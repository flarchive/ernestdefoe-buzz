<?php

namespace Ernestdefoe\Buzz\Integration;

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
 */
class Brainstorm
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function __invoke(\ErnestDefoe\Brainstorm\Event\AttemptFinished $event): void
    {
        $quiz = $event->attempt->quiz;

        if (! $event->firstPass || ! $quiz || ! $quiz->leaderboard || ! $quiz->isPublished() || $event->user->getAttribute('quiz_private')) {
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
        $query->where(fn (Builder $q) => $q->whereNull('users.quiz_private')->orWhere('users.quiz_private', false));

        Feed::whereExists(
            $query,
            \ErnestDefoe\Brainstorm\Quiz::query()->visibleTo($actor)->where('quizzes.leaderboard', true),
            'quizzes.id'
        );
    }
}
