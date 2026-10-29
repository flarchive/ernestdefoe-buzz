<?php

namespace Ernestdefoe\Buzz\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Buzz\Pruner;
use Ernestdefoe\Buzz\Recorder;
use Ernestdefoe\Buzz\Tests\integration\BuzzTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The poll endpoint: cursors, the shared guest copy, ETags, its cost, rate limit and pruning. */
class PollTest extends BuzzTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-buzz');
        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    /** @return array{0: int, 1: int} all queries, and those touching buzz_events */
    private function queriesFor(callable $fn): array
    {
        $all = $buzz = 0;
        $this->database()->listen(function ($query) use (&$all, &$buzz) {
            $all++;
            if (str_contains($query->sql, 'buzz_events')) {
                $buzz++;
            }
        });
        $fn();
        $this->database()->setEventDispatcher(new \Illuminate\Events\Dispatcher());

        return [$all, $buzz];
    }

    private function record(int $times): void
    {
        $recorder = $this->app()->getContainer()->make(Recorder::class);
        for ($i = 0; $i < $times; $i++) {
            $recorder->record('member', User::find(2));
        }
    }

    #[Test]
    public function a_page_starts_from_now_and_receives_only_what_came_after(): void
    {
        $this->boot();
        $this->record(3);

        $start = $this->poll(null);
        $this->assertSame([], $start['body']['events'], 'no backlog is replayed');
        $cursor = $start['body']['cursor'];

        $this->record(2);
        $next = $this->poll(null, ['after' => $cursor]);
        $this->assertCount(2, $next['body']['events']);
        $this->assertSame($cursor + 2, $next['body']['cursor']);
        $this->assertSame([], $this->poll(2, ['after' => $next['body']['cursor']])['body']['events']);
    }

    #[Test]
    public function recent_context_is_at_most_three_rows_from_the_last_hour(): void
    {
        $this->boot();
        $old = $this->insertEvent('member', 2, ['created_at' => Carbon::now('UTC')->subHours(2)]);
        $this->record(5);

        foreach ([null, 2] as $as) {
            $events = $this->poll($as, ['recent' => 9])['body']['events'];
            $this->assertCount(3, $events);
            $this->assertNotContains($old, array_column($events, 'id'));
        }
    }

    #[Test]
    public function an_unchanged_feed_answers_304_to_the_same_etag(): void
    {
        $this->boot();
        $this->record(1);
        $first = $this->poll(2, ['after' => 0]);
        $etag = $first['response']->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);
        $this->assertSame(304, $this->poll(2, ['after' => 0], ['If-None-Match' => $etag])['status']);

        $this->record(1);
        $this->assertSame(200, $this->poll(2, ['after' => 0], ['If-None-Match' => $etag])['status']);
    }

    #[Test]
    public function an_idle_poll_touches_no_buzz_table_and_a_busy_one_costs_one_query_whatever_the_size(): void
    {
        $this->boot();
        $this->record(3);
        $latest = $this->poll(2)['body']['cursor'];

        [$idleAll, $idle] = $this->queriesFor(fn () => $this->poll(2, ['after' => $latest]));
        $this->assertSame(0, $idle, 'nothing new: answered from the cached cursor');

        $this->poll(2, ['after' => 0]); // warm: the session's first touch writes once
        [$small, $smallBuzz] = $this->queriesFor(fn () => $this->poll(2, ['after' => 0]));
        $this->record(200);
        [$big, $bigBuzz] = $this->queriesFor(fn () => $this->poll(2, ['after' => 0]));

        $this->assertSame(1, $smallBuzz);
        $this->assertSame(1, $bigBuzz);
        $this->assertSame($small, $big, 'the same total with 3 rows or 203');
        $this->assertCount(20, $this->poll(2, ['after' => 0])['body']['events'], 'capped');

        if ($report = getenv('BUZZ_QUERY_REPORT')) {
            file_put_contents($report, "Member poll: idle $idleAll queries ($idle on buzz_events); 3 rows $small ($smallBuzz); 203 rows $big ($bigBuzz)\n", FILE_APPEND);
        }
    }

    #[Test]
    public function guests_share_one_cached_copy(): void
    {
        $this->boot();
        $this->record(2);

        $ids = [];
        [, $first] = $this->queriesFor(function () use (&$ids) {
            $ids = array_column($this->poll(null, ['after' => 0])['body']['events'], 'id');
        });
        $this->assertCount(2, $ids);

        // Ids from the answer: on MySQL and PostgreSQL they don't restart at 1 between tests.
        [, $second] = $this->queriesFor(fn () => $this->poll(null, ['after' => $ids[0]]));

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'a second guest (another cursor) costs no buzz query');
        $this->assertSame([$ids[1]], array_column($this->poll(null, ['after' => $ids[0]])['body']['events'], 'id'));
    }

    #[Test]
    public function the_endpoint_is_rate_limited(): void
    {
        $this->boot();
        $statuses = [];
        for ($i = 0; $i < 32; $i++) {
            $statuses[] = $this->poll(2, ['after' => $i])['status'];
        }

        $this->assertSame(200, $statuses[0]);
        $this->assertSame(429, end($statuses));
    }

    #[Test]
    public function a_forum_guests_cannot_view_serves_guests_nothing(): void
    {
        $this->boot();
        $this->database()->table('group_permission')->where('permission', 'viewForum')->where('group_id', 2)->delete();
        $this->database()->table('group_permission')->insert(['permission' => 'viewForum', 'group_id' => 3]);
        $this->app()->getContainer()->make(\Flarum\Group\PermissionCache::class)->flush();
        $this->record(1);

        $this->assertSame([], $this->poll(null, ['after' => 0])['body']['events']);
        $this->assertCount(1, $this->poll(2, ['after' => 0])['body']['events']);
    }

    #[Test]
    public function old_rows_and_rows_past_the_cap_are_pruned(): void
    {
        $this->setting('ernestdefoe-buzz.keep_hours', 24);
        $this->setting('ernestdefoe-buzz.keep_max', 50);
        $this->boot();

        $this->insertEvent('member', 2, ['created_at' => Carbon::now('UTC')->subHours(25)]);
        for ($i = 0; $i < 60; $i++) {
            $this->insertEvent('member', 2);
        }
        $newest = (int) $this->database()->table('buzz_events')->max('id');

        $this->assertSame(11, $this->app()->getContainer()->make(Pruner::class)->prune());
        $this->assertSame(50, $this->database()->table('buzz_events')->count());
        $this->assertSame($newest, (int) $this->database()->table('buzz_events')->max('id'));
    }
}
