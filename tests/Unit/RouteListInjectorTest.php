<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Tests\Unit;

use BlaxSoftware\LaravelWebSockets\Routing\RouteListInjector;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;
use BlaxSoftware\LaravelWebSockets\Websocket\EventRegistry;
use Illuminate\Routing\Router;

class RouteListInjectorTest extends TestCase
{
    private const FIXTURES_NS = 'BlaxSoftware\\LaravelWebSockets\\Test\\Unit\\Fixtures\\Controllers';

    public function setUp(): void
    {
        parent::setUp();

        // Pin the registry to the same fixtures used by EventRegistryTest so
        // we have a deterministic set of attribute-tagged events to merge in.
        EventRegistry::clear();
        EventRegistry::setSearchPaths([
            __DIR__ . '/Fixtures/Controllers' => self::FIXTURES_NS,
        ]);
    }

    public function tearDown(): void
    {
        EventRegistry::clear();
        parent::tearDown();
    }

    /** @test */
    public function it_injects_a_route_per_attribute_tagged_method()
    {
        $router = new Router(app('events'), app());

        RouteListInjector::inject($router);

        $routes = $router->getRoutes();

        // At least one of the fixture events should be present
        $matched = collect($routes)->first(fn($r) => $r->uri() === 'plain.alpha');
        $this->assertNotNull($matched, 'Expected route plain.alpha to be registered');
    }

    /** @test */
    public function injected_routes_use_ws_and_wss_as_methods()
    {
        $router = new Router(app('events'), app());

        RouteListInjector::inject($router);

        $matched = collect($router->getRoutes())->first(fn($r) => $r->uri() === 'plain.alpha');

        $this->assertNotNull($matched);
        $this->assertContains('WS', $matched->methods());
        $this->assertContains('WSS', $matched->methods());

        // No HTTP methods
        $this->assertNotContains('GET', $matched->methods());
        $this->assertNotContains('POST', $matched->methods());
    }

    /** @test */
    public function injected_routes_carry_a_controller_action_string()
    {
        $router = new Router(app('events'), app());

        RouteListInjector::inject($router);

        $matched = collect($router->getRoutes())->first(fn($r) => $r->uri() === 'plain.alpha');

        $this->assertNotNull($matched);
        $action = $matched->getActionName();
        $this->assertSame(
            self::FIXTURES_NS . '\\PlainController@alpha',
            $action
        );
    }

    /** @test */
    public function collect_includes_attribute_tagged_events()
    {
        $events = RouteListInjector::collect();

        $this->assertArrayHasKey('plain.alpha', $events);
        $this->assertArrayHasKey('plain.bravo', $events);
        $this->assertArrayHasKey('api-v1-me.show', $events);
    }

    /** @test */
    public function collect_marks_attribute_sourced_events()
    {
        $events = RouteListInjector::collect();

        $this->assertSame('http-attribute', $events['plain.alpha']['source']);
    }

    /** @test */
    public function collect_propagates_need_auth_for_attribute_targets()
    {
        $events = RouteListInjector::collect();

        $this->assertTrue($events['api-v1-me.show']['needAuth']);
        $this->assertFalse($events['plain.alpha']['needAuth']);
    }

    /** @test */
    public function collect_returns_a_sorted_map()
    {
        $events = RouteListInjector::collect();
        $keys = array_keys($events);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }
}
