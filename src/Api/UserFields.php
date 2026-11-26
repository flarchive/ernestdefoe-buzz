<?php

namespace Ernestdefoe\Buzz\Api;

use Ernestdefoe\Buzz\BuzzUserSetting;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * The member's own "show my activity in Buzz": seen and changed on their own
 * user only, stored in buzz_user_settings. Null clears the answer, so the
 * forum default applies again.
 *
 * Serialized only when asked for (`fields[users]=buzzShare`, which the
 * settings page sends) or written, so the session user on every other
 * response costs no extra query.
 */
class UserFields
{
    /** @return array<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('buzzShare')
                ->nullable()
                // fieldRequested() is json-api-server's Context method (Flarum's Context extends it): sparse fieldsets.
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id
                    && ($context->fieldRequested('users', 'buzzShare', false) || $context->endpoint instanceof Endpoint\Update))
                ->writable(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user) => BuzzUserSetting::shareOf($user))
                ->save(function (User $user, ?bool $value) {
                    if ($value === null) {
                        BuzzUserSetting::query()->whereKey($user->id)->delete();
                        $user->setRelation('buzzSettings', null);

                        return;
                    }

                    // One upsert: two first saves at once can't collide on the key.
                    BuzzUserSetting::query()->upsert([['user_id' => $user->id, 'buzz_share' => $value]], ['user_id'], ['buzz_share']);
                    $user->setRelation('buzzSettings', (new BuzzUserSetting())->forceFill(['user_id' => $user->id, 'buzz_share' => $value]));
                }),
        ];
    }
}
