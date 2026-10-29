<?php

// Stand-ins for Ernest's extensions that are not on Packagist, shaped like
// the real classes Buzz reads (see tests/phpstan/optional-extensions.php).
namespace Ernestdefoe\Ladder\Notification {
    if (! class_exists(PromotedBlueprint::class)) {
        class PromotedBlueprint implements \Flarum\Notification\Blueprint\BlueprintInterface
        {
            public function __construct(public \Flarum\Group\Group $group)
            {
            }

            public function getSubject(): ?\Flarum\Database\AbstractModel
            {
                return $this->group;
            }

            public function getFromUser(): ?\Flarum\User\User
            {
                return null;
            }

            public function getData(): mixed
            {
                return null;
            }

            public static function getType(): string
            {
                return 'ladderPromoted';
            }

            public static function getSubjectModel(): string
            {
                return \Flarum\Group\Group::class;
            }
        }
    }
}

namespace ErnestDefoe\Cadence\Notification {
    if (! class_exists(StreakMoment::class)) {
        class StreakMoment extends \Ernestdefoe\Ladder\Notification\PromotedBlueprint
        {
            public function __construct(public \Flarum\User\User $user, public string $event, public int $value, public string $on)
            {
            }
        }
    }
}

namespace ErnestDefoe\ClientArea\Model {
    if (! class_exists(Subscription::class)) {
        class Product extends \Flarum\Database\AbstractModel
        {
        }

        class Subscription extends \Flarum\Database\AbstractModel
        {
        }
    }
}

namespace ErnestDefoe\ClientArea\Event {
    if (! class_exists(ServicePaid::class)) {
        class ServicePaid
        {
            public function __construct(public \ErnestDefoe\ClientArea\Model\Subscription $subscription, public bool $first)
            {
            }
        }
    }
}

namespace Ernestdefoe\Buzz\Tests\integration\api {
    use Carbon\Carbon;
    use Ernestdefoe\Buzz\Extend\Buzz;
    use Ernestdefoe\Buzz\Integration;
    use Ernestdefoe\Buzz\Recorder;
    use Ernestdefoe\Buzz\Tests\integration\BuzzTestCase;
    use Ernestdefoe\Buzz\Types;
    use Flarum\Group\Group;
    use Flarum\User\User;
    use FoF\Badges\Badge;
    use FoF\Badges\Event\BadgeAwarded;
    use FoF\Badges\UserBadge;
    use Illuminate\Contracts\Events\Dispatcher;
    use PHPUnit\Framework\Attributes\Test;

    /** Each optional integration, present and absent, and the public extender. */
    class IntegrationsTest extends BuzzTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $this->prepareDatabase([
                User::class => [$this->normalUser()],
                'groups' => [
                    ['id' => 10, 'name_singular' => 'Veteran', 'name_plural' => 'Veterans', 'is_hidden' => 0],
                    ['id' => 11, 'name_singular' => 'Secret', 'name_plural' => 'Secrets', 'is_hidden' => 1],
                ],
            ]);
        }

        private function types(): array
        {
            return array_keys($this->app()->getContainer()->make(Types::class)->all());
        }

        #[Test]
        public function without_any_integration_only_the_core_types_exist_and_stray_rows_are_ignored(): void
        {
            $this->extension('ernestdefoe-buzz');
            $this->boot();

            $this->assertSame(['discussion', 'reply', 'member'], $this->types());

            // A row left behind by an extension since disabled: never served, never an error.
            $this->insertEvent('badge', 2);
            $this->insertEvent('member', 2);
            $result = $this->poll(null, ['after' => 0]);
            $this->assertSame(200, $result['status']);
            $this->assertSame(['member'], array_column($result['body']['events'], 'type'));
        }

        #[Test]
        public function fof_badges_earned_visible_badges_are_broadcast(): void
        {
            $this->extension('fof-badges', 'ernestdefoe-buzz');
            $this->prepareDatabase([
                'fof_badges' => [
                    ['id' => 1, 'name' => 'Helper', 'slug' => 'helper', 'icon' => 'fas fa-star', 'is_visible' => 1, 'is_active' => 1, 'created_at' => Carbon::now()],
                    ['id' => 2, 'name' => 'Hidden', 'slug' => 'hidden', 'icon' => 'fas fa-eye-slash', 'is_visible' => 0, 'is_active' => 1, 'created_at' => Carbon::now()],
                ],
            ]);
            $this->boot();
            $this->assertContains('badge', $this->types());

            $events = $this->app()->getContainer()->make(Dispatcher::class);
            foreach ([1, 2] as $id) {
                $badge = Badge::find($id);
                $events->dispatch(new BadgeAwarded(User::find(2), $badge, new UserBadge(), 'manual'));
            }

            $served = $this->poll(null, ['after' => 0])['body']['events'];
            $this->assertCount(1, $served);
            $this->assertSame("\u{1} earned the Helper badge", $served[0]['text']);
            $this->assertSame('fas fa-award', $served[0]['icon']);
        }

        #[Test]
        public function ladder_rank_ups_ride_on_the_promotion_alert_and_hidden_ranks_stay_hidden(): void
        {
            $this->extension('ernestdefoe-buzz');
            $this->extend((new Buzz())->type('rank', 'fas fa-ranking-star', 'ernestdefoe-buzz.forum.event.rank'));
            $this->boot();

            $hook = $this->app()->getContainer()->make(Integration\Ladder::class);
            $user = User::find(2);
            $this->assertSame([$user], $hook(new \Ernestdefoe\Ladder\Notification\PromotedBlueprint(Group::find(10)), [$user]), 'recipients pass through untouched');
            $hook(new \Ernestdefoe\Ladder\Notification\PromotedBlueprint(Group::find(11)), [$user]);

            $served = $this->poll(null, ['after' => 0])['body']['events'];
            $this->assertSame(["\u{1} reached the rank Veteran"], array_column($served, 'text'));
        }

        #[Test]
        public function cadence_milestones_only(): void
        {
            $this->extension('ernestdefoe-buzz');
            $this->extend((new Buzz())->type('streak', 'fas fa-fire', 'ernestdefoe-buzz.forum.event.streak'));
            $this->boot();

            $hook = $this->app()->getContainer()->make(Integration\Cadence::class);
            $hook(new \ErnestDefoe\Cadence\Notification\StreakMoment(User::find(2), 'milestone', 30, '2026-10-29'), []);
            $hook(new \ErnestDefoe\Cadence\Notification\StreakMoment(User::find(2), 'saved', 1, '2026-10-29'), []);

            $this->assertSame(["\u{1} hit a 30-day streak"], array_column($this->poll(null, ['after' => 0])['body']['events'], 'text'));
        }

        #[Test]
        public function a_purchase_is_anonymous_unless_the_admin_shows_buyers(): void
        {
            $this->extension('ernestdefoe-buzz');
            $this->extend((new Buzz())->type('purchase', 'fas fa-bag-shopping', 'ernestdefoe-buzz.forum.event.purchase'));
            $this->boot();

            $product = new \ErnestDefoe\ClientArea\Model\Product();
            $product->forceFill(['id' => 5, 'name' => 'Parley Calls', 'is_active' => true, 'extension_url' => '/extensions/parley-calls']);
            $paid = function (bool $first, string $provider = 'paypal', int $cents = 3900) use ($product) {
                $s = new \ErnestDefoe\ClientArea\Model\Subscription();
                $s->forceFill(['provider' => $provider, 'price_cents' => $cents]);
                $s->setRelation('product', $product);
                $s->setRelation('user', User::find(2));

                return new \ErnestDefoe\ClientArea\Event\ServicePaid($s, $first);
            };
            $hook = $this->app()->getContainer()->make(Integration\ClientArea::class);

            $hook($paid(false));              // a renewal
            $hook($paid(true, 'grant', 0));   // a comped plan
            $hook($paid(true));

            $served = $this->poll(null, ['after' => 0])['body']['events'];
            $this->assertCount(1, $served);
            $this->assertSame('Someone bought Parley Calls', $served[0]['text']);
            $this->assertNull($served[0]['user'], 'no name, avatar or profile link');
            $this->assertSame('/extensions/parley-calls', $served[0]['url']);
        }

        #[Test]
        public function the_extender_adds_a_type_with_its_own_permission_and_scope(): void
        {
            $this->extension('ernestdefoe-buzz');
            $this->extend((new Buzz())->type('acme.thing', 'fas fa-star', 'ernestdefoe-buzz.forum.event.member', [
                'permission' => 'acme.view',
                'scope' => fn ($query, User $actor) => $query->where('buzz_events.subject_id', '!=', 99),
            ]));
            $this->prepareDatabase(['group_permission' => [['permission' => 'acme.view', 'group_id' => Group::MEMBER_ID]]]);
            $this->boot();

            $recorder = $this->app()->getContainer()->make(Recorder::class);
            $recorder->record('acme.thing', User::find(2), [], ['subject_id' => 1, 'route' => ['name' => 'acme', 'params' => ['id' => 1]]]);
            $recorder->record('acme.thing', User::find(2), [], ['subject_id' => 99]);
            $this->assertNull($recorder->record('acme.unknown', User::find(2)), 'an unregistered type records nothing');

            $this->assertSame([], $this->poll(null, ['after' => 0])['body']['events'], 'guests lack the permission');
            $served = $this->poll(2, ['after' => 0])['body']['events'];
            $this->assertCount(1, $served, 'the scope removed subject 99');
            $this->assertSame(['name' => 'acme', 'params' => ['id' => 1]], $served[0]['route']);
        }

        #[Test]
        public function a_url_must_be_a_path_on_this_forum(): void
        {
            $this->extension('ernestdefoe-buzz');
            $this->boot();

            $recorder = $this->app()->getContainer()->make(Recorder::class);
            foreach (['//evil.example/x', 'https://evil.example', 'javascript:alert(1)', '/ok/path'] as $url) {
                $recorder->record('member', User::find(2), [], ['url' => $url]);
            }

            $this->assertSame([null, null, null, '/ok/path'], array_column($this->poll(null, ['after' => 0])['body']['events'], 'url'));
        }
    }
}
