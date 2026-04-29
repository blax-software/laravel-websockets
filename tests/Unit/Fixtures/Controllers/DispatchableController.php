<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Fixtures\Controllers;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Exercises the various return shapes the dispatcher must handle, plus
 * positional argument resolution from the WS payload.
 */
class DispatchableController
{
    /** Plain array — the simplest case. */
    #[Websocket(suffix: 'array')]
    public function returnsArray(): array
    {
        return ['kind' => 'array', 'ok' => true];
    }

    /** JsonResponse → unwrapped to its `getData(true)` array. */
    #[Websocket(suffix: 'json')]
    public function returnsJson(): JsonResponse
    {
        return new JsonResponse(['kind' => 'json-response', 'ok' => true]);
    }

    /** Plain Response with JSON body → decoded to an array. */
    #[Websocket(suffix: 'response-json-body')]
    public function returnsJsonBodyResponse(): Response
    {
        return new Response(json_encode(['kind' => 'response-json']), 200, ['Content-Type' => 'application/json']);
    }

    /** Plain Response with non-JSON body → returned verbatim as a string. */
    #[Websocket(suffix: 'response-text')]
    public function returnsTextResponse(): Response
    {
        return new Response('plain-text-body');
    }

    /** Receives a positional arg matched by parameter name from $data. */
    #[Websocket(suffix: 'with-arg')]
    public function withArg(string $slug): array
    {
        return ['kind' => 'with-arg', 'slug' => $slug];
    }

    /** Optional default arg — used when caller omits it from $data. */
    #[Websocket(suffix: 'with-default')]
    public function withDefault(string $mode = 'fallback'): array
    {
        return ['kind' => 'with-default', 'mode' => $mode];
    }

    /** Auth-required method. */
    #[Websocket(suffix: 'protected', needAuth: true)]
    public function protectedAction(): array
    {
        return ['kind' => 'protected', 'ok' => true];
    }
}
