<?php

namespace Ernestdefoe\Buzz\Api;

use Ernestdefoe\Buzz\Types;
use Flarum\Frontend\Document;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The admin page's list of types: every one whose extension is enabled, named in the admin's language. */
class AdminPayload
{
    public function __construct(private Types $types, private TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document): void
    {
        $list = [];

        foreach ($this->types->all() as $type) {
            $list[] = [
                'key' => $type->key,
                'setting' => $type->settingKey(),
                'icon' => $type->icon,
                'name' => $this->translator->trans($type->name ?? $type->label),
            ];
        }

        $document->payload['buzzTypes'] = $list;
    }
}
