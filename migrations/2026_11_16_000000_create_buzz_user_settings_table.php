<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's own Buzz answers, beside core's users table instead of in it.
 * No row means "never chose": the forum's default (share, or ask first)
 * applies, so changing that default needs no rewrite here.
 */
return Migration::createTable('buzz_user_settings', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->boolean('buzz_share');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
