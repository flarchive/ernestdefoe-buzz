<?php

namespace Ernestdefoe\Buzz\Integration;

use ErnestDefoe\Brainstorm\OptOut;
use Ernestdefoe\Buzz\Feed;
use Ernestdefoe\Buzz\Recorder;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ernestdefoe/brainstorm: a member passed a quiz for the first time.
 *
 * Brainstorm's privacy is kept exactly: a member who keeps themselves off
 * quiz leaderboards is never broadcast, nor is a pass on a quiz whose
 * results are private (leaderboard switched off). Both are asked again on
 * every poll, so switching either on later takes passes already shown down.
 *
 * Works with both Brainstorm layouts: 1.2+ keeps the leaderboard opt-out in
 * its own quiz_leaderboard_opt_outs table (OptOut) and scopes quizzes with
 * core's whereVisibleTo; 1.1 kept a users.quiz_private column and its own
 * visibleTo scope.
 */
class Brainstorm
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function __invoke(\ErnestDefoe\Brainstorm\Event\AttemptFinished $event): void
    {
        $quiz = $event->attempt->quiz;

        if (! $event->firstPass || ! $quiz || ! $quiz->leaderboard || ! $quiz->isPublished() || self::optedOut($event->user)) {
            return;
        }

        $this->recorder->record('quiz', $event->user, ['quiz' => $quiz->title], [
            'route' => ['name' => 'brainstorm.quiz', 'params' => ['id' => $quiz->id.($quiz->slug ? '-'.$quiz->slug : '')]],
            'subject_id' => $quiz->id,
            'members_only' => true,
        ]);
    }

    /** @param Builder<\Ernestdefoe\Buzz\BuzzEvent> $query */
    public static function scope(Builder $query, User $actor): void
    {
        if (class_exists(OptOut::class)) {
            $query->whereNotIn('users.id', OptOut::query()->select('user_id'));
        } else {
            $query->where(fn (Builder $q) => $q->whereNull('users.quiz_private')->orWhere('users.quiz_private', false));
        }

        // Brainstorm 1.1 had its own visibleTo scope instead of core's trait.
        $quizzes = \ErnestDefoe\Brainstorm\Quiz::query();
        $quizzes = method_exists(\ErnestDefoe\Brainstorm\Quiz::class, 'scopeWhereVisibleTo') // @phpstan-ignore function.alreadyNarrowedType
            ? $quizzes->whereVisibleTo($actor)
            : $quizzes->visibleTo($actor);

        Feed::whereExists($query, $quizzes->where('quizzes.leaderboard', true), 'quizzes.id');
    }

    /** Whether the member keeps themselves off quiz leaderboards (and so out of Buzz). */
    private static function optedOut(User $user): bool
    {
        if (class_exists(OptOut::class)) {
            return OptOut::query()->whereKey($user->id)->exists();
        }

        return (bool) $user->getAttribute('quiz_private');
    }
}
