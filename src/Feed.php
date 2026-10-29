<?php

namespace Ernestdefoe\Buzz;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\SlugManager;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads broadcasts for one viewer.
 *
 * 🚨 ONE query per poll, whatever the number of rows or types: the author's
 * account (name, avatar, suspension, opt-out) comes in by a join, the post a
 * row points at is checked with core's own post visibility scope as a
 * correlated EXISTS (tags, approval, hiding, private discussions and every
 * other extension's rules, without Buzz knowing them), and each type's own
 * rule is another where-clause. Walking the primary key down from the top
 * makes it cost the same with ten rows or ten thousand.
 */
class Feed
{
    /** The character the text carries where the member's name goes. */
    public const USER_MARK = "\u{1}";

    public const MAX = 20;

    public function __construct(
        private Types $types,
        private Settings $settings,
        private Cache $cache,
        private ExtensionManager $extensions,
        private SlugManager $slugs,
        private TranslatorInterface $translator,
    ) {
    }

    /** The newest id, kept in the cache by the recorder; one query only when the cache was cleared. */
    public function latest(): int
    {
        $latest = $this->cache->get(Recorder::LATEST);

        if ($latest === null) {
            $latest = (int) BuzzEvent::query()->max('id');
            $this->cache->forever(Recorder::LATEST, $latest);
        }

        return (int) $latest;
    }

    /**
     * Rows after a cursor, oldest first, as the browser draws them.
     *
     * @return list<array<string, mixed>>
     */
    public function since(User $actor, int $after, int $limit = self::MAX, ?Carbon $notBefore = null): array
    {
        $query = $this->query($actor);

        if (! $query) {
            return [];
        }

        $query->where('buzz_events.id', '>', $after);

        if ($notBefore) {
            $query->where('buzz_events.created_at', '>=', $notBefore);
        }

        return $this->present($query->orderByDesc('buzz_events.id')->limit($limit)->get()->reverse()->values());
    }

    /**
     * Guests all see the same thing, so they share one cached copy of the
     * newest rows: a thousand guests polling cost one query per new row (or
     * per minute), not a thousand.
     *
     * @return list<array<string, mixed>>
     */
    public function guestStream(int $latest): array
    {
        $key = 'ernestdefoe-buzz.guest.'.$this->translator->getLocale().'.'.$latest;

        return $this->cache->remember($key, 60, fn () => $this->since(new Guest(), 0, self::MAX, Carbon::now('UTC')->subHours($this->settings->keepHours())));
    }

    /**
     * Which of these rows the actor may see. Used to decide whether a row may
     * be announced on realtime's public channel.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function visibleIds(User $actor, array $ids): array
    {
        $query = $this->query($actor);

        return $query ? $query->whereIn('buzz_events.id', $ids)->pluck('buzz_events.id')->map(fn ($id) => (int) $id)->all() : [];
    }

    /** @return Builder<BuzzEvent>|null */
    private function query(User $actor): ?Builder
    {
        $types = $this->types->visibleTo($actor);

        if (! $types || ! $actor->hasPermission('viewForum')) {
            return null;
        }

        $columns = ['buzz_events.*', 'users.username as buzz_username', 'users.avatar_url as buzz_avatar'];
        $nicknames = $this->extensions->isEnabled('flarum-nicknames');

        if ($nicknames) {
            $columns[] = 'users.nickname as buzz_nickname';
        }

        $query = BuzzEvent::query()
            ->select($columns)
            ->leftJoin('users', 'users.id', '=', 'buzz_events.user_id');

        // The author: a confirmed, unsuspended member who has not opted out.
        $query->where(function (Builder $q) {
            $q->whereNull('buzz_events.user_id')->orWhere(function (Builder $q) {
                $q->where('users.is_email_confirmed', true);

                if ($this->extensions->isEnabled('flarum-suspend')) {
                    $q->where(fn (Builder $q) => $q->whereNull('users.suspended_until')->orWhere('users.suspended_until', '<', Carbon::now('UTC')));
                }

                if ($this->settings->optIn()) {
                    $q->where('users.buzz_share', true);
                } else {
                    $q->where(fn (Builder $q) => $q->whereNull('users.buzz_share')->orWhere('users.buzz_share', true));
                }
            });
        });

        if ($actor->isGuest()) {
            $query->where('buzz_events.members_only', false);
        }

        // The post a row points at must be one this viewer can open.
        $query->where(function (Builder $q) use ($actor) {
            $q->whereNull('buzz_events.post_id');
            self::whereExists($q, Post::query()->whereVisibleTo($actor), 'posts.id', 'buzz_events.post_id', 'or');
        });

        $query->where(function (Builder $q) use ($types, $actor) {
            $plain = [];

            foreach ($types as $type) {
                if ($type->scope) {
                    $q->orWhere(function (Builder $q) use ($type, $actor) {
                        $q->where('buzz_events.type', $type->key);
                        ($type->scope)($q, $actor);
                    });
                } else {
                    $plain[] = $type->key;
                }
            }

            if ($plain) {
                $q->orWhereIn('buzz_events.type', $plain);
            }
        });

        return $query;
    }

    /**
     * Add "EXISTS (a row of $subject this viewer may see, matching this row)".
     * For type scopes: pass the subject's own visibility-scoped query.
     *
     * @param Builder<BuzzEvent> $query
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $subject
     */
    public static function whereExists(Builder $query, Builder $subject, string $subjectColumn, string $buzzColumn = 'buzz_events.subject_id', string $boolean = 'and'): void
    {
        $query->getQuery()->addWhereExistsQuery(
            $subject->whereColumn($subjectColumn, $buzzColumn)->select($subjectColumn)->toBase(),
            $boolean
        );
    }

    /**
     * @param Collection<int, BuzzEvent> $rows
     * @return list<array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        $someone = $this->translator->trans('ernestdefoe-buzz.forum.someone');
        $out = [];

        foreach ($rows as $row) {
            $type = $this->types->get($row->type);

            if (! $type) {
                continue;
            }

            $data = $row->data ?? [];
            $anonymous = ! empty($data['anonymous']) || ! $row->user_id;
            $params = (array) ($data['params'] ?? []);
            $params['user'] = $anonymous ? $someone : self::USER_MARK;

            $user = null;

            if (! $anonymous) {
                $model = new User();
                $model->forceFill(['id' => $row->user_id, 'username' => $row->getAttribute('buzz_username'), 'avatar_url' => $row->getAttribute('buzz_avatar')]);

                if ($row->getAttribute('buzz_nickname') !== null) {
                    $model->setAttribute('nickname', $row->getAttribute('buzz_nickname'));
                }

                $user = [
                    'name' => $model->display_name,
                    'avatar' => $model->avatar_url,
                    'slug' => $this->slugs->forResource(User::class)->toSlug($model),
                ];
            }

            $out[] = [
                'id' => $row->id,
                'type' => $row->type,
                'icon' => $type->icon,
                'text' => $this->translator->trans($type->label, $params),
                'user' => $user,
                'route' => $data['route'] ?? null,
                'url' => $data['url'] ?? null,
                'at' => $row->created_at->toIso8601String(),
            ];
        }

        return $out;
    }
}
