<?php

namespace Ernestdefoe\Buzz\Api;

use Carbon\Carbon;
use Ernestdefoe\Buzz\Feed;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/buzz?after=<id>[&recent=<n>]
 *
 * - No `after`: where "now" is, plus up to `recent` (at most 3) rows from the
 *   last hour for context. Nothing older is ever replayed.
 * - `after` at or past the newest row: an empty answer that touches no table.
 * - Otherwise, the rows after it this viewer may see: guests from one shared
 *   cached copy, members by one indexed query.
 *
 * The answer carries an ETag of (newest row, cursor, viewer), so a browser
 * that asks again before anything happened gets a bodiless 304.
 */
class PollController implements RequestHandlerInterface
{
    /** Bumped whenever the events' format changes (2: subjects marked for bold). */
    private const FORMAT = 2;

    public function __construct(private Feed $feed)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();
        $latest = $this->feed->latest();
        $after = isset($query['after']) && is_numeric($query['after']) ? max(0, (int) $query['after']) : null;
        $recent = min(3, max(0, (int) ($query['recent'] ?? 0)));

        // FORMAT changes when the answer's shape does, so a browser's cached copy from before an update is not reused.
        $etag = '"buzz-'.self::FORMAT.'-'.$latest.'-'.($after ?? 'n'.$recent).'-'.($actor->isGuest() ? 'g' : $actor->id).'"';

        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return new EmptyResponse(304, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
        }

        $events = [];

        if ($after === null) {
            if ($recent > 0 && $latest > 0) {
                $events = $actor->isGuest()
                    ? array_slice(array_values(array_filter($this->feed->guestStream($latest), fn ($e) => $e['at'] >= Carbon::now('UTC')->subHour()->toIso8601String())), -$recent)
                    : $this->feed->since($actor, 0, $recent, Carbon::now('UTC')->subHour());
            }
        } elseif ($after < $latest) {
            $events = $actor->isGuest()
                ? array_values(array_filter($this->feed->guestStream($latest), fn ($e) => $e['id'] > $after))
                : $this->feed->since($actor, $after);
        }

        // A row committed after `latest` was read can come back too; never step behind it.
        $cursor = max($latest, $after ?? 0, ...array_map(fn ($e) => (int) $e['id'], $events ?: [['id' => 0]]));

        return new JsonResponse(['cursor' => $cursor, 'events' => $events], 200, [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
