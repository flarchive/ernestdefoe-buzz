<?php

namespace Ernestdefoe\Buzz\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Buzz\Tests\integration\BuzzTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 Buzz runs on every page, so it must add NO query to a normal page load:
 * the forum page, /api, the discussion list (1 and 20 discussions) and a
 * discussion, for a guest and for a member. Measured with Buzz disabled,
 * then enabled with events in its table, and compared exactly.
 */
class PageLoadQueriesTest extends BuzzTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $discussions = $posts = [];
        for ($i = 1; $i <= 20; $i++) {
            $discussions[] = ['id' => $i, 'title' => "Discussion $i", 'slug' => "discussion-$i", 'user_id' => 2, 'created_at' => Carbon::now()->subMinutes($i), 'first_post_id' => $i, 'last_post_id' => $i, 'last_posted_at' => Carbon::now()->subMinutes($i), 'comment_count' => 1, 'last_post_number' => 1];
            $posts[] = ['id' => $i, 'discussion_id' => $i, 'user_id' => 2, 'number' => 1, 'type' => 'comment', 'content' => '<t>Post</t>', 'created_at' => Carbon::now()->subMinutes($i)];
        }

        $this->prepareDatabase([User::class => [$this->normalUser()], 'discussions' => $discussions, 'posts' => $posts]);
    }

    /** @return array<string, int> */
    private function measure(): array
    {
        $routes = [
            'forum page' => '/',
            'discussion page' => '/d/1',
            '/api' => '/api',
            'list of 1' => '/api/discussions?page[limit]=1',
            'list of 20' => '/api/discussions?page[limit]=20',
            'discussion' => '/api/discussions/1',
        ];

        $counts = [];
        foreach ([null => 'guest', 2 => 'member'] as $as => $who) {
            foreach ($routes as $label => $path) {
                [$uri, $query] = array_pad(explode('?', $path, 2), 2, '');
                parse_str($query, $params);
                $request = $this->request('GET', $uri, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($params);

                $this->assertSame(200, $this->send($request)->getStatusCode(), "$who $label"); // warm

                // The fewest of three: a once-a-while write (a session touch,
                // a last-seen bump) is not a cost of the page.
                $runs = [];
                for ($run = 0; $run < 3; $run++) {
                    $n = 0;
                    $this->database()->listen(function () use (&$n) {
                        $n++;
                    });
                    $this->send($request);
                    $this->database()->setEventDispatcher(new \Illuminate\Events\Dispatcher());
                    $runs[] = $n;
                }
                $counts["$who $label"] = min($runs);
            }
        }

        return $counts;
    }

    #[Test]
    public function without_buzz(): array
    {
        $this->extension('flarum-tags');
        $this->boot();

        return $this->measure();
    }

    #[Test]
    #[Depends('without_buzz')]
    public function with_buzz_every_page_costs_exactly_the_same(array $without): void
    {
        $this->extension('flarum-tags', 'ernestdefoe-buzz');
        $this->boot();
        for ($i = 0; $i < 30; $i++) {
            $this->insertEvent('member', 2);
        }
        $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->forever(\Ernestdefoe\Buzz\Recorder::LATEST, 30);

        $with = $this->measure();

        // BUZZ_QUERY_REPORT=/path/file.txt writes the table, for the release notes.
        if ($report = getenv('BUZZ_QUERY_REPORT')) {
            $lines = array_map(fn ($label) => sprintf('%-26s %3d -> %3d', $label, $without[$label], $with[$label]), array_keys($with));
            file_put_contents($report, "Queries per page load (Buzz off -> on)\n".implode("\n", $lines)."\n");
        }

        $this->assertSame($without, $with);
        $this->assertSame($with['guest list of 1'], $with['guest list of 20']);
        $this->assertSame($with['member list of 1'], $with['member list of 20']);
    }
}
