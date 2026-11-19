<?php

namespace Ernestdefoe\Buzz\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The poll endpoint is asked every 10+ seconds by each open tab, plus once
 * per realtime signal. More than 30 asks a minute from one member or one
 * address is not a browser tab, and gets a 429.
 */
class Throttle
{
    public const PER_MINUTE = 30;

    public function __construct(private Cache $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if ($request->getAttribute('routeName') !== 'buzz.poll') {
            return null;
        }

        $actor = RequestUtil::getActor($request);
        $who = $actor->isGuest() ? 'ip.'.sha1((string) $request->getAttribute('ipAddress')) : 'u.'.$actor->id;
        $key = 'ernestdefoe-buzz.rate.'.$who.'.'.intdiv(time(), 60);

        $this->cache->add($key, 0, 120);

        return $this->cache->increment($key) > self::PER_MINUTE;
    }
}
