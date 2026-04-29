<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Fixtures\Controllers;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

/**
 * Exercises every override: `prefix`, `suffix`, and the full `event` override.
 */
class OverrideController
{
    // Default: derived prefix `override`, method name `defaulted`
    #[Websocket]
    public function defaulted(): array
    {
        return ['endpoint' => 'override.defaulted'];
    }

    // prefix override only — keeps method name as suffix
    #[Websocket(prefix: 'custom-prefix')]
    public function prefixed(): array
    {
        return ['endpoint' => 'custom-prefix.prefixed'];
    }

    // suffix override only — keeps derived prefix
    #[Websocket(suffix: 'custom-suffix')]
    public function suffixed(): array
    {
        return ['endpoint' => 'override.custom-suffix'];
    }

    // both overrides combined
    #[Websocket(prefix: 'pre', suffix: 'post')]
    public function bothOverridden(): array
    {
        return ['endpoint' => 'pre.post'];
    }

    // full event string — wins over both prefix and suffix
    #[Websocket(event: 'totally.custom', prefix: 'ignored', suffix: 'ignored')]
    public function fullOverride(): array
    {
        return ['endpoint' => 'totally.custom'];
    }
}
