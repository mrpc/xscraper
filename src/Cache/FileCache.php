<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgument;

/**
 * Minimal PSR-16 file cache used when the host application does not supply one.
 *
 * Values are stored as JSON (not serialize()), so a tampered cache file can never
 * instantiate objects. Only scalars and arrays are supported, which is all this
 * library stores. Writes are atomic (temp file + rename).
 */
final class FileCache implements CacheInterface
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create cache directory $directory");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->path($key);
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !array_key_exists('v', $entry)) {
            return $default;
        }
        if ($entry['e'] !== null && $entry['e'] <= time()) {
            @unlink($file);
            return $default;
        }
        return $entry['v'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $expires = $this->expiry($ttl);
        if ($expires !== null && $expires <= time()) {
            return $this->delete($key);
        }
        $json = json_encode(['e' => $expires, 'v' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        return @rename($tmp, $file);
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);
        return !is_file($file) || @unlink($file);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }
        return $ok;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    /** @param iterable<string,mixed> $values */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    private function path(string $key): string
    {
        if ($key === '' || preg_match('~[{}()/\\\\@:]~', $key)) {
            throw new class ("Invalid cache key '$key'") extends \InvalidArgumentException implements Psr16InvalidArgument {
            };
        }
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function expiry(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable())->add($ttl)->getTimestamp();
        }
        return time() + $ttl;
    }
}
