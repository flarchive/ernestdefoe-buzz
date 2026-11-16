<?php

namespace Ernestdefoe\Buzz;

use Flarum\Database\AbstractModel;

/**
 * A member's own Buzz answers. No row = never chose, so the forum default applies.
 *
 * @property int $user_id
 * @property bool $buzz_share
 */
class BuzzUserSetting extends AbstractModel
{
    protected $table = 'buzz_user_settings';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'buzz_share' => 'boolean',
    ];

    /** The member's answer: true, false, or null when they never chose. */
    public static function shareOf(\Flarum\User\User $user): ?bool
    {
        $row = $user->getRelationValue('buzzSettings');

        return $row instanceof self ? $row->buzz_share : null;
    }
}
