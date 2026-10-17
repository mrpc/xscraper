<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use DateInterval;
use Mrpc\XScraper\Cache\FileCache;
use Mrpc\XScraper\Cache\TtlPolicy;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;

final class CacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/xscraper_cache_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function testTtlPolicyAdaptive(): void
    {
        $policy = new TtlPolicy([
            3600 => 120,
            86400 => 1800,
        ], 86400);

        // Tweet ID for a very recent post vs older post
        $recentId = '1845187747970863384';
        $now = 1728765432;
        $ttlRecent = $policy->freshFor($recentId, $now);
        $this->assertIsInt($ttlRecent);

        // Invalid tweet id falls back to oldest
        $this->assertSame(86400, $policy->freshFor('invalid_id'));
    }

    public function testTtlPolicyFixed(): void
    {
        $policy = TtlPolicy::fixed(300);
        $this->assertSame(300, $policy->freshFor('1845187747970863384'));
    }

    public function testFileCacheBasicOperations(): void
    {
        $cache = new FileCache($this->cacheDir);

        $this->assertNull($cache->get('missing'));
        $this->assertSame('default', $cache->get('missing', 'default'));
        $this->assertFalse($cache->has('missing'));

        $this->assertTrue($cache->set('foo', 'bar'));
        $this->assertSame('bar', $cache->get('foo'));
        $this->assertTrue($cache->has('foo'));

        // Delete
        $this->assertTrue($cache->delete('foo'));
        $this->assertNull($cache->get('foo'));

        // Expiry
        $cache->set('short_lived', 'value', -1);
        $this->assertNull($cache->get('short_lived'));

        $cache->set('interval_item', 'value2', new DateInterval('PT1H'));
        $this->assertSame('value2', $cache->get('interval_item'));
    }

    public function testFileCacheMultiple(): void
    {
        $cache = new FileCache($this->cacheDir);

        $data = ['k1' => 'v1', 'k2' => 'v2'];
        $this->assertTrue($cache->setMultiple($data));

        $retrieved = $cache->getMultiple(['k1', 'k2', 'k3'], 'def');
        $this->assertSame(['k1' => 'v1', 'k2' => 'v2', 'k3' => 'def'], $retrieved);

        $this->assertTrue($cache->deleteMultiple(['k1', 'k2']));
        $this->assertNull($cache->get('k1'));
        $this->assertNull($cache->get('k2'));

        // Clear
        $cache->set('a', '1');
        $cache->set('b', '2');
        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get('a'));
    }

    public function testFileCacheInvalidKeyThrows(): void
    {
        $cache = new FileCache($this->cacheDir);
        $this->expectException(InvalidArgumentException::class);
        $cache->get('invalid{key}');
    }

    public function testCorruptedFileAndExpiredOnDisk(): void
    {
        $cache = new FileCache($this->cacheDir);

        // 1. Corrupted file missing 'v'
        $key1 = 'corrupted_key';
        $path1 = $this->cacheDir . '/' . hash('sha256', $key1) . '.json';
        file_put_contents($path1, json_encode(['e' => null]));
        $this->assertNull($cache->get($key1));

        // 2. Expired file on disk
        $key2 = 'expired_on_disk';
        $path2 = $this->cacheDir . '/' . hash('sha256', $key2) . '.json';
        file_put_contents($path2, json_encode(['e' => time() - 100, 'v' => 'old_val']));
        $this->assertNull($cache->get($key2));
        $this->assertFileDoesNotExist($path2); // Should have unlinked
    }
}
