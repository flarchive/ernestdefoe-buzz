<?php

namespace Ernestdefoe\Buzz;

use Flarum\Foundation\AbstractServiceProvider;

class BuzzServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Types::class);
    }
}
