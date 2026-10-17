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

    public function testSynchronizedSuccess(): void
    {
        $factory = new FileLockFactory($this->lockDir);
        $result = $factory->synchronized('test_resource', 2.0, function () {
            return 'done';
        });

        $this->assertSame('done', $result);
    }

    public function testLockTimeout(): void
    {
        $factory = new FileLockFactory($this->lockDir);

        // Simulate contention with file lock
        $lockFile = $this->lockDir . '/' . hash('sha256', 'contended') . '.lock';
        $fp = fopen($lockFile, 'c');
        $this->assertNotFalse($fp);
        flock($fp, LOCK_EX);

        $this->expectException(LockTimeoutException::class);
        try {
            $factory->synchronized('contended', 0.2, function () {
                return 'never reached';
            });
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
