<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Exception\RateLimitException;
use Mrpc\XScraper\RateLimit\Throttle;
use PHPUnit\Framework\TestCase;

final class ThrottleTest extends TestCase
{
    private string $throttleDir;

    protected function setUp(): void
    {
        $this->throttleDir = sys_get_temp_dir() . '/xscraper_throttle_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->throttleDir)) {
            foreach (glob($this->throttleDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->throttleDir);
        }
    }

    public function testThrottleConstructInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Throttle($this->throttleDir, minDelay: 5.0, maxDelay: 2.0);
    }

    public function testThrottleAcquireAndRecord(): void
    {
        $sleepCount = 0;
        $sleeper = function (int $us) use (&$sleepCount): void {
            $sleepCount++;
        };

        $throttle = new Throttle(
            $this->throttleDir,
            minDelay: 0.0,
            maxDelay: 0.0,
            sleeper: $sleeper,
        );

        $sessionKey = 'sess_abc';
        $throttle->acquire($sessionKey, 'TweetDetail');

        // Record headers
        $headers = [
            'x-rate-limit-remaining' => '100',
            'x-rate-limit-reset' => (string) (time() + 900),
        ];
        $throttle->record($sessionKey, 'TweetDetail', 200, $headers);

        $status = $throttle->status($sessionKey);
        $this->assertArrayHasKey('TweetDetail', $status['ops'] ?? []);
        $this->assertSame(100, $status['ops']['TweetDetail']['remaining']);
    }

    public function testThrottleCooldownOn429(): void
    {
        $throttle = new Throttle(
            $this->throttleDir,
            minDelay: 0.0,
            maxDelay: 0.0,
            cooldown: 900,
        );

        $sessionKey = 'sess_limited';
        $throttle->record($sessionKey, 'TweetDetail', 429, [
            'x-rate-limit-reset' => (string) (time() + 900),
        ]);

        $this->expectException(RateLimitException::class);
        $throttle->acquire($sessionKey, 'TweetDetail');
    }

    public function testThrottleExhaustedBudget(): void
    {
        $throttle = new Throttle(
            $this->throttleDir,
            minDelay: 0.0,
            maxDelay: 0.0,
            keepInReserve: 1,
        );

        $sessionKey = 'sess_exhausted';
        $throttle->record($sessionKey, 'TweetDetail', 200, [
            'x-rate-limit-remaining' => '1', // at reserve
            'x-rate-limit-reset' => (string) (time() + 60),
        ]);

        $this->expectException(RateLimitException::class);
        $throttle->acquire($sessionKey, 'TweetDetail');
    }

    public function testRateLimitExceptionRetryAfter(): void
    {
        $future = time() + 120;
        $ex = new RateLimitException('Rate limited', $future);
        $this->assertGreaterThan(0, $ex->retryAfter());

        $exNull = new RateLimitException('No reset time');
        $this->assertSame(0, $exNull->retryAfter());
    }

    public function testThrottleEnforcesDelayWithSleeper(): void
    {
        $sleptMicroseconds = null;
        $sleeper = function (int $us) use (&$sleptMicroseconds): void {
            $sleptMicroseconds = $us;
        };

        $throttle = new Throttle(
            $this->throttleDir,
            minDelay: 2.0,
            maxDelay: 2.0,
            sleeper: $sleeper,
        );

        $sessionKey = 'sess_delay_check';
        // First acquire sets last timestamp
        $throttle->acquire($sessionKey, 'TweetDetail');
        $this->assertNull($sleptMicroseconds);

        // Immediate second acquire triggers sleep
        $throttle->acquire($sessionKey, 'TweetDetail');
        $this->assertNotNull($sleptMicroseconds);
        $this->assertGreaterThan(0, $sleptMicroseconds);
    }
}
