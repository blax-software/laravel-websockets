<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Tests\Unit;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;
use BlaxSoftware\LaravelWebSockets\Websocket\EventRegistry;

class EventRegistryTest extends TestCase
{
    /**
     * Namespace prefix shared by every fixture under tests/Unit/Fixtures/Controllers.
     * Tests pin this base namespace explicitly so event-prefix derivation is
     * deterministic (independent of where the test runs from).
     */
    private const FIXTURES_NS = 'BlaxSoftware\\LaravelWebSockets\\Test\\Unit\\Fixtures\\Controllers';

    public function setUp(): void
    {
        parent::setUp();
        EventRegistry::clear();
        EventRegistry::setSearchPaths([
            __DIR__ . '/Fixtures/Controllers' => self::FIXTURES_NS,
        ]);
    }

    public function tearDown(): void
    {
        EventRegistry::clear();
        // Reset to default-detection so other tests aren't affected
        EventRegistry::setSearchPaths([
            __DIR__ . '/Fixtures/Controllers' => self::FIXTURES_NS,
        ]);
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────
    // eventPrefixFor() — direct algorithm tests
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_derives_prefix_for_a_flat_controller()
    {
        $this->assertSame(
            'plain',
            EventRegistry::eventPrefixFor(
                self::FIXTURES_NS . '\\PlainController',
                self::FIXTURES_NS . '\\'
            )
        );
    }

    /** @test */
    public function it_derives_folder_aware_prefix_for_a_nested_controller()
    {
        $this->assertSame(
            'api-v1-me',
            EventRegistry::eventPrefixFor(
                self::FIXTURES_NS . '\\Api\\V1\\MeController',
                self::FIXTURES_NS . '\\'
            )
        );
    }

    /** @test */
    public function it_kebabs_multi_word_class_names()
    {
        $this->assertSame(
            'admin-user-settings',
            EventRegistry::eventPrefixFor(
                'App\\Http\\Controllers\\Admin\\UserSettingsController',
                'App\\Http\\Controllers\\'
            )
        );
    }

    /** @test */
    public function it_falls_back_to_short_name_when_base_namespace_does_not_match()
    {
        $this->assertSame(
            'foo',
            EventRegistry::eventPrefixFor(
                'Some\\Other\\FooController',
                'App\\Http\\Controllers\\'
            )
        );
    }

    /** @test */
    public function it_strips_the_controller_suffix()
    {
        $this->assertSame(
            'something',
            EventRegistry::eventPrefixFor(
                'App\\Http\\Controllers\\SomethingController',
                'App\\Http\\Controllers\\'
            )
        );
    }

    /** @test */
    public function it_handles_a_class_named_only_controller_defensively()
    {
        // 'App\\Http\\Controllers\\Controller' → strip 'Controller' from leaf
        // leaves an empty leaf — fall back to a non-empty placeholder.
        $prefix = EventRegistry::eventPrefixFor(
            'App\\Http\\Controllers\\Controller',
            'App\\Http\\Controllers\\'
        );
        $this->assertNotSame('', $prefix);
    }

    // ─────────────────────────────────────────────────────────────────
    // map() — discovery + auto-defaults
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_auto_registers_tagged_methods_with_default_event_names()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('plain.alpha', $map);
        $this->assertArrayHasKey('plain.bravo', $map);
        $this->assertSame(self::FIXTURES_NS . '\\PlainController', $map['plain.alpha']['class']);
        $this->assertSame('alpha', $map['plain.alpha']['method']);
        $this->assertFalse($map['plain.alpha']['needAuth']);
    }

    /** @test */
    public function it_skips_untagged_methods()
    {
        $map = EventRegistry::map();

        $this->assertArrayNotHasKey('plain.charlie', $map);
    }

    /** @test */
    public function it_uses_folder_aware_prefix_for_nested_namespaces()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('api-v1-me.show', $map);
        $this->assertSame(self::FIXTURES_NS . '\\Api\\V1\\MeController', $map['api-v1-me.show']['class']);
        $this->assertSame('show', $map['api-v1-me.show']['method']);
        $this->assertTrue($map['api-v1-me.show']['needAuth']);
    }

    /** @test */
    public function it_skips_abstract_classes()
    {
        $map = EventRegistry::map();

        // AbstractBaseController is tagged but abstract; nothing should resolve to it
        foreach ($map as $event => $target) {
            $this->assertStringNotContainsString('AbstractBaseController', $target['class'], "Abstract class leaked via event '{$event}'");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Override behavior: prefix, suffix, event
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_uses_default_event_name_when_no_arguments_given()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('override.defaulted', $map);
    }

    /** @test */
    public function it_honors_a_prefix_only_override()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('custom-prefix.prefixed', $map);
        $this->assertArrayNotHasKey('override.prefixed', $map, 'Auto-prefix should NOT also register when prefix: is overridden');
        $this->assertSame('prefixed', $map['custom-prefix.prefixed']['method']);
    }

    /** @test */
    public function it_honors_a_suffix_only_override()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('override.custom-suffix', $map);
        $this->assertArrayNotHasKey('override.suffixed', $map, 'Default method-name suffix should NOT also register when suffix: is overridden');
        $this->assertSame('suffixed', $map['override.custom-suffix']['method'], 'PHP method name preserved even when WS suffix differs');
    }

    /** @test */
    public function it_honors_prefix_and_suffix_combined()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('pre.post', $map);
        $this->assertSame('bothOverridden', $map['pre.post']['method']);
    }

    /** @test */
    public function the_event_argument_wins_over_prefix_and_suffix()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('totally.custom', $map);
        $this->assertArrayNotHasKey('ignored.ignored', $map);
        $this->assertArrayNotHasKey('ignored.fullOverride', $map);
        $this->assertSame('fullOverride', $map['totally.custom']['method']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Class-level attribute
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function class_level_attribute_applies_to_every_public_method()
    {
        $map = EventRegistry::map();

        $this->assertArrayHasKey('class-prefixed.alpha', $map);
        $this->assertArrayHasKey('class-prefixed.bravo', $map);
    }

    /** @test */
    public function class_level_need_auth_propagates_to_every_method()
    {
        $map = EventRegistry::map();

        $this->assertTrue($map['class-prefixed.alpha']['needAuth']);
        $this->assertTrue($map['class-prefixed.bravo']['needAuth']);
    }

    /** @test */
    public function method_level_attribute_overrides_class_level_for_that_method_only()
    {
        $map = EventRegistry::map();

        // Only the suffix differs — prefix is inherited from class-level
        $this->assertArrayHasKey('class-prefixed.remapped', $map);
        $this->assertArrayNotHasKey('class-prefixed.overridden', $map, 'Method-level override must replace, not duplicate');

        // Other methods on the same class still use the default suffix
        $this->assertArrayHasKey('class-prefixed.alpha', $map);
    }

    // ─────────────────────────────────────────────────────────────────
    // resolve(), clear(), setSearchPaths()
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function resolve_returns_null_for_unknown_events()
    {
        $this->assertNull(EventRegistry::resolve('this.does-not-exist'));
    }

    /** @test */
    public function resolve_returns_target_for_known_events()
    {
        $target = EventRegistry::resolve('plain.alpha');

        $this->assertNotNull($target);
        $this->assertSame(self::FIXTURES_NS . '\\PlainController', $target['class']);
        $this->assertSame('alpha', $target['method']);
        $this->assertFalse($target['needAuth']);
    }

    /** @test */
    public function clear_invalidates_the_cache()
    {
        // Build the map once to populate the cache
        EventRegistry::map();

        // Re-point search paths to a non-existent directory and clear
        EventRegistry::clear();
        EventRegistry::setSearchPaths(['/nonexistent/path/that/does/not/exist' => 'App\\']);

        // Map should now be empty (no fixtures discoverable from the bogus path)
        $this->assertSame([], EventRegistry::map());
    }

    /** @test */
    public function setSearchPaths_supports_explicit_path_to_namespace_map()
    {
        EventRegistry::clear();
        EventRegistry::setSearchPaths([
            __DIR__ . '/Fixtures/Controllers' => self::FIXTURES_NS,
        ]);

        $this->assertNotEmpty(EventRegistry::map());
    }

    /** @test */
    public function the_attribute_constructor_defaults_are_all_null_or_false()
    {
        $attr = new Websocket();

        $this->assertNull($attr->event);
        $this->assertNull($attr->prefix);
        $this->assertNull($attr->suffix);
        $this->assertFalse($attr->needAuth);
    }

    /** @test */
    public function the_attribute_accepts_named_arguments()
    {
        $attr = new Websocket(event: 'a.b', prefix: 'a', suffix: 'b', needAuth: true);

        $this->assertSame('a.b', $attr->event);
        $this->assertSame('a', $attr->prefix);
        $this->assertSame('b', $attr->suffix);
        $this->assertTrue($attr->needAuth);
    }
}
