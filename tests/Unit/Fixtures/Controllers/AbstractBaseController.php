<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Fixtures\Controllers;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

/**
 * Abstract classes must NOT register events even if attributes are present.
 */
#[Websocket]
abstract class AbstractBaseController
{
    #[Websocket]
    public function shouldNotRegister(): array
    {
        return [];
    }
}
