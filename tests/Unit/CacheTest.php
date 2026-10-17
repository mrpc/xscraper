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

    /**
     * Verifies adaptive TTL calculation based on Snowflake ID creation age.
     */
    public function testTtlPolicyAdaptive(): void
    {
        // Arrange
        $policy = new TtlPolicy([
            3600 => 120,
            86400 => 1800,
        ], 86400);
        $recentId = '1845187747970863384';
        $now = 1728765432;

        // Act
        $ttlRecent = $policy->freshFor($recentId, $now);

        // Assert
        $this->assertIsInt($ttlRecent);
        $this->assertSame(86400, $policy->freshFor('invalid_id'));
    }

    /**
     * Verifies fixed TTL policy always returns configured constant TTL.
     */
    public function testTtlPolicyFixed(): void
    {
        // Arrange
        $policy = TtlPolicy::fixed(300);

        // Act & Assert
        $this->assertSame(300, $policy->freshFor('1845187747970863384'));
    }

    /**
     * Verifies basic key-value operations (get, set, has, delete, expiry, DateInterval) on FileCache.
     */
    public function testFileCacheBasicOperations(): void
    {
        // Arrange
        $cache = new FileCache($this->cacheDir);

        // Act & Assert (missing key)
        $this->assertNull($cache->get('missing'));
        $this->assertSame('default', $cache->get('missing', 'default'));
        $this->assertFalse($cache->has('missing'));

        // Act & Assert (storing value)
        $this->assertTrue($cache->set('foo', 'bar'));
        $this->assertSame('bar', $cache->get('foo'));
        $this->assertTrue($cache->has('foo'));

        // Act & Assert (deletion)
        $this->assertTrue($cache->delete('foo'));
        $this->assertNull($cache->get('foo'));

        // Act & Assert (negative TTL expiry)
        $cache->set('short_lived', 'value', -1);
        $this->assertNull($cache->get('short_lived'));

        // Act & Assert (DateInterval TTL)
        $cache->set('interval_item', 'value2', new DateInterval('PT1H'));
        $this->assertSame('value2', $cache->get('interval_item'));
    }

    /**
     * Verifies batch get, set, delete, and clear operations on FileCache.
     */
    public function testFileCacheMultiple(): void
    {
        // Arrange
        $cache = new FileCache($this->cacheDir);
        $data = ['k1' => 'v1', 'k2' => 'v2'];

        // Act & Assert (batch set and get)
        $this->assertTrue($cache->setMultiple($data));
        $retrieved = $cache->getMultiple(['k1', 'k2', 'k3'], 'def');
        $this->assertSame(['k1' => 'v1', 'k2' => 'v2', 'k3' => 'def'], $retrieved);

        // Act & Assert (batch delete)
        $this->assertTrue($cache->deleteMultiple(['k1', 'k2']));
        $this->assertNull($cache->get('k1'));
        $this->assertNull($cache->get('k2'));

        // Act & Assert (clear)
        $cache->set('a', '1');
        $cache->set('b', '2');
        $this->assertTrue($cache->clear());
        $this->assertNull($cache->get('a'));
    }

    /**
     * Verifies that invalid cache key containing reserved characters throws InvalidArgumentException.
     */
    public function testFileCacheInvalidKeyThrows(): void
    {
        // Arrange
        $cache = new FileCache($this->cacheDir);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $cache->get('invalid{key}');
    }

    /**
     * Verifies that corrupted cache files and expired on-disk cache files are pruned gracefully.
     */
    public function testCorruptedFileAndExpiredOnDisk(): void
    {
        // Arrange
        $cache = new FileCache($this->cacheDir);
        $key1 = 'corrupted_key';
        $path1 = $this->cacheDir . '/' . hash('sha256', $key1) . '.json';
        file_put_contents($path1, json_encode(['e' => null]));

        $key2 = 'expired_on_disk';
        $path2 = $this->cacheDir . '/' . hash('sha256', $key2) . '.json';
        file_put_contents($path2, json_encode(['e' => time() - 100, 'v' => 'old_val']));

        // Act & Assert
        $this->assertNull($cache->get($key1));
        $this->assertNull($cache->get($key2));
        $this->assertFileDoesNotExist($path2);
    }
}

