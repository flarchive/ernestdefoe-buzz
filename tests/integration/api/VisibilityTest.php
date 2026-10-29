<?php

namespace Ernestdefoe\Buzz\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Buzz\Listener\CoreActivity;
use Ernestdefoe\Buzz\Tests\integration\BuzzTestCase;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\CommentPost;
use Flarum\User\Event\Activated;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

/** Who receives what: every row is checked for every viewer, on the server. */
class VisibilityTest extends BuzzTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-suspend', 'ernestdefoe-buzz');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'moderator', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'unconfirmed', 'email' => 'un@machine.local', 'is_email_confirmed' => 0],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0, 'is_restricted' => false],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true],
                ['id' => 3, 'name' => 'Offtopic', 'slug' => 'offtopic', 'position' => 2, 'is_restricted' => false],
            ],
        ]);
    }

    #[Test]
    public function a_public_discussion_and_reply_reach_guests_and_members(): void
    {
        $this->boot();
        $d = $this->startDiscussion(2, 'Hello world', [1]);
        $this->reply(3, (int) $d['id']);

        $expected = ['discussion:'.$d['id'].'-hello-world', 'reply:'.$d['id'].'-hello-world'];
        $this->assertSame($expected, $this->seen(null));
        $this->assertSame($expected, $this->seen(2));

        $event = $this->poll(null, ['after' => 0])['body']['events'][1];
        $this->assertSame("\u{1} replied to Hello world", $event['text']);
        $this->assertSame('moderator', $event['user']['name']);
        $this->assertSame('discussion.near', $event['route']['name']);
        $this->assertSame(2, $event['route']['params']['near']);
    }

    #[Test]
    public function a_restricted_tag_reaches_only_those_who_can_see_it(): void
    {
        $this->boot();
        $this->startDiscussion(1, 'Staff only', [2]);

        $this->assertSame([], $this->seen(null));
        $this->assertSame([], $this->seen(2));
        $this->assertCount(1, $this->seen(1));
    }

    #[Test]
    public function a_hidden_post_is_forgotten_at_once(): void
    {
        $this->boot();
        $d = $this->startDiscussion(2, 'Topic', [1]);
        $post = $this->reply(2, (int) $d['id']);

        $response = $this->send($this->request('PATCH', '/api/posts/'.$post['id'], [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $post['id'], 'attributes' => ['isHidden' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(0, $this->database()->table('buzz_events')->where('post_id', $post['id'])->count());
        $this->assertSame(['discussion:'.$d['id'].'-topic'], $this->seen(null));
        // Even staff, who could see the hidden post, no longer receive it.
        $this->assertSame(['discussion:'.$d['id'].'-topic'], $this->seen(1));
    }

    #[Test]
    public function a_post_waiting_for_approval_goes_out_when_approved(): void
    {
        $this->extension('flarum-tags', 'flarum-suspend', 'flarum-flags', 'flarum-approval', 'ernestdefoe-buzz');
        $this->boot();
        $d = $this->startDiscussion(2, 'Topic', [1]);

        $this->database()->table('group_permission')->where('permission', 'discussion.replyWithoutApproval')->delete();
        $this->app()->getContainer()->make(\Flarum\Group\PermissionCache::class)->flush();
        $post = $this->reply(2, (int) $d['id']);

        $this->assertSame(['discussion:'.$d['id'].'-topic'], $this->seen(1), 'unapproved: not recorded, not even for staff');

        $response = $this->send($this->request('PATCH', '/api/posts/'.$post['id'], [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $post['id'], 'attributes' => ['isApproved' => true]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(['discussion:'.$d['id'].'-topic', 'reply:'.$d['id'].'-topic'], $this->seen(null));
    }

    #[Test]
    public function a_suspended_author_drops_out_of_the_feed(): void
    {
        $this->boot();
        $this->startDiscussion(2, 'Topic', [1]);
        $this->assertCount(1, $this->seen(null));

        $this->database()->table('users')->where('id', 2)->update(['suspended_until' => Carbon::now()->addDay()]);
        $this->assertSame([], $this->seen(1), 'members: at once');
        $this->assertCount(1, $this->seen(null), 'guests share a copy cached for up to a minute');
        $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->flush();
        $this->assertSame([], $this->seen(null), 'guests: once that copy expires');

        // And while suspended, nothing new is recorded.
        $this->app()->getContainer()->make(CoreActivity::class)->member(User::find(2));
        $this->assertSame(1, $this->database()->table('buzz_events')->count());
    }

    #[Test]
    public function a_member_who_opted_out_is_never_shown(): void
    {
        $this->boot();
        $this->startDiscussion(2, 'Before', [1]);

        $this->database()->table('users')->where('id', 2)->update(['buzz_share' => false]);
        $this->assertSame([], $this->seen(null), 'opting out takes what was shown down too');

        $this->startDiscussion(2, 'After', [1]);
        $this->assertSame(1, $this->database()->table('buzz_events')->count(), 'nothing new is recorded');
    }

    #[Test]
    public function the_opt_in_setting_shows_only_members_who_switched_sharing_on(): void
    {
        $this->setting('ernestdefoe-buzz.opt_in', true);
        $this->boot();

        $this->startDiscussion(2, 'Quiet', [1]);
        $this->assertSame(0, $this->database()->table('buzz_events')->count());

        $this->database()->table('users')->where('id', 3)->update(['buzz_share' => true]);
        $d = $this->startDiscussion(3, 'Loud', [1]);
        $this->assertSame(['discussion:'.$d['id'].'-loud'], $this->seen(null));
    }

    #[Test]
    public function the_member_can_switch_sharing_on_their_own_account_only(): void
    {
        $this->boot();

        $response = $this->send($this->request('PATCH', '/api/users/2', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'users', 'id' => '2', 'attributes' => ['buzzShare' => false]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(false, json_decode((string) $response->getBody(), true)['data']['attributes']['buzzShare']);
        $this->assertSame(0, (int) $this->database()->table('users')->where('id', 2)->value('buzz_share'));

        // Somebody else's: not even visible.
        $response = $this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 3]));
        $this->assertArrayNotHasKey('buzzShare', json_decode((string) $response->getBody(), true)['data']['attributes']);
    }

    #[Test]
    public function excluded_groups_and_tags_are_never_broadcast(): void
    {
        $this->setting('ernestdefoe-buzz.exclude_groups', json_encode([Group::MODERATOR_ID]));
        $this->setting('ernestdefoe-buzz.exclude_tags', json_encode([3]));
        $this->boot();

        $this->startDiscussion(3, 'From a moderator', [1]);
        $this->startDiscussion(2, 'Off topic', [3]);
        $d = $this->startDiscussion(2, 'Fine', [1]);

        $this->assertSame(['discussion:'.$d['id'].'-fine'], $this->seen(1));
    }

    #[Test]
    public function a_private_discussion_is_never_recorded(): void
    {
        $this->prepareDatabase([
            Discussion::class => [['id' => 50, 'title' => 'DM', 'user_id' => 2, 'is_private' => true, 'first_post_id' => 50, 'comment_count' => 1]],
            'posts' => [['id' => 50, 'discussion_id' => 50, 'user_id' => 2, 'number' => 1, 'type' => 'comment', 'content' => '<t>x</t>', 'created_at' => Carbon::now()]],
        ]);
        $this->boot();

        $this->app()->getContainer()->make(CoreActivity::class)->post(CommentPost::find(50));

        $this->assertSame(0, $this->database()->table('buzz_events')->count());
    }

    #[Test]
    public function a_new_member_is_announced_only_once_their_email_is_confirmed(): void
    {
        $this->boot();
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        $events->dispatch(new Registered(User::find(4), User::find(4)));
        $this->assertSame(0, $this->database()->table('buzz_events')->count());

        // An unconfirmed member's row, however it got there, is never served.
        $this->insertEvent('member', 4);
        $this->assertSame([], $this->seen(null));

        $this->database()->table('users')->where('id', 4)->update(['is_email_confirmed' => true]);
        $this->database()->table('buzz_events')->delete();
        $events->dispatch(new Activated(User::find(4)));
        $this->assertSame(['member:unconfirmed'], $this->seen(null));
    }

    #[Test]
    public function a_switched_off_type_is_neither_recorded_nor_served(): void
    {
        $this->setting('ernestdefoe-buzz.type.reply', false);
        $this->boot();

        $d = $this->startDiscussion(2, 'Topic', [1]);
        $this->reply(2, (int) $d['id']);
        $this->insertEvent('reply', 2);

        $this->assertSame(['discussion:'.$d['id'].'-topic'], $this->seen(null));
    }
}
