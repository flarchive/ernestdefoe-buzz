<?php

namespace Ernestdefoe\Buzz;

use Flarum\User\Guest;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * With flarum/realtime, says "something new" on its public channel.
 *
 * 🚨 Only for rows a GUEST could see, and the signal carries nothing but the
 * row's id: every open page then polls, and the poll applies that viewer's
 * own visibility. Restricted rows are never announced on the public channel;
 * members get them on their next poll.
 */
class Broadcaster
{
    public function __construct(private Container $container, private LoggerInterface $log)
    {
    }

    public function available(): bool
    {
        return class_exists(\Pusher\Pusher::class) && $this->container->bound(\Pusher\Pusher::class);
    }

    public function signal(int $id): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            if (! $this->container->make(Feed::class)->visibleIds(new Guest(), [$id])) {
                return;
            }

            $this->container->make(\Pusher\Pusher::class)->trigger('public', 'buzz.new', ['id' => $id]);
        } catch (Throwable $e) {
            // The row is saved; polling delivers it. A daemon that is down must not fail a post.
            $this->log->warning('[buzz] realtime signal failed: '.$e->getMessage());
        }
    }
}
