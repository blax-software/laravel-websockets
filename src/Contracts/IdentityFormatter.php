<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Contracts;

/**
 * Renders the "who" associated with a websocket connection for admin tooling
 * (`websockets:watch -v`, server-info dumps, debug logs).
 *
 * The package has no opinion on what kind of subject is authenticated against
 * a socket — most apps store an Eloquent User, but a multi-tenant app might
 * authenticate a Company, an api-only app might use an ApiClient, etc.
 * `WebsocketService::setUserAuthed()` accepts whatever object the app passes,
 * so the corresponding formatter is the app's responsibility too.
 *
 * The package ships a `DefaultIdentityFormatter` that handles the common
 * Eloquent-User shape (id / name / username / email). Apps that need
 * different fields, multiple subject types, or custom formatting can bind
 * their own implementation:
 *
 *     // In an app service provider:
 *     $this->app->bind(
 *         \BlaxSoftware\LaravelWebSockets\Contracts\IdentityFormatter::class,
 *         \App\Websockets\MyIdentityFormatter::class,
 *     );
 *
 * Or via config (`config/websockets.php`):
 *
 *     'identity_formatter' => \App\Websockets\MyIdentityFormatter::class,
 */
interface IdentityFormatter
{
    /**
     * Return a human-readable label for the given subject.
     *
     * @param  mixed   $subject   Whatever was cached via setUserAuthed() — an
     *                            Eloquent model, a stdClass, null for guests,
     *                            or an arbitrary value the app stored.
     * @param  string  $socketId  The socket id this subject is attached to.
     *                            Useful if the formatter wants to pull
     *                            additional context from elsewhere.
     */
    public function format(mixed $subject, string $socketId): string;
}
