<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Fixtures\Controllers;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

/**
 * Class-level attribute: applies prefix + needAuth to every public method.
 * A per-method attribute on `overridden()` re-routes that single method
 * without affecting the rest.
 */
#[Websocket(prefix: 'class-prefixed', needAuth: true)]
class ClassLevelController
{
    public function alpha(): array
    {
        return ['endpoint' => 'class-prefixed.alpha'];
    }

    public function bravo(): array
    {
        return ['endpoint' => 'class-prefixed.bravo'];
    }

    // Per-method override: suffix only — picks up class-level prefix
    #[Websocket(suffix: 'remapped')]
    public function overridden(): array
    {
        return ['endpoint' => 'class-prefixed.remapped'];
    }
}
