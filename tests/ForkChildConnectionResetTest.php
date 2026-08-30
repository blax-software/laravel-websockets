<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

/**
 * Regression guard for the fork-child Redis isolation fix (#1445).
 *
 * The per-message fork child must disconnect EVERY configured Redis connection
 * it inherited from the parent — a single missed connection re-opens the predis
 * desync flood ("unserialize(): Error at offset 0 of N bytes"). This pins that
 * resetInheritedConnectionsInChild() disconnects each real connection and skips
 * the non-connection config entries (client / options / anything host-less).
 */
class ForkChildConnectionResetTest extends TestCase
{
    public function test_it_disconnects_every_configured_redis_connection_and_skips_pseudo_entries()
    {
        // A realistic multi-connection layout: two pseudo-entries that are NOT
        // connections (client, options) plus three real ones on their own DBs.
        config(['database.redis' => [
            'client'  => 'predis',
            'options' => ['prefix' => 'test_'],
            'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
            'cache'   => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
            'session' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 2],
        ]]);

        // Record which connections get disconnect()ed, without touching real Redis.
        $recorder = new \ArrayObject();
        $fakeManager = new class($recorder) {
            public function __construct(private \ArrayObject $recorder) {}

            public function connection($name = null)
            {
                return new class($name, $this->recorder) {
                    public function __construct(private ?string $name, private \ArrayObject $recorder) {}

                    public function disconnect(): void
                    {
                        $this->recorder->append($this->name);
                    }
                };
            }
        };
        $this->app->instance('redis', $fakeManager);

        $reset = new \ReflectionMethod($this->wsHandler, 'resetInheritedConnectionsInChild');
        $reset->setAccessible(true);
        $reset->invoke($this->wsHandler);

        $disconnected = $recorder->getArrayCopy();
        sort($disconnected);

        // Every host-bearing connection was reset; client/options were skipped.
        $this->assertSame(['cache', 'default', 'session'], $disconnected);
    }

    public function test_it_does_not_throw_when_redis_is_unresolvable()
    {
        // A broken redis manager must not bubble out of the child reset — the
        // forgetInstance() fallback still forces fresh managers.
        $this->app->instance('redis', new class {
            public function connection($name = null)
            {
                throw new \RuntimeException('redis down');
            }
        });

        $reset = new \ReflectionMethod($this->wsHandler, 'resetInheritedConnectionsInChild');
        $reset->setAccessible(true);

        $reset->invoke($this->wsHandler);

        $this->addToAssertionCount(1); // reached here = no throw
    }
}
