<?php

namespace Ernestdefoe\Buzz;

use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Every type of broadcast this forum knows, and which of them are switched on.
 *
 * Asking costs no queries: settings and the enabled-extension list are
 * already in memory, and a viewer's permissions come from groups Flarum
 * loads for every request anyway.
 */
class Types
{
    /** @var array<string, Type> */
    private array $types = [];

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions,
    ) {
    }

    public function add(Type $type): void
    {
        $this->types[$type->key] = $type;
    }

    public function get(string $key): ?Type
    {
        $type = $this->types[$key] ?? null;

        return $type && $this->exists($type) ? $type : null;
    }

    /** @return array<string, Type> every type whose extension is enabled, switched on or not */
    public function all(): array
    {
        return array_filter($this->types, fn (Type $type) => $this->exists($type));
    }

    /** @return array<string, Type> */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (Type $type) => $this->isOn($type));
    }

    /** @return array<string, Type> the switched-on types this viewer may see at all */
    public function visibleTo(User $actor): array
    {
        return array_filter($this->enabled(), fn (Type $type) => $type->permission === null || $actor->hasPermission($type->permission));
    }

    public function isOn(Type $type): bool
    {
        $value = $this->settings->get($type->settingKey());

        return $value === null || $value === '' || (bool) $value;
    }

    private function exists(Type $type): bool
    {
        return $type->extension === null || $this->extensions->isEnabled($type->extension);
    }
}
