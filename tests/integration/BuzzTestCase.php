<?php

namespace Ernestdefoe\Buzz\Tests\integration;

use Carbon\Carbon;
use Ernestdefoe\Buzz\Recorder;
use Flarum\Extend;
use Flarum\Locale\LocaleManager;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;

abstract class BuzzTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests post several times a second; core's flood guard would refuse.
        $this->extend((new Extend\ThrottleApi())->remove('postTimeout'));

        foreach (['min_primary_tags' => 1, 'max_primary_tags' => 1, 'min_secondary_tags' => 0, 'max_secondary_tags' => 0] as $key => $value) {
            $this->setting('flarum-tags.'.$key, $value);
        }
    }

    /**
     * Every test runs inside one transaction that is rolled back, so "after
     * commit" would never come. Like Laravel's own test helper, count the
     * request's transactions only: callbacks run when those commit.
     */
    protected function boot(): void
    {
        $this->app();

        /** @var Connection $db */
        $db = $this->database();
        $db->setTransactionManager(new class extends DatabaseTransactionsManager {
            public function afterCommitCallbacksShouldBeExecuted($level)
            {
                return $level <= 1;
            }
        });

        $this->app()->getContainer()->make(Cache::class)->forget(Recorder::LATEST);

        /*
         * flarum/testing enables extensions (which resolves the locale
         * manager) before it applies their extenders, so an extension's
         * Locales extender never runs in tests. Load ours by hand.
         */
        $this->app()->getContainer()->make(LocaleManager::class)->addTranslations('en', __DIR__.'/../../locale/en.yml');
    }

    /** @return array<string, mixed> */
    protected function poll(?int $as, array $query = [], array $headers = []): array
    {
        $request = $this->request('GET', '/api/buzz', $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->send($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true), 'response' => $response];
    }

    /** @return string[] the types a viewer receives after a cursor */
    protected function seen(?int $as, int $after = 0): array
    {
        $result = $this->poll($as, ['after' => $after]);
        $this->assertSame(200, $result['status'], json_encode($result['body']));

        return array_map(fn ($e) => $e['type'].':'.($e['route']['params']['id'] ?? $e['user']['slug'] ?? ''), $result['body']['events']);
    }

    protected function startDiscussion(int $as, string $title, array $tagIds = []): array
    {
        $relationships = $tagIds ? ['tags' => ['data' => array_map(fn ($id) => ['type' => 'tags', 'id' => (string) $id], $tagIds)]] : [];

        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => ['title' => $title, 'content' => 'Body of '.$title], 'relationships' => $relationships]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));

        return json_decode((string) $response->getBody(), true)['data'];
    }

    protected function reply(int $as, int $discussionId, string $content = 'A reply'): array
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => $content], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => (string) $discussionId]]]]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), substr((string) $response->getBody(), 0, 2000));

        return json_decode((string) $response->getBody(), true)['data'];
    }

    protected function insertEvent(string $type, ?int $userId, array $extra = []): int
    {
        $this->app()->getContainer()->make(Cache::class)->forget(Recorder::LATEST);

        return (int) $this->database()->table('buzz_events')->insertGetId(array_merge([
            'type' => $type,
            'user_id' => $userId,
            'members_only' => false,
            'data' => json_encode(['params' => []]),
            'created_at' => Carbon::now('UTC'),
        ], $extra));
    }
}
