<?php

namespace Ernestdefoe\Buzz\Console;

use Ernestdefoe\Buzz\Pruner;
use Flarum\Console\AbstractCommand;

class PruneCommand extends AbstractCommand
{
    public function __construct(private Pruner $pruner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('buzz:prune')->setDescription('Forget Buzz events past the keep window.');
    }

    protected function fire(): int
    {
        $this->info('Forgot '.$this->pruner->prune().' Buzz events.');

        return 0;
    }
}
