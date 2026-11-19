<?php

namespace Ernestdefoe\Buzz\Integration;

use Ernestdefoe\Buzz\Recorder;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * ernestdefoe/cadence: a member's streak reached a milestone.
 *
 * Cadence announces milestones with its own alert, so this reads that alert
 * as it goes out (see Ladder for the mechanism). When one post earns a
 * milestone and a bigger moment at once, Cadence sends only the bigger one,
 * and so does Buzz.
 */
class Cadence
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
        if ($blueprint instanceof \ErnestDefoe\Cadence\Notification\StreakMoment && $blueprint->event === 'milestone') {
            $this->recorder->record('streak', $blueprint->user, ['days' => $blueprint->value]);
        }

        return $recipients;
    }
}
