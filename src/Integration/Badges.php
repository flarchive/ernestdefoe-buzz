<?php

namespace Ernestdefoe\Buzz\Integration;

use Ernestdefoe\Buzz\Recorder;
use FoF\Badges\Event\BadgeAwarded;

/** fof/badges: a member earned a badge. Hidden or switched-off badges are never broadcast. */
class Badges
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function __invoke(BadgeAwarded $event): void
    {
        $badge = $event->badge;

        if (! $badge->is_visible || ($badge->is_active ?? true) === false) {
            return;
        }

        $this->recorder->record('badge', $event->user, ['badge' => $badge->name], [
            'subject_id' => $badge->id,
        ]);
    }
}
