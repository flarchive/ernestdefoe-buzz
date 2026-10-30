<?php

namespace Ernestdefoe\Buzz\Extend;

use Closure;
use Ernestdefoe\Buzz\Type;
use Ernestdefoe\Buzz\Types;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * Lets any extension add its own kind of broadcast.
 *
 *     (new \Ernestdefoe\Buzz\Extend\Buzz())
 *         ->type('acme.photo', 'fas fa-camera', 'acme-photos.forum.buzz.photo', [
 *             'name' => 'acme-photos.admin.buzz.photo',   // admin switch label (optional)
 *             'permission' => 'acme.viewPhotos',           // viewers need this (optional)
 *             'scope' => fn ($query, $actor) => ...,       // one where-clause per viewer (optional)
 *         ]);
 *
 * Record one with `resolve(\Ernestdefoe\Buzz\Recorder::class)->record(...)`.
 * Put the extender inside `Extend\Conditional::whenExtensionEnabled('ernestdefoe-buzz', ...)`
 * so your extension works without Buzz.
 */
class Buzz implements ExtenderInterface
{
    /** @var list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}> */
    private array $types = [];

    /**
     * @param array{name?: string, permission?: string, scope?: callable, extension?: string} $options
     */
    public function type(string $key, string $icon, string $label, array $options = []): self
    {
        $this->types[] = [$key, $icon, $label, $options];

        return $this;
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $types = $this->types;
        $owner = $extension?->getId();

        $container->resolving(Types::class, function (Types $registry) use ($types, $owner) {
            foreach ($types as [$key, $icon, $label, $options]) {
                $registry->add(new Type(
                    key: $key,
                    icon: $icon,
                    label: $label,
                    permission: $options['permission'] ?? null,
                    scope: isset($options['scope']) ? Closure::fromCallable($options['scope']) : null,
                    extension: $options['extension'] ?? ($owner === 'ernestdefoe-buzz' ? null : $owner),
                    name: $options['name'] ?? null,
                ));
            }
        });
    }
}
