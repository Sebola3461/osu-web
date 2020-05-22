<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Libraries;

use App\Exceptions\InvariantException;
use App\Models\BeatmapDiscussion;
use App\Models\BeatmapDiscussionPost;
use App\Models\Beatmapset;
use App\Models\BeatmapsetEvent;
use App\Models\User;
use DB;

class BeatmapsetDiscussionReview
{
    const BLOCK_TEXT_LENGTH_LIMIT = 750;

    public static function create(Beatmapset $beatmapset, array $document, User $user)
    {
        if (!$document || !is_array($document) || empty($document)) {
            throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_document'));
        }

        $problemPost = null;
        $output = [];
        try {
            DB::beginTransaction();

            // create the issues for the embeds first
            $childIds = [];
            $blockCount = 0;
            foreach ($document as $block) {
                if (!isset($block['type'])) {
                    throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_block_type'));
                }

                $message = get_string($block['text'] ?? null);
                if ($message === null) {
                    throw new InvariantException(trans('beatmap_discussions.review.validation.missing_text'));
                }

                switch ($block['type']) {
                    case 'embed':
                        $embedPost = self::createPost(
                            $beatmapset->getKey(),
                            $block['discussion_type'],
                            $message,
                            $user->getKey(),
                            $block['beatmap_id'] ?? null,
                            $block['timestamp'] ?? null
                        );
                        $output[] = [
                            'type' => 'embed',
                            'discussion_id' => $embedPost->getKey(),
                        ];
                        $childIds[] = $embedPost->getKey();
                        if ($block['discussion_type'] == 'problem' && !$problemPost) {
                            $problemPost = $embedPost;
                        }
                        break;

                    case 'paragraph':
                        if (mb_strlen($block['text']) > static::BLOCK_TEXT_LENGTH_LIMIT) {
                            throw new InvariantException(trans('beatmap_discussions.review.validation.block_too_large', ['limit' => static::BLOCK_TEXT_LENGTH_LIMIT]));
                        }
                        $output[] = [
                            'type' => 'paragraph',
                            'text' => $block['text'],
                        ];
                        break;

                    default:
                        // invalid block type
                        throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_block_type'));
                }
                $blockCount++;
            }

            $minIssues = config('osu.beatmapset.discussion_review_min_issues');
            if (empty($childIds) || count($childIds) < $minIssues) {
                throw new InvariantException(trans_choice('beatmap_discussions.review.validation.minimum_issues', $minIssues));
            }

            $maxBlocks = config('osu.beatmapset.discussion_review_max_blocks');
            if ($blockCount > $maxBlocks) {
                throw new InvariantException(trans_choice('beatmap_discussions.review.validation.too_many_blocks', $maxBlocks));
            }

            $review = self::createPost(
                $beatmapset->getKey(),
                'review',
                json_encode($output),
                $user->getKey()
            );

            // associate children with parent
            BeatmapDiscussion::whereIn('id', $childIds)
                ->update(['parent_id' => $review->getKey()]);

            // handle disqualifications and the resetting of nominations
            if ($problemPost) {
                $resetNominations = $beatmapset->isPending() &&
                    $beatmapset->hasNominations() &&
                    priv_check_user($user, 'BeatmapsetResetNominations', $beatmapset)->can();

                if ($resetNominations) {
                    BeatmapsetEvent::log(BeatmapsetEvent::NOMINATION_RESET, $user, $problemPost)->saveOrExplode();
                    $beatmapset->refreshCache();
                } else {
                    if (priv_check_user($user, 'BeatmapsetDisqualify', $beatmapset)->can()) {
                        $beatmapset->disqualify($user, $problemPost);
                    }
                }
            }

            DB::commit();

            return $review;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // TODO: combine with create()?
    public static function update(BeatmapDiscussion $discussion, array $document, User $user)
    {
        if (!$document || !is_array($document) || empty($document)) {
            throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_document'));
        }

        $beatmapset = Beatmapset::findOrFail($discussion->beatmapset_id); // handle deleted beatmapsets
        $post = $discussion->startingPost;

        $output = [];
        try {
            DB::beginTransaction();

            // iterate over the children to determine which embeds are new and which have been unlinked
            $childIds = [];
            $blockCount = 0;

            foreach ($document as $block) {
                if (!isset($block['type'])) {
                    throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_block_type'));
                }

                $message = get_string($block['text'] ?? null);
                if ($message === null) {
                    // skip empty message check if this is an existing embed
                    if ($block['type'] !== 'embed' || !isset($block['discussion_id'])) {
                        throw new InvariantException(trans('beatmap_discussions.review.validation.missing_text'));
                    }
                }

                switch ($block['type']) {
                    case 'embed':
                        // if there's a discussion_id, this is an existing embed
                        if (isset($block['discussion_id'])) {
                            $childId = $block['discussion_id'];
                        } else {
                            // otherwise, create new discussion
                            $childId = self::createPost(
                                $beatmapset->getKey(),
                                $block['discussion_type'],
                                $message,
                                $user->getKey(),
                                $block['beatmap_id'] ?? null,
                                $block['timestamp'] ?? null
                            )->getKey();
                        }

                        $output[] = [
                            'type' => 'embed',
                            'discussion_id' => $childId,
                        ];
                        $childIds[] = $childId;
                        break;

                    case 'paragraph':
                        if (mb_strlen($block['text']) > static::BLOCK_TEXT_LENGTH_LIMIT) {
                            throw new InvariantException(trans('beatmap_discussions.review.validation.block_too_large', ['limit' => static::BLOCK_TEXT_LENGTH_LIMIT]));
                        }
                        $output[] = [
                            'type' => 'paragraph',
                            'text' => $block['text'],
                        ];
                        break;

                    default:
                        // invalid block type
                        throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_block_type'));
                }
                $blockCount++;
            }

            $minIssues = config('osu.beatmapset.discussion_review_min_issues');
            if (empty($childIds) || count($childIds) < $minIssues) {
                throw new InvariantException(trans_choice('beatmap_discussions.review.validation.minimum_issues', $minIssues));
            }

            $maxBlocks = config('osu.beatmapset.discussion_review_max_blocks');
            if ($blockCount > $maxBlocks) {
                throw new InvariantException(trans_choice('beatmap_discussions.review.validation.too_many_blocks', $maxBlocks));
            }

            // ensure all referenced embeds belong to this discussion
            $externalEmbeds = BeatmapDiscussion::whereIn('id', $childIds)->where('parent_id', '<>', $discussion->getKey())->count();
            if ($externalEmbeds > 0) {
                throw new InvariantException(trans('beatmap_discussions.review.validation.external_references'));
            }

            // update the review post
            $post['message'] = json_encode($output);
            $post['last_editor_id'] = $user->getKey();
            $post->saveOrExplode();

            // unlink any embeds that were removed from the review
            BeatmapDiscussion::where('parent_id', $discussion->getKey())
                ->whereNotIn('id', $childIds)
                ->update(['parent_id' => null]);

            // associate children with parent
            BeatmapDiscussion::whereIn('id', $childIds)
                ->update(['parent_id' => $discussion->getKey()]);

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private static function createPost($beatmapsetId, $discussionType, $message, $userId, $beatmapId = null, $timestamp = null)
    {
        $newDiscussion = new BeatmapDiscussion([
            'beatmapset_id' => $beatmapsetId,
            'user_id' => $userId,
            'resolved' => false,
            'message_type' => $discussionType,
            'timestamp' => $timestamp,
            'beatmap_id' => $beatmapId,
        ]);
        $newDiscussion->saveOrExplode();

        $postParams = [
            'user_id' => $userId,
            'message' => $message,
        ];
        $newPost = new BeatmapDiscussionPost($postParams);
        $newPost->beatmapDiscussion()->associate($newDiscussion);
        $newPost->saveOrExplode();

        return $newDiscussion;
    }
}
