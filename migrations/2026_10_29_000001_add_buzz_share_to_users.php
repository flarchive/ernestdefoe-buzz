<?php

use Flarum\Database\Migration;

/*
 * A member's own "show my activity in Buzz" answer: null until they choose,
 * so the forum's default (share, or ask first) applies to everyone who never
 * touched it, and changing that default needs no rewrite of this column.
 */
return Migration::addColumns('users', [
    'buzz_share' => ['boolean', 'nullable' => true],
]);
