<?php

namespace Ernestdefoe\Buzz\Integration;

use Ernestdefoe\Buzz\Recorder;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * ernestdefoe/ladder: a member climbed a rung.
 *
 * Ladder tells the member with its "promoted" alert and nothing else, so
 * this rides on that alert: Flarum hands every alert to beforeSending
 * callbacks before delivering it. Ladder's own quiet re-rank of the whole
 * forum sends no alerts, so turning Ladder on never floods the feed.
 */
class Ladder
{
    public function __construct(private Recorder $recorder)
    {
    }

    /**
     * @param User[] $recipients
     * @return User[]
     */
    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        if ($blueprint instanceof \Ernestdefoe\Ladder\Notification\PromotedBlueprint) {
            $group = $blueprint->group;

            // A hidden group is a rank nobody else is meant to see.
            if (! $group->is_hidden) {
                foreach ($recipients as $user) {
                    $this->recorder->record('rank', $user, ['rank' => $group->name_singular], [
                        'subject_id' => $group->id,
                    ]);
                }
            }
        }

        return $recipients;
    }
}
