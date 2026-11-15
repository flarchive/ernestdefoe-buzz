<?php

namespace Ernestdefoe\Buzz;

use Flarum\Settings\SettingsRepositoryInterface;

/** Buzz's settings, typed and clamped. */
class Settings
{
    public const PREFIX = 'ernestdefoe-buzz.';

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? $default : $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    public function int(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->get($key, $default)));
    }

    /** @return int[] */
    public function ids(string $key): array
    {
        $raw = $this->get($key, '');
        $list = is_string($raw) ? (json_decode($raw, true) ?? explode(',', $raw)) : [];

        return array_values(array_unique(array_filter(array_map('intval', (array) $list))));
    }

    /** Members must switch sharing on before their activity is shown. */
    public function optIn(): bool
    {
        return $this->bool('opt_in');
    }

    public function interval(): int
    {
        return $this->int('interval', 30, 10, 600);
    }

    public function keepHours(): int
    {
        return $this->int('keep_hours', 24, 1, 720);
    }

    public function keepMax(): int
    {
        return $this->int('keep_max', 500, 50, 10000);
    }
}
