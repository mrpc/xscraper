<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

require_once __DIR__ . '/../Support/PramnosStub.php';

use DateInterval;
use Mrpc\XScraper\Cache\PramnosCache;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Psr\SimpleCache\InvalidArgumentException;

final class PramnosCacheTest extends TestCase
{
    private Cache $rawCache;
    private PramnosCache $cache;

    protected function setUp(): void
    {
        $this->rawCache = new Cache();
        $this->cache = new PramnosCache($this->rawCache);
    }

    /**
     * Verifies get, set, has, and delete operations on single keys.
     */
    public function testBasicKeyValueOperations(): void
    {
        // Arrange
        $key = 'test_key';
        $value = ['data' => 123];

        // Act & Assert (initial missing state)
        $this->assertNull($this->cache->get($key));
        $this->assertSame('fallback', $this->cache->get($key, 'fallback'));
        $this->assertFalse($this->cache->has($key));

        // Act (storing value)
        $setOk = $this->cache->set($key, $value, 3600);

        // Assert (stored state)
        $this->assertTrue($setOk);
        $this->assertSame($value, $this->cache->get($key));
        $this->assertTrue($this->cache->has($key));

        // Act (deletion)
        $delOk = $this->cache->delete($key);

        // Assert (post-delete state)
        $this->assertTrue($delOk);
        $this->assertNull($this->cache->get($key));
    }

    /**
     * Verifies batch getMultiple, setMultiple, and deleteMultiple operations.
     */
    public function testBatchOperations(): void
    {
        // Arrange
        $items = ['item_a' => 'val_a', 'item_b' => 'val_b'];

        // Act
        $setResult = $this->cache->setMultiple($items, 600);
        $retrieved = $this->cache->getMultiple(['item_a', 'item_b', 'item_c'], 'default_c');

        // Assert
        $this->assertTrue($setResult);
        $this->assertSame(['item_a' => 'val_a', 'item_b' => 'val_b', 'item_c' => 'default_c'], $retrieved);

        // Act (batch delete)
        $delResult = $this->cache->deleteMultiple(['item_a', 'item_b']);

        // Assert
        $this->assertTrue($delResult);
        $this->assertNull($this->cache->get('item_a'));
        $this->assertNull($this->cache->get('item_b'));
    }

    /**
     * Verifies that clearing the cache empties all keys.
     */
    public function testClearCache(): void
    {
        // Arrange
        $this->cache->set('one', 1);
        $this->cache->set('two', 2);

        // Act
        $clearResult = $this->cache->clear();

        // Assert
        $this->assertTrue($clearResult);
        $this->assertNull($this->cache->get('one'));
        $this->assertNull($this->cache->get('two'));
    }

    /**
     * Verifies that setting a TTL <= 0 immediately deletes the key.
     */
    public function testTtlZeroOrNegativeDeletes(): void
    {
        // Arrange
        $this->cache->set('ephemeral', 'data');

        // Act
        $this->cache->set('ephemeral', 'new_data', 0);

        // Assert
        $this->assertNull($this->cache->get('ephemeral'));
    }

    /**
     * Verifies DateInterval TTL support.
     */
    public function testDateIntervalTtl(): void
    {
        // Arrange
        $interval = new DateInterval('PT30M');

        // Act
        $ok = $this->cache->set('interval_key', 'hello', $interval);

        // Assert
        $this->assertTrue($ok);
        $this->assertSame('hello', $this->cache->get('interval_key'));
    }

    /**
     * Verifies that invalid cache keys throw PSR-16 compliant InvalidArgumentException.
     */
    public function testInvalidKeyThrowsException(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $this->cache->get('invalid:key:with:colon');
    }

    /**
     * Verifies that empty cache key throws InvalidArgumentException.
     */
    public function testEmptyKeyThrowsException(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $this->cache->get('');
    }

    /**
     * Verifies static factory instantiation with default Cache instance.
     */
    public function testDefaultFactory(): void
    {
        // Act
        $instance = PramnosCache::default();

        // Assert
        $this->assertInstanceOf(PramnosCache::class, $instance);
    }

    /**
     * Verifies that setMultiple returns false when underlying cache save fails.
     */
    public function testSetMultipleReturnsFalseOnSaveFailure(): void
    {
        // Arrange
        $this->rawCache->saveResult = false;

        // Act
        $result = $this->cache->setMultiple(['key1' => 'val1']);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Verifies that deleteMultiple returns false when underlying cache delete fails.
     */
    public function testDeleteMultipleReturnsFalseOnDeleteFailure(): void
    {
        // Arrange
        $this->rawCache->deleteResult = false;

        // Act
        $result = $this->cache->deleteMultiple(['key1']);

        // Assert
        $this->assertFalse($result);
    }
}

