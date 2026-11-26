<?php

namespace Ernestdefoe\Buzz;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;

/**
 * Writes one broadcast. The cost on the request that caused it is a few
 * checks (the member's own answer is one primary-key read) and ONE insert;
 * the cursor bump, the realtime signal and, now and then, a queued prune wait
 * until the surrounding transaction commits, so no browser is ever told
 * about a row it cannot read yet.
 *
 * Other extensions call this too:
 *
 *     resolve(\Ernestdefoe\Buzz\Recorder::class)->record('acme.thing', $user, ['title' => $title], [
 *         'route' => ['name' => 'acme.thing', 'params' => ['id' => $thing->id]],
 *         'subject_id' => $thing->id,
 *     ]);
 */
class Recorder
{
    public const LATEST = 'ernestdefoe-buzz.latest';

    /** One insert in this many also queues a prune, for forums without a scheduler. */
    public const PRUNE_ODDS = 50;

    public function __construct(
        private Types $types,
        private Settings $settings,
        private Cache $cache,
        private Broadcaster $broadcaster,
        private Queue $queue,
    ) {
    }

    /**
     * @param array<string, scalar|null> $params shown in the text: everyone who may see the row may see these
     * @param array{route?: array{name: string, params: array<string, scalar>}, url?: string, discussion_id?: int|null, post_id?: int|null, subject_id?: int|null, members_only?: bool, tag_ids?: int[], anonymous?: bool} $options
     */
    public function record(string $type, ?User $user, array $params = [], array $options = []): ?int
    {
        if (! $this->types->get($type) || ! $this->types->isOn($this->types->get($type))) {
            return null;
        }

        if ($user && ! $this->mayFeature($user)) {
            return null;
        }

        $excludedTags = $this->settings->ids('exclude_tags');

        if ($excludedTags && array_intersect($excludedTags, array_map('intval', $options['tag_ids'] ?? []))) {
            return null;
        }

        $data = $this->data($params, $options);

        $event = new BuzzEvent();
        $event->forceFill([
            'type' => $type,
            'user_id' => $user?->id,
            'discussion_id' => $options['discussion_id'] ?? null,
            'post_id' => $options['post_id'] ?? null,
            'subject_id' => $options['subject_id'] ?? null,
            'members_only' => ! empty($options['members_only']),
            'data' => $data,
            'created_at' => Carbon::now('UTC'),
        ])->save();
        $id = $event->id;

        $after = function () use ($id) {
            $this->cache->forever(self::LATEST, max($id, (int) $this->cache->get(self::LATEST, 0)));
            $this->broadcaster->signal($id);

            if (random_int(1, self::PRUNE_ODDS) === 1) {
                $this->queue->push(new PruneJob());
            }
        };

        $event->getConnection()->afterCommit($after);

        return $id;
    }

    /**
     * What the row stores to draw itself: the text's params, and where it links.
     *
     * @param array<string, scalar|null> $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function data(array $params, array $options): array
    {
        $data = ['params' => $this->clean($params)];

        if (isset($options['route']['name'])) {
            $data['route'] = ['name' => (string) $options['route']['name'], 'params' => $this->clean($options['route']['params'] ?? [])];
        }

        // A path on this forum only: never another host, never `//host`.
        if (isset($options['url']) && preg_match('#^/(?!/)[^\s\\\\]*$#', (string) $options['url'])) {
            $data['url'] = (string) $options['url'];
        }

        if (! empty($options['anonymous'])) {
            $data['anonymous'] = true;
        }

        return $data;
    }

    /**
     * Whether a member's activity may be broadcast at all: a confirmed,
     * unsuspended account that has not opted out, outside the excluded groups.
     */
    public function mayFeature(User $user): bool
    {
        if (! $user->exists || ! $user->is_email_confirmed) {
            return false;
        }

        $suspendedUntil = $user->getAttribute('suspended_until');

        if ($suspendedUntil && Carbon::parse($suspendedUntil)->isFuture()) {
            return false;
        }

        // No row = never chose: shown unless the forum asks first.
        $share = BuzzUserSetting::shareOf($user);

        if ($share === false || ($this->settings->optIn() && $share !== true)) {
            return false;
        }

        $excluded = $this->settings->ids('exclude_groups');

        return ! $excluded || ! array_intersect($excluded, $user->groups->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Scalars only, control characters out (the browser splits the text on
     * one), long strings cut.
     *
     * @param array<mixed> $params
     * @return array<string, scalar|null>
     */
    private function clean(array $params): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $value = mb_strimwidth((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), 0, 120, '…');
            } elseif (! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
                continue;
            }

            $out[(string) $key] = $value;
        }

        return $out;
    }
}
