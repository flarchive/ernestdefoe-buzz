<?php

namespace Ernestdefoe\Buzz;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/** Forgets broadcasts older than the keep window, and all but the newest N. */
class Pruner
{
    public function __construct(private ConnectionInterface $db, private Settings $settings)
    {
    }

    public function prune(): int
    {
        $deleted = $this->db->table('buzz_events')
            ->where('created_at', '<', Carbon::now('UTC')->subHours($this->settings->keepHours()))
            ->delete();

        $floor = $this->db->table('buzz_events')
            ->orderByDesc('id')
            ->offset($this->settings->keepMax())
            ->limit(1)
            ->value('id');

        if ($floor !== null) {
            $deleted += $this->db->table('buzz_events')->where('id', '<=', $floor)->delete();
        }

        return $deleted;
    }
}
