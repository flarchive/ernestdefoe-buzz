<?php

namespace Ernestdefoe\Buzz\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

/** The member's own "show my activity in Buzz": seen and changed on their own user only. */
class UserFields
{
    /** @return array<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('buzzShare')
                ->nullable()
                ->property('buzz_share')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->writable(fn (User $user, Context $context) => $context->getActor()->id === $user->id),
        ];
    }
}
