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

    /**
     * Verifies that minDelay greater than maxDelay throws InvalidArgumentException.
     */
    public function testThrottleConstructInvalid(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Throttle($this->throttleDir, minDelay: 5.0, maxDelay: 2.0);
    }

    /**
     * Verifies acquiring slot and recording rate-limit header status.
     */
    public function testThrottleAcquireAndRecord(): void
    {
        // Arrange
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

        // Act
        $throttle->acquire($sessionKey, 'TweetDetail');
        $headers = [
            'x-rate-limit-remaining' => '100',
            'x-rate-limit-reset' => (string) (time() + 900),
        ];
        $throttle->record($sessionKey, 'TweetDetail', 200, $headers);
        $status = $throttle->status($sessionKey);

        // Assert
        $this->assertArrayHasKey('TweetDetail', $status['ops'] ?? []);
        $this->assertSame(100, $status['ops']['TweetDetail']['remaining']);
    }

    /**
     * Verifies that receiving HTTP 429 initiates cooldown period and blocks subsequent acquisitions.
     */
    public function testThrottleCooldownOn429(): void
    {
        // Arrange
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

        // Assert
        $this->expectException(RateLimitException::class);

        // Act
        $throttle->acquire($sessionKey, 'TweetDetail');
    }

    /**
     * Verifies that exhausted budget when remaining <= reserve throws RateLimitException.
     */
    public function testThrottleExhaustedBudget(): void
    {
        // Arrange
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

        // Assert
        $this->expectException(RateLimitException::class);

        // Act
        $throttle->acquire($sessionKey, 'TweetDetail');
    }

    /**
     * Verifies RateLimitException retryAfter calculation with both specified and null reset times.
     */
    public function testRateLimitExceptionRetryAfter(): void
    {
        // Arrange
        $future = time() + 120;
        $ex = new RateLimitException('Rate limited', $future);
        $exNull = new RateLimitException('No reset time');

        // Act & Assert
        $this->assertGreaterThan(0, $ex->retryAfter());
        $this->assertSame(0, $exNull->retryAfter());
    }

    /**
     * Verifies that back-to-back acquisitions enforce delay via custom sleeper callable.
     */
    public function testThrottleEnforcesDelayWithSleeper(): void
    {
        // Arrange
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

        // Act (first acquire)
        $throttle->acquire($sessionKey, 'TweetDetail');

        // Assert
        $this->assertNull($sleptMicroseconds);

        // Act (immediate second acquire)
        $throttle->acquire($sessionKey, 'TweetDetail');

        // Assert
        $this->assertNotNull($sleptMicroseconds);
        $this->assertGreaterThan(0, $sleptMicroseconds);
    }
}

