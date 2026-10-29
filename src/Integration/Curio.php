<?php

namespace Ernestdefoe\Buzz\Integration;

use Ernestdefoe\Buzz\Feed;
use Ernestdefoe\Buzz\Recorder;
use Flarum\Api\Context;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ernestdefoe/curio: a member added something to a collection.
 *
 * Only items added by hand through the API (an import of 2,000 records is
 * not 2,000 pieces of news), only to collections that are not private, and
 * never wishlist items. Every poll asks Curio's own item visibility, so a
 * collection made private later, a deleted item or a hidden owner all drop
 * out of the feed.
 */
class Curio
{
    public function __invoke(Context $context, mixed $item): mixed
    {
        if (! $item instanceof \Ernestdefoe\Curio\Item || ! $item->wasRecentlyCreated || $item->status === 'wishlist') {
            return $item;
        }

        $collection = $item->collection;

        if (! $collection || ! in_array($collection->visibility, ['public', 'members'], true)) {
            return $item;
        }

        resolve(Recorder::class)->record('collection', $context->getActor(), ['item' => $item->title, 'collection' => $collection->name], [
            'route' => ['name' => 'curio.collection', 'params' => ['id' => $collection->id.($collection->slug ? '-'.$collection->slug : '')]],
            'subject_id' => $item->id,
            'members_only' => $collection->visibility === 'members',
        ]);

        return $item;
    }

    /** @param Builder<\Ernestdefoe\Buzz\BuzzEvent> $query */
    public static function scope(Builder $query, User $actor): void
    {
        Feed::whereExists($query, \Ernestdefoe\Curio\Item::query()->whereVisibleTo($actor), 'curio_items.id');
    }
}
