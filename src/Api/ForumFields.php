<?php

namespace Ernestdefoe\Buzz\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The forum's `buzz` attribute: how and when this viewer's page shows events, or null for none. */
class ForumFields
{
    public function __construct(private ForumConfig $config)
    {
    }

    /** @return array<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('buzz')
                ->get(fn ($forum, Context $context) => $this->config->for($context->getActor())),
        ];
    }
}
