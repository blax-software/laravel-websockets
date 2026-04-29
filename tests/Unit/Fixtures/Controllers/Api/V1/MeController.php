<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Fixtures\Controllers\Api\V1;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

/**
 * Folder-aware default: nested under Api\V1, so the auto-derived prefix is
 * "api-v1-me" (mirrors what ControllerResolver would build in reverse).
 */
class MeController
{
    #[Websocket(needAuth: true)]
    public function show(): array
    {
        return ['endpoint' => 'api-v1-me.show'];
    }
}
