<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 1.1.4 and earlier kept the answer in users.buzz_share. Copy every answer
 * given (one INSERT … SELECT, prefix-safe through the query builder), then
 * take the column off core's table. down() puts it back with the answers.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'buzz_share')) {
            return;
        }

        $db = $schema->getConnection();

        // OrIgnore: MySQL can't roll back the drop below, so a re-run after a failed drop must not trip on rows already copied.
        $db->table('buzz_user_settings')->insertOrIgnoreUsing(
            ['user_id', 'buzz_share'],
            $db->table('users')->select('id', 'buzz_share')->whereNotNull('buzz_share')
        );

        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn('buzz_share');
        });
    },

    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'buzz_share')) {
            return;
        }

        $schema->table('users', function (Blueprint $table) {
            $table->boolean('buzz_share')->nullable();
        });

        $db = $schema->getConnection();

        foreach ([true, false] as $share) {
            $db->table('users')
                ->whereIn('id', $db->table('buzz_user_settings')->select('user_id')->where('buzz_share', $share))
                ->update(['buzz_share' => $share]);
        }
    },
];
