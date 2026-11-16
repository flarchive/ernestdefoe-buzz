<?php

namespace Ernestdefoe\Buzz\Api;

use Ernestdefoe\Buzz\Broadcaster;
use Ernestdefoe\Buzz\Recorder;
use Ernestdefoe\Buzz\Settings;
use Ernestdefoe\Buzz\Types;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * What the browser needs to run Buzz, on the forum payload.
 *
 * 🚨 No queries: settings are already loaded, the cursor comes from the
 * cache (null when the cache was cleared: the first poll then asks for it),
 * and the type check reads the actor's already-loaded permissions.
 * Display options only, never a secret.
 */
class ForumConfig
{
    public function __construct(
        private Settings $settings,
        private Types $types,
        private Cache $cache,
        private Broadcaster $broadcaster,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function for(User $actor): ?array
    {
        if (! $actor->hasPermission('viewForum') || ! $this->types->visibleTo($actor)) {
            return null;
        }

        $s = $this->settings;
        $cursor = $this->cache->get(Recorder::LATEST);

        return [
            'style' => $s->get('style', 'toast') === 'ticker' ? 'ticker' : 'toast',
            'tickerMode' => $s->get('ticker_mode', 'slide') === 'marquee' ? 'marquee' : 'slide',
            'side' => $s->get('toast_side', 'left') === 'right' ? 'right' : 'left',
            'offsetX' => $s->int('offset_x', 16, 0, 400),
            'offsetY' => $s->int('offset_y', 16, 0, 400),
            'avatar' => $s->bool('show_avatar', true),
            'username' => $s->bool('show_username', true),
            'profileLink' => $s->bool('profile_link', true),
            'interval' => $s->interval(),
            'seconds' => $s->int('display_seconds', 6, 3, 30),
            // Cards on screen at once (desktop; phones always one), as Invision's "Max concurrent toasts".
            'maxToasts' => $s->int('max_toasts', 2, 1, 5),
            'pages' => $s->get('pages', 'all') === 'home' ? 'home' : 'all',
            'hideComposing' => $s->bool('hide_composing', true),
            'recent' => $s->int('recent_on_load', 0, 0, 3),
            'optIn' => $s->optIn(),
            'realtime' => $this->broadcaster->available(),
            'cursor' => $cursor === null ? null : (int) $cursor,
        ];
    }
}
