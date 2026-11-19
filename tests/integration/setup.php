<?php

use Flarum\Testing\integration\Setup\SetupScript;

require __DIR__.'/../../vendor/autoload.php';

$setup = new SetupScript();

$setup->run();

/*
 * Stand-in tables for the optional extensions IntegrationsTest fakes. They
 * are created here, once, because DDL inside a test would commit MySQL's
 * per-test transaction and leak rows into the next test.
 */
$config = require $setup->tmpDir().'/config.php';
$capsule = new Illuminate\Database\Capsule\Manager();
$capsule->addConnection($config['database']);
$schema = $capsule->getConnection()->getSchemaBuilder();

// ernestdefoe/brainstorm 1.2
if (! $schema->hasTable('quizzes')) {
    $schema->create('quizzes', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('title');
        $table->string('slug');
        $table->boolean('leaderboard');
    });
}
if (! $schema->hasTable('quiz_leaderboard_opt_outs')) {
    $schema->create('quiz_leaderboard_opt_outs', fn (Illuminate\Database\Schema\Blueprint $table) => $table->unsignedInteger('user_id')->primary());
}
