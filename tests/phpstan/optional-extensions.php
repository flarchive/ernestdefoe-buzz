<?php

// The parts of Ernest's optional extensions that Buzz reads, for PHPStan only.

namespace Ernestdefoe\Ladder\Notification {
    use Flarum\Group\Group;

    class PromotedBlueprint implements \Flarum\Notification\Blueprint\BlueprintInterface
    {
        public Group $group;
    }
}

namespace ErnestDefoe\Cadence\Notification {
    use Flarum\User\User;

    class StreakMoment implements \Flarum\Notification\Blueprint\BlueprintInterface
    {
        public User $user;
        public string $event;
        public int $value;
        public string $on;
    }
}

namespace ErnestDefoe\Onramp\Event {
    class Graduated
    {
        public \Flarum\User\User $user;
    }
}

namespace ErnestDefoe\Brainstorm {
    /**
     * @property int $id
     * @property string $title
     * @property string $slug
     * @property bool $leaderboard
     * @method static \Illuminate\Database\Eloquent\Builder<static> query()
     */
    class Quiz extends \Flarum\Database\AbstractModel
    {
        public function isPublished(): bool
        {
            return true;
        }

        /** @param \Illuminate\Database\Eloquent\Builder<Quiz> $query */
        public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, \Flarum\User\User $actor): void
        {
        }
    }

    /** @property Quiz|null $quiz */
    class Attempt extends \Flarum\Database\AbstractModel
    {
    }
}

namespace ErnestDefoe\Brainstorm\Event {
    class AttemptFinished
    {
        public \ErnestDefoe\Brainstorm\Attempt $attempt;
        public \Flarum\User\User $user;
        public bool $firstPass;
    }
}

namespace Ernestdefoe\Curio {
    /**
     * @property int $id
     * @property string $name
     * @property string $slug
     * @property string $visibility
     */
    class Collection extends \Flarum\Database\AbstractModel
    {
    }

    /**
     * @property int $id
     * @property string $title
     * @property string $status
     * @property Collection|null $collection
     */
    class Item extends \Flarum\Database\AbstractModel
    {
    }
}

namespace Ernestdefoe\Curio\Api {
    class ItemResource extends \Flarum\Api\Resource\AbstractDatabaseResource
    {
    }
}

namespace ErnestDefoe\ClientArea\Model {
    /**
     * @property int $id
     * @property string $name
     * @property bool $is_active
     * @property string|null $extension_url
     */
    class Product extends \Flarum\Database\AbstractModel
    {
    }

    /**
     * @property string $provider
     * @property int $price_cents
     * @property Product|null $product
     * @property \Flarum\User\User|null $user
     */
    class Subscription extends \Flarum\Database\AbstractModel
    {
    }
}

namespace ErnestDefoe\ClientArea\Event {
    class ServicePaid
    {
        public \ErnestDefoe\ClientArea\Model\Subscription $subscription;
        public bool $first;
    }
}

namespace Pusher {
    class Pusher
    {
        /**
         * @param string|string[] $channels
         * @param array<string, mixed> $data
         */
        public function trigger(string|array $channels, string $event, array $data): object
        {
            return new \stdClass();
        }
    }
}
