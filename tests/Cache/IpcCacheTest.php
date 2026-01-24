<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Cache;

use BlaxSoftware\LaravelWebSockets\Cache\IpcCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IpcCache.
 *
 * IpcCache is a fast RAM-backed cache using /dev/shm for IPC between
 * forked processes.
 */
class IpcCacheTest extends TestCase
{
    private string $testKey;

    protected function setUp(): void
    {
        parent::setUp();
        // Use unique key per test to avoid conflicts
        $this->testKey = 'test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        IpcCache::forget($this->testKey);
        parent::tearDown();
    }

    public function test_it_can_check_tmpfs_availability()
    {
        $isTmpfs = IpcCache::isTmpfs();

        $this->assertIsBool($isTmpfs);

        // On Linux with /dev/shm, should be true
        if (is_dir('/dev/shm') && is_writable('/dev/shm')) {
            $this->assertTrue($isTmpfs);
        }
    }

    public function test_it_can_store_and_retrieve_data()
    {
        $testData = ['event' => 'test', 'data' => 'hello'];

        IpcCache::put($this->testKey, $testData);

        $this->assertTrue(IpcCache::has($this->testKey));
        $this->assertEquals($testData, IpcCache::get($this->testKey));
    }

    public function test_it_returns_null_for_missing_key()
    {
        $result = IpcCache::get('nonexistent_key_' . uniqid());

        $this->assertNull($result);
    }

    public function test_it_can_forget_a_key()
    {
        IpcCache::put($this->testKey, 'data');
        $this->assertTrue(IpcCache::has($this->testKey));

        IpcCache::forget($this->testKey);

        $this->assertFalse(IpcCache::has($this->testKey));
    }

    public function test_it_can_forget_multiple_keys()
    {
        $keys = [
            $this->testKey . '_a',
            $this->testKey . '_b',
            $this->testKey . '_c',
        ];

        foreach ($keys as $key) {
            IpcCache::put($key, 'data');
        }

        foreach ($keys as $key) {
            $this->assertTrue(IpcCache::has($key));
        }

        IpcCache::forgetMultiple($keys);

        foreach ($keys as $key) {
            $this->assertFalse(IpcCache::has($key));
        }
    }

    public function test_it_can_store_complex_data()
    {
        $complexData = [
            'event' => 'pusher:connection_established',
            'data' => [
                'socket_id' => '123.456',
                'activity_timeout' => 120,
                'nested' => [
                    'deep' => [
                        'value' => true,
                    ],
                ],
            ],
        ];

        IpcCache::put($this->testKey, $complexData);

        $retrieved = IpcCache::get($this->testKey);

        $this->assertEquals($complexData, $retrieved);
        $this->assertEquals(true, $retrieved['data']['nested']['deep']['value']);
    }

    public function test_it_can_store_strings()
    {
        $jsonString = '{"event":"test","channel":"my-channel"}';

        IpcCache::put($this->testKey, $jsonString);

        $this->assertEquals($jsonString, IpcCache::get($this->testKey));
    }

    public function test_it_works_across_fork()
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $parentKey = $this->testKey . '_parent';
        $childKey = $this->testKey . '_child';

        // Parent writes first
        IpcCache::put($parentKey, 'from_parent');

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            // Should be able to read parent's data
            $parentData = IpcCache::get($parentKey);

            // Write child's data
            IpcCache::put($childKey, 'from_child_saw_' . $parentData);

            exit(0);
        }

        // Parent waits for child
        pcntl_waitpid($pid, $status);

        // Parent should see child's data
        $childData = IpcCache::get($childKey);

        $this->assertEquals('from_child_saw_from_parent', $childData);

        // Cleanup
        IpcCache::forget($parentKey);
        IpcCache::forget($childKey);
    }

    public function test_it_handles_special_characters_in_keys()
    {
        $specialKey = $this->testKey . ':with:colons';
        $data = 'test_data';

        IpcCache::put($specialKey, $data);

        $this->assertTrue(IpcCache::has($specialKey));
        $this->assertEquals($data, IpcCache::get($specialKey));

        IpcCache::forget($specialKey);
    }

    public function test_it_measures_performance()
    {
        $iterations = 100;

        // Measure write performance
        $writeStart = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            IpcCache::put($this->testKey . '_perf_' . $i, ['iteration' => $i]);
        }
        $writeTime = (microtime(true) - $writeStart) * 1000;

        // Measure read performance
        $readStart = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            IpcCache::get($this->testKey . '_perf_' . $i);
        }
        $readTime = (microtime(true) - $readStart) * 1000;

        // Cleanup
        for ($i = 0; $i < $iterations; $i++) {
            IpcCache::forget($this->testKey . '_perf_' . $i);
        }

        $avgWriteMs = $writeTime / $iterations;
        $avgReadMs = $readTime / $iterations;

        // Should be fast (< 1ms per operation on tmpfs)
        $this->assertLessThan(5, $avgWriteMs, "Avg write: {$avgWriteMs}ms");
        $this->assertLessThan(5, $avgReadMs, "Avg read: {$avgReadMs}ms");

        // Log for visibility
        fwrite(STDERR, "\n  [Write avg: " . round($avgWriteMs, 3) . "ms, Read avg: " . round($avgReadMs, 3) . "ms] ");
    }
}
