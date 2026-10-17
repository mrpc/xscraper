<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use Mrpc\XScraper\Exception\LockTimeoutException;
use Mrpc\XScraper\Lock\FileLockFactory;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase
{
    private string $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/xscraper_lock_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->lockDir)) {
            foreach (glob($this->lockDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->lockDir);
        }
    }

    /**
     * Verifies that the lock executes the synchronized callback and returns its result.
     */
    public function testSynchronizedSuccess(): void
    {
        // Arrange
        $factory = new FileLockFactory($this->lockDir);

        // Act
        $result = $factory->synchronized('test_resource', 2.0, function () {
            return 'done';
        });

        // Assert
        $this->assertSame('done', $result);
    }

    /**
     * Verifies that waiting for an already held lock throws LockTimeoutException.
     */
    public function testLockTimeout(): void
    {
        // Arrange
        $factory = new FileLockFactory($this->lockDir);
        $lockFile = $this->lockDir . '/' . hash('sha256', 'contended') . '.lock';
        $fp = fopen($lockFile, 'c');
        $this->assertNotFalse($fp);
        flock($fp, LOCK_EX);

        // Assert
        $this->expectException(LockTimeoutException::class);

        // Act
        try {
            $factory->synchronized('contended', 0.2, function () {
                return 'never reached';
            });
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Verifies that an unwritable/invalid lock directory throws RuntimeException.
     */
    public function testInvalidDirectoryThrows(): void
    {
        // Arrange
        $invalidPath = '/dev/null/impossible_dir_' . bin2hex(random_bytes(4));

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act
        new FileLockFactory($invalidPath);
    }
}

