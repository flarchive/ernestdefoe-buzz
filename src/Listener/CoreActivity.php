<?php

namespace Ernestdefoe\Buzz\Listener;

use Ernestdefoe\Buzz\Recorder;
use Ernestdefoe\Buzz\Settings;
use Flarum\Approval\Event\PostWasApproved;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event as DiscussionEvent;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Event as PostEvent;
use Flarum\Post\Post;
use Flarum\User\Event\Activated;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * New discussions, replies and members, and taking them back down again.
 *
 * Only what is already public-ish is recorded: an approved, visible comment
 * in a discussion that is not private. Each viewer's poll still asks core's
 * own post visibility, so a reply in a staff-only tag is recorded but only
 * staff ever receive it.
 */
class CoreActivity
{
    public function __construct(
        private Recorder $recorder,
        private Settings $settings,
        private ExtensionManager $extensions,
        private ConnectionInterface $db,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PostEvent\Posted::class, fn (PostEvent\Posted $e) => $this->post($e->post, $e->actor));
        $events->listen(Activated::class, fn (Activated $e) => $this->member($e->user));
        $events->listen(Registered::class, fn (Registered $e) => $e->user->is_email_confirmed ? $this->member($e->user) : null);

        // Held for approval: it goes out when a moderator lets it through.
        if (class_exists(PostWasApproved::class)) {
            $events->listen(PostWasApproved::class, fn (PostWasApproved $e) => $this->post($e->post));
        }

        // Taken down: forgotten at once, not just filtered.
        $events->listen(PostEvent\Hidden::class, fn (PostEvent\Hidden $e) => $this->forgetPost($e->post));
        $events->listen(PostEvent\Deleted::class, fn (PostEvent\Deleted $e) => $this->forgetPost($e->post));
        $events->listen(DiscussionEvent\Hidden::class, fn (DiscussionEvent\Hidden $e) => $this->forgetDiscussion($e->discussion));
        $events->listen(DiscussionEvent\Deleted::class, fn (DiscussionEvent\Deleted $e) => $this->forgetDiscussion($e->discussion));
    }

    public function post(Post $post, ?User $actor = null): void
    {
        $discussion = $post->discussion;

        if (! $post instanceof CommentPost || ! $discussion || ! $post->user_id || ! $this->isOut($post, $discussion)) {
            return;
        }

        // The poster is the actor: already loaded, groups and all.
        $author = $actor && $actor->id === $post->user_id ? $actor : $post->user;

        $first = (int) $post->number === 1 || $post->id === $discussion->first_post_id;
        $id = $discussion->id.($discussion->slug ? '-'.$discussion->slug : '');

        $this->recorder->record($first ? 'discussion' : 'reply', $author, ['title' => $discussion->title], [
            'route' => $first
                ? ['name' => 'discussion', 'params' => ['id' => $id]]
                : ['name' => 'discussion.near', 'params' => ['id' => $id, 'near' => (int) $post->number]],
            'discussion_id' => $discussion->id,
            'post_id' => $post->id,
            'tag_ids' => $this->tagIds($discussion),
        ]);
    }

    public function member(User $user): void
    {
        $this->recorder->record('member', $user);
    }

    private function isOut(Post $post, Discussion $discussion): bool
    {
        return $post->hidden_at === null
            && $discussion->hidden_at === null
            && ! $post->is_private
            && ! $discussion->is_private
            // flarum/approval's flag; without it, everything is approved.
            && ! in_array($post->getAttribute('is_approved'), [false, 0, '0'], true)
            && ! in_array($discussion->getAttribute('is_approved'), [false, 0, '0'], true);
    }

    /** @return int[] only looked up when the admin excluded some tags */
    private function tagIds(Discussion $discussion): array
    {
        if (! $this->settings->ids('exclude_tags') || ! $this->extensions->isEnabled('flarum-tags')) {
            return [];
        }

        return $this->db->table('discussion_tag')->where('discussion_id', $discussion->id)->pluck('tag_id')->map(fn ($id) => (int) $id)->all();
    }

    private function forgetPost(Post $post): void
    {
        $this->db->table('buzz_events')->where('post_id', $post->id)->delete();
    }

    private function forgetDiscussion(Discussion $discussion): void
    {
        $this->db->table('buzz_events')->where('discussion_id', $discussion->id)->delete();
    }
}
