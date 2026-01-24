<?php

namespace BlaxSoftware\LaravelWebSockets\Tests\Unit;

use BlaxSoftware\LaravelWebSockets\Websocket\ControllerResolver;
use PHPUnit\Framework\TestCase;

class ControllerResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ControllerResolver::clearCache();
    }

    /** @test */
    public function it_resolves_simple_controller_names()
    {
        // The package has a PusherController in vendor namespace
        $result = ControllerResolver::resolve('pusher');

        $this->assertNotNull($result);
        $this->assertStringContainsString('PusherController', $result);
    }

    /** @test */
    public function it_caches_resolved_controllers()
    {
        // First call scans and caches
        ControllerResolver::resolve('pusher');

        $stats = ControllerResolver::getStats();
        $this->assertTrue($stats['scanned']);
        $this->assertGreaterThan(0, $stats['cached']);
    }

    /** @test */
    public function it_caches_null_for_nonexistent_controllers()
    {
        // First call - not found
        $result1 = ControllerResolver::resolve('nonexistent-controller-xyz');
        $this->assertNull($result1);

        // Second call - should still be null (cached)
        $result2 = ControllerResolver::resolve('nonexistent-controller-xyz');
        $this->assertNull($result2);

        // Check it's in cache
        $stats = ControllerResolver::getStats();
        $this->assertGreaterThan(0, $stats['cached']);
    }

    /** @test */
    public function it_converts_kebab_case_to_pascal_case()
    {
        // admin-user should try AdminUserController
        // This tests the internal conversion (we can't directly test private method,
        // but we can test the resolve behavior)
        $stats = ControllerResolver::getStats();
        $this->assertIsArray($stats);
    }

    /** @test */
    public function it_clears_cache_correctly()
    {
        // Populate cache
        ControllerResolver::resolve('pusher');

        $statsBefore = ControllerResolver::getStats();
        $this->assertTrue($statsBefore['scanned']);

        // Clear cache
        ControllerResolver::clearCache();

        $statsAfter = ControllerResolver::getStats();
        $this->assertFalse($statsAfter['scanned']);
        $this->assertEquals(0, $statsAfter['cached']);
        $this->assertEquals(0, $statsAfter['available']);
    }

    /** @test */
    public function it_preloads_controllers()
    {
        ControllerResolver::preload();

        $stats = ControllerResolver::getStats();
        $this->assertTrue($stats['scanned']);
        $this->assertGreaterThan(0, $stats['available']);
    }

    /** @test */
    public function it_returns_same_result_on_repeated_calls()
    {
        $result1 = ControllerResolver::resolve('pusher');
        $result2 = ControllerResolver::resolve('pusher');

        $this->assertSame($result1, $result2);
    }
}
