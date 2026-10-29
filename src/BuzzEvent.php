<?php

namespace Ernestdefoe\Buzz;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $type
 * @property int|null $user_id
 * @property int|null $discussion_id
 * @property int|null $post_id
 * @property int|null $subject_id
 * @property bool $members_only
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
class BuzzEvent extends AbstractModel
{
    protected $table = 'buzz_events';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'discussion_id' => 'integer',
        'post_id' => 'integer',
        'subject_id' => 'integer',
        'members_only' => 'boolean',
        'data' => 'array',
        'created_at' => 'datetime',
    ];
}
