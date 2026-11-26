<?php

namespace Ernestdefoe\Buzz;

use Closure;

/**
 * One kind of broadcast: "new reply", "badge earned", "quiz passed".
 *
 * - `key`         stored on each row; also the admin switch's name.
 * - `icon`        a Font Awesome class, drawn beside the text.
 * - `label`       a translation key. It gets `{user}` (the member's name, drawn
 *                 by the browser as a link or plain text) and every scalar
 *                 the recorder put in the event's `params`.
 * - `permission`  a global permission a viewer must hold to see any of them
 *                 (asked in PHP from the viewer's already-loaded groups).
 * - `scope`       optional fn (Builder $query, User $actor): void, narrowing
 *                 the rows of this type a viewer may see. It runs INSIDE the
 *                 poll's one query, so it must be a where-clause (a whereExists
 *                 on the subject's own visibility scope is ideal), never a
 *                 query of its own.
 * - `extension`   the extension id this type belongs to; the type only exists
 *                 while that extension is enabled.
 * - `name`        a translation key naming the type on the admin page.
 */
final class Type
{
    public function __construct(
        public readonly string $key,
        public readonly string $icon,
        public readonly string $label,
        public readonly ?string $permission = null,
        public readonly ?Closure $scope = null,
        public readonly ?string $extension = null,
        public readonly ?string $name = null,
    ) {
    }

    /** The setting that switches this type on or off. */
    public function settingKey(): string
    {
        return 'ernestdefoe-buzz.type.'.str_replace('.', '_', $this->key);
    }
}
