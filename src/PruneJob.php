<?php

namespace Ernestdefoe\Buzz;

use Flarum\Queue\AbstractJob;

/** Forgets expired broadcasts off the request; the hourly buzz:prune does the same on a schedule. */
class PruneJob extends AbstractJob
{
    public function handle(Pruner $pruner): void
    {
        $pruner->prune();
    }
}
