<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Fixtures\Controllers;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

/**
 * Default-everything fixture: leaf class, no overrides.
 * Expected event names with default scan (base = .../Fixtures/Controllers):
 *   plain.alpha
 *   plain.bravo
 */
class PlainController
{
    #[Websocket]
    public function alpha(): array
    {
        return ['endpoint' => 'plain.alpha'];
    }

    #[Websocket]
    public function bravo(string $id): array
    {
        return ['endpoint' => 'plain.bravo', 'id' => $id];
    }

    // Untagged — must NOT appear in the registry
    public function charlie(): array
    {
        return ['endpoint' => 'plain.charlie'];
    }
}
