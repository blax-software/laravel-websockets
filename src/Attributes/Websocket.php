<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Attributes;

/**
 * Marks an HTTP controller method (or whole controller class) as ALSO
 * reachable via the websocket dispatcher.
 *
 * Default event-name resolution mirrors the existing
 * {@see \BlaxSoftware\LaravelWebSockets\Websocket\ControllerResolver}:
 *
 *   App\Http\Controllers\Api\V1\FlightschoolController::index
 *     → controller prefix derived from PascalCase → kebab-case ("flightschool")
 *     → event name "flightschool.index"
 *
 * Override either piece by passing the named arguments:
 *
 *   #[Websocket(event: 'flightschools.list')]
 *   public function index() { ... }
 *
 *   #[Websocket(prefix: 'flightschool-guest')]
 *   public function index() { ... }   // → 'flightschool-guest.index'
 *
 * The actual websocket dispatch wiring lives in
 * {@see \BlaxSoftware\LaravelWebSockets\Websocket\Controller::handle()} —
 * the {@see \BlaxSoftware\LaravelWebSockets\Websocket\EventRegistry}
 * provides the event → callable map that the dispatcher consults as a
 * fallback when no `App\Websocket\Controllers\` match is found.
 *
 * Class-level usage applies the same prefix to every public method on the
 * controller (each becomes its own event name `prefix.methodName`).
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Websocket
{
    public function __construct(
        /**
         * Full event name, e.g. "flightschool.index". Wins over `prefix`.
         */
        public ?string $event = null,

        /**
         * Event prefix override (the part before the ".") — useful when the
         * controller's class name doesn't match the desired event prefix.
         * The method name supplies the suffix.
         */
        public ?string $prefix = null,

        /**
         * Whether the method requires an authenticated websocket connection.
         * Mirrors the `$need_auth` property on existing WS controllers.
         */
        public bool $needAuth = false,
    ) {}
}
