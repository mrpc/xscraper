<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Lock;

use Mrpc\XScraper\Exception\LockTimeoutException;

/**
 * Cross-process mutex based on flock().
 *
 * Used for single-flight fetching: when two workers ask for the same post at once,
 * the second one waits for the first and then reads its result from the cache
 * instead of scanning the same conversation again.
 *
 * flock() is per-host. For several servers sharing one cache, implement
 * {@see LockFactory} with Redis or a database.
 */
final class FileLockFactory implements LockFactory
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create lock directory $directory");
        }
    }

    public function synchronized(string $name, float $timeoutSeconds, callable $callback): mixed
    {
        $file = $this->directory . '/' . hash('sha256', $name) . '.lock';
        $fp = @fopen($file, 'c');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open lock file for $name");
        }
        try {
            $deadline = microtime(true) + $timeoutSeconds;
            while (!flock($fp, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new LockTimeoutException("Timed out waiting for lock '$name'");
                }
                usleep(100_000);
            }
            try {
                return $callback();
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
