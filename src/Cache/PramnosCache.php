<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Cache;

use DateInterval;
use DateTimeImmutable;
use Pramnos\Cache\Cache;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/**
 * PSR-16 adapter wrapping PramnosFramework's native `Pramnos\Cache\Cache` engine.
 *
 * Allows xscraper to share the host application's central cache store (Redis, Memcached,
 * or local filesystem) when running inside PramnosFramework.
 */
final class PramnosCache implements CacheInterface
{
    private const RESERVED_CHARS = '{}()/\\@:';

    public function __construct(private ?Cache $cache = null)
    {
        if (!class_exists(Cache::class)) {
            throw new \LogicException('mrpc/pramnosframework is not installed');
        }
        $this->cache ??= Cache::getInstance();
    }

    public static function default(): self
    {
        return new self();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $value = $this->cache->load($key);
        return $value !== null && $value !== false ? $value : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $ttlSeconds = $this->normalizeTtl($ttl);

        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            return $this->delete($key);
        }

        // Cache::save($data, $file, $category, $timeout)
        return (bool) $this->cache->save($value, $key, '', $ttlSeconds ?? 0);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return (bool) $this->cache->delete($key);
    }

    public function clear(): bool
    {
        $this->cache->clear();
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get((string) $key, $default);
        }
        return $out;
    }

    /** @param iterable<string,mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->delete((string) $key)) {
                $ok = false;
            }
        }
        return $ok;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        $value = $this->cache->load($key);
        return $value !== null && $value !== false;
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new class ('Cache key must not be empty') extends \InvalidArgumentException implements Psr16InvalidArgument {};
        }
        if (strpbrk($key, self::RESERVED_CHARS) !== false) {
            throw new class ("Cache key '{$key}' contains reserved characters: " . self::RESERVED_CHARS) extends \InvalidArgumentException implements Psr16InvalidArgument {};
        }
    }

    private function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $future = $now->add($ttl);
            return max(0, $future->getTimestamp() - $now->getTimestamp());
        }
        return max(0, $ttl);
    }
}
