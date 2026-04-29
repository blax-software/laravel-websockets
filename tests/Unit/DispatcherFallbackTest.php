<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Tests\Unit;

use BlaxSoftware\LaravelWebSockets\ChannelManagers\LocalChannelManager;
use BlaxSoftware\LaravelWebSockets\Channels\Channel;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;
use BlaxSoftware\LaravelWebSockets\Test\Unit\Support\RecordingConnection;
use BlaxSoftware\LaravelWebSockets\Websocket\Controller;
use BlaxSoftware\LaravelWebSockets\Websocket\ControllerResolver;
use BlaxSoftware\LaravelWebSockets\Websocket\EventRegistry;
use React\EventLoop\Factory as LoopFactory;

/**
 * End-to-end tests for the EventRegistry → HTTP-controller fallback wired
 * into {@see Controller::controll_message()}. The flow under test:
 *
 *   ControllerResolver::resolve($prefix) === null
 *      ↓
 *   EventRegistry::resolve($eventName) → {class, method, needAuth}
 *      ↓
 *   Controller::dispatchHttpAttributeTarget()
 *      ↓
 *   payload normalized + $connection->send(...)
 */
class DispatcherFallbackTest extends TestCase
{
    private const FIXTURES_NS = 'BlaxSoftware\\LaravelWebSockets\\Test\\Unit\\Fixtures\\Controllers';

    private LocalChannelManager $localChannelManager;
    private Channel $testChannel;

    public function setUp(): void
    {
        parent::setUp();

        ControllerResolver::clearCache();
        EventRegistry::clear();
        EventRegistry::setSearchPaths([
            __DIR__ . '/Fixtures/Controllers' => self::FIXTURES_NS,
        ]);

        $this->localChannelManager = new LocalChannelManager(LoopFactory::create());
        $this->testChannel = new Channel('test-channel');
    }

    public function tearDown(): void
    {
        EventRegistry::clear();
        EventRegistry::setSearchPaths([
            __DIR__ . '/Fixtures/Controllers' => self::FIXTURES_NS,
        ]);
        parent::tearDown();
    }

    /**
     * Build a WS message envelope.
     *
     * @param array<string, mixed> $data
     */
    private function message(string $event, array $data = []): array
    {
        return [
            'event' => $event,
            'data' => $data,
            'channel' => 'test-channel',
        ];
    }

    /**
     * Run a full dispatch through `controll_message` and return both the
     * direct return value and the recorded `send()` payload.
     *
     * @return array{return: mixed, sent: mixed, connection: RecordingConnection}
     */
    private function dispatch(array $message, ?object $user = null): array
    {
        $connection = new RecordingConnection();
        $connection->user = $user;

        $return = Controller::controll_message($connection, $this->testChannel, $message, $this->localChannelManager);

        return [
            'return' => $return,
            'sent' => $connection->lastPayload(),
            'connection' => $connection,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Fallback path
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_routes_to_a_registered_http_controller_when_resolver_misses()
    {
        $result = $this->dispatch($this->message('dispatchable.array'));

        $this->assertSame(['kind' => 'array', 'ok' => true], $result['return']);
        $this->assertSame('dispatchable.array:response', $result['sent']['event']);
        $this->assertSame(['kind' => 'array', 'ok' => true], $result['sent']['data']);
        $this->assertSame('test-channel', $result['sent']['channel']);
    }

    /** @test */
    public function it_strips_the_client_side_uniquifier_before_registry_lookup()
    {
        // Real-world client-side wrappers append a per-request uniquifier in
        // square brackets (e.g. `dispatchable.array[abc123]`) so the response
        // can be correlated. The dispatcher must strip that before looking
        // up the registry — otherwise every WS client request would 404 the
        // registry path even though the underlying event is well-formed.
        $result = $this->dispatch($this->message('dispatchable.array[abc123]'));

        $this->assertSame(['kind' => 'array', 'ok' => true], $result['return']);
        // The :response envelope echoes back the ORIGINAL event name (with
        // uniquifier) so clients can match the response to the request.
        $this->assertSame('dispatchable.array[abc123]:response', $result['sent']['event']);
        $this->assertSame(['kind' => 'array', 'ok' => true], $result['sent']['data']);
    }

    /** @test */
    public function it_falls_through_to_the_send_error_when_neither_resolver_nor_registry_matches()
    {
        $result = $this->dispatch($this->message('not-a-real-thing.show'));

        // send_error returns null and pushes an error envelope
        $this->assertNull($result['return']);
        $this->assertNotNull($result['sent']);
        $this->assertArrayHasKey('event', $result['sent']);
        $this->assertSame('not-a-real-thing.show:error', $result['sent']['event']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Response normalization
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_unwraps_a_json_response_payload()
    {
        $result = $this->dispatch($this->message('dispatchable.json'));

        $this->assertSame(['kind' => 'json-response', 'ok' => true], $result['sent']['data']);
    }

    /** @test */
    public function it_decodes_a_plain_response_with_json_body_into_an_array()
    {
        $result = $this->dispatch($this->message('dispatchable.response-json-body'));

        $this->assertSame(['kind' => 'response-json'], $result['sent']['data']);
    }

    /** @test */
    public function it_passes_through_a_plain_response_with_text_body()
    {
        $result = $this->dispatch($this->message('dispatchable.response-text'));

        $this->assertSame('plain-text-body', $result['sent']['data']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Argument resolution (positional from $data)
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_resolves_positional_arguments_by_parameter_name()
    {
        $result = $this->dispatch(
            $this->message('dispatchable.with-arg', ['slug' => 'hello-world'])
        );

        $this->assertSame(['kind' => 'with-arg', 'slug' => 'hello-world'], $result['sent']['data']);
    }

    /** @test */
    public function it_uses_default_argument_value_when_data_is_missing()
    {
        $result = $this->dispatch(
            $this->message('dispatchable.with-default', /* no mode key */)
        );

        $this->assertSame(['kind' => 'with-default', 'mode' => 'fallback'], $result['sent']['data']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Auth gating
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_blocks_a_protected_method_for_an_unauthenticated_connection()
    {
        $result = $this->dispatch($this->message('dispatchable.protected'));

        // No user attached → Unauthorized
        $this->assertSame('dispatchable.protected:error', $result['sent']['event']);
        $this->assertStringContainsString('Unauthorized', json_encode($result['sent']['data']));
    }

    /** @test */
    public function it_allows_a_protected_method_for_an_authenticated_connection()
    {
        // Any object with at least the shape Controller::dispatchHttpAttributeTarget() reads
        $fakeUser = new \stdClass();
        $fakeUser->id = 42;

        $result = $this->dispatch($this->message('dispatchable.protected'), $fakeUser);

        $this->assertSame(['kind' => 'protected', 'ok' => true], $result['sent']['data']);
        $this->assertSame('dispatchable.protected:response', $result['sent']['event']);
    }

    // ─────────────────────────────────────────────────────────────────
    // resolveAttributeMethodArgs (via reflection — covers edge cases the
    // public dispatch test would only exercise indirectly).
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function resolveAttributeMethodArgs_picks_values_by_name()
    {
        $args = $this->callResolveArgs('withArg', ['slug' => 'foo', 'extra' => 'ignored']);

        $this->assertSame(['foo'], $args);
    }

    /** @test */
    public function resolveAttributeMethodArgs_falls_back_to_default_value()
    {
        $args = $this->callResolveArgs('withDefault', []);

        $this->assertSame(['fallback'], $args);
    }

    /** @test */
    public function resolveAttributeMethodArgs_breaks_at_required_missing_param()
    {
        // `withArg(string $slug)` — required, no default, not nullable → return [] so
        // PHP's own ArgumentCountError surfaces during the actual invocation
        $args = $this->callResolveArgs('withArg', []);

        $this->assertSame([], $args);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    private function callResolveArgs(string $method, array $data): array
    {
        $reflection = new \ReflectionMethod(Controller::class, 'resolveAttributeMethodArgs');
        $reflection->setAccessible(true);

        return $reflection->invoke(
            null,
            self::FIXTURES_NS . '\\DispatchableController',
            $method,
            $data
        );
    }
}
