<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per broadcast. Only what deciding "may this viewer see it" and
 * drawing it need: the post or subject it points at (whose own visibility is
 * asked when a viewer polls), whether guests are left out, and a small JSON
 * blob of display data (a title, a post number, a label).
 *
 * The id is the polling cursor: "everything after 1234" walks the primary key
 * backwards from the top, so a poll costs the same with ten rows or ten
 * thousand.
 */
return Migration::createTable('buzz_events', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('type', 60);
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('post_id')->nullable();
    $table->unsignedInteger('subject_id')->nullable();
    $table->boolean('members_only')->default(false);
    $table->text('data')->nullable();
    $table->dateTime('created_at');

    $table->index('created_at');
    $table->index('post_id');
    $table->index('discussion_id');
    $table->index(['type', 'subject_id']);

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
