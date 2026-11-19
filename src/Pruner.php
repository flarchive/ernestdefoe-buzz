<?php

namespace Ernestdefoe\Buzz;

use Carbon\Carbon;

/** Forgets broadcasts older than the keep window, and all but the newest N. */
class Pruner
{
    public function __construct(private Settings $settings)
    {
    }

    public function prune(): int
    {
        $deleted = BuzzEvent::query()
            ->where('created_at', '<', Carbon::now('UTC')->subHours($this->settings->keepHours()))
            ->delete();

        $floor = BuzzEvent::query()
            ->orderByDesc('id')
            ->offset($this->settings->keepMax())
            ->limit(1)
            ->value('id');

        if ($floor !== null) {
            $deleted += BuzzEvent::query()->where('id', '<=', $floor)->delete();
        }

        return $deleted;
    }
}
