<?php

/*
 * Buzz — a live activity feed for Flarum.
 *
 * The shape of it: listeners write one small row per happening; each open
 * page asks "anything after row N?" every so often (or the moment realtime
 * says so), and the answer holds only the rows that viewer may see, checked
 * on the server by one query. The browser shows them one at a time.
 */

use Ernestdefoe\Buzz\Api;
use Ernestdefoe\Buzz\BuzzServiceProvider;
use Ernestdefoe\Buzz\Console\PruneCommand;
use Ernestdefoe\Buzz\Extend\Buzz;
use Ernestdefoe\Buzz\Integration;
use Ernestdefoe\Buzz\Listener\CoreActivity;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The display is its own chunk, fetched when the first event arrives.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->content(Api\AdminPayload::class),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())->register(BuzzServiceProvider::class),

    (new Extend\Routes('api'))
        ->get('/buzz', 'buzz.poll', Api\PollController::class),

    (new Extend\ThrottleApi())->set('buzz', Api\Throttle::class),

    (new Extend\Event())->subscribe(CoreActivity::class),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function (Event $event): void {
            $event->hourly();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-buzz.style', 'toast')
        ->default('ernestdefoe-buzz.ticker_mode', 'slide')
        ->default('ernestdefoe-buzz.toast_side', 'left')
        ->default('ernestdefoe-buzz.offset_x', 16)
        ->default('ernestdefoe-buzz.offset_y', 16)
        ->default('ernestdefoe-buzz.show_avatar', true)
        ->default('ernestdefoe-buzz.show_username', true)
        ->default('ernestdefoe-buzz.profile_link', true)
        ->default('ernestdefoe-buzz.interval', 30)
        ->default('ernestdefoe-buzz.display_seconds', 6)
        ->default('ernestdefoe-buzz.pages', 'all')
        ->default('ernestdefoe-buzz.hide_composing', true)
        ->default('ernestdefoe-buzz.recent_on_load', 0)
        ->default('ernestdefoe-buzz.keep_hours', 24)
        ->default('ernestdefoe-buzz.keep_max', 500)
        ->default('ernestdefoe-buzz.opt_in', false)
        ->default('ernestdefoe-buzz.purchase_show_buyer', false),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('buzz')
                ->get(fn ($forum, Context $context) => resolve(Api\ForumConfig::class)->for($context->getActor())),
        ]),

    (new Extend\Model(User::class))
        ->cast('buzz_share', 'boolean'),

    // "Mute Buzz": this member sees no feed. A preference, so it costs nothing to read.
    (new Extend\User())
        ->registerPreference('buzzMute', 'boolval', false),

    // The member's own "show my activity in Buzz", on their own user only.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('buzzShare')
                ->nullable()
                ->property('buzz_share')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->writable(fn (User $user, Context $context) => $context->getActor()->id === $user->id),
        ]),

    // The core types. Integrations below add theirs only while that extension is enabled.
    (new Buzz())
        ->type('discussion', 'fas fa-comments', 'ernestdefoe-buzz.forum.event.discussion', ['name' => 'ernestdefoe-buzz.admin.types.discussion'])
        ->type('reply', 'fas fa-reply', 'ernestdefoe-buzz.forum.event.reply', ['name' => 'ernestdefoe-buzz.admin.types.reply'])
        ->type('member', 'fas fa-user-plus', 'ernestdefoe-buzz.forum.event.member', ['name' => 'ernestdefoe-buzz.admin.types.member']),

    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Buzz())->type('badge', 'fas fa-award', 'ernestdefoe-buzz.forum.event.badge', [
                'name' => 'ernestdefoe-buzz.admin.types.badge', 'permission' => 'badges.viewUserBadges', 'extension' => 'fof-badges',
            ]),
            (new Extend\Event())->listen(\FoF\Badges\Event\BadgeAwarded::class, Integration\Badges::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-ladder', fn () => [
            (new Buzz())->type('rank', 'fas fa-ranking-star', 'ernestdefoe-buzz.forum.event.rank', [
                'name' => 'ernestdefoe-buzz.admin.types.rank', 'extension' => 'ernestdefoe-ladder',
            ]),
            (new Extend\Notification())->beforeSending(Integration\Ladder::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-cadence', fn () => [
            (new Buzz())->type('streak', 'fas fa-fire', 'ernestdefoe-buzz.forum.event.streak', [
                'name' => 'ernestdefoe-buzz.admin.types.streak', 'permission' => 'cadence.viewStreaks', 'extension' => 'ernestdefoe-cadence',
            ]),
            (new Extend\Notification())->beforeSending(Integration\Cadence::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-onramp', fn () => [
            (new Buzz())->type('quest', 'fas fa-flag-checkered', 'ernestdefoe-buzz.forum.event.quest', [
                'name' => 'ernestdefoe-buzz.admin.types.quest', 'extension' => 'ernestdefoe-onramp',
            ]),
            (new Extend\Event())->listen(\ErnestDefoe\Onramp\Event\Graduated::class, Integration\Onramp::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-brainstorm', fn () => [
            (new Buzz())->type('quiz', 'fas fa-graduation-cap', 'ernestdefoe-buzz.forum.event.quiz', [
                'name' => 'ernestdefoe-buzz.admin.types.quiz', 'permission' => 'brainstorm.view', 'extension' => 'ernestdefoe-brainstorm',
                'scope' => [Integration\Brainstorm::class, 'scope'],
            ]),
            (new Extend\Event())->listen(\ErnestDefoe\Brainstorm\Event\AttemptFinished::class, Integration\Brainstorm::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-curio', fn () => [
            (new Buzz())->type('collection', 'fas fa-box-open', 'ernestdefoe-buzz.forum.event.collection', [
                'name' => 'ernestdefoe-buzz.admin.types.collection', 'extension' => 'ernestdefoe-curio',
                'scope' => [Integration\Curio::class, 'scope'],
            ]),
            (new Extend\ApiResource(\Ernestdefoe\Curio\Api\ItemResource::class))
                ->endpoint(Endpoint\Create::class, fn (Endpoint\Create $endpoint) => $endpoint->after(new Integration\Curio())),
        ])
        ->whenExtensionEnabled('ernestdefoe-client-area', fn () => [
            (new Buzz())->type('purchase', 'fas fa-bag-shopping', 'ernestdefoe-buzz.forum.event.purchase', [
                'name' => 'ernestdefoe-buzz.admin.types.purchase', 'extension' => 'ernestdefoe-client-area',
            ]),
            (new Extend\Event())->listen(\ErnestDefoe\ClientArea\Event\ServicePaid::class, Integration\ClientArea::class),
        ]),
];
