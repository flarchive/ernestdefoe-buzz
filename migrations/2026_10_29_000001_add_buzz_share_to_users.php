<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 1.1.4 and earlier added users.buzz_share here. Since 1.1.5 the answer lives
 * in buzz_user_settings (see 2026_11_16_*), so a new install adds nothing to
 * core's users table; forums that ran this before are moved over by
 * 2026_11_16_000001. Kept, empty on the way up, so its record stays valid.
 */
return [
    'up' => function (Builder $schema) {
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'buzz_share')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('buzz_share');
            });
        }
    },
];
