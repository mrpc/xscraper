<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Snowflake;
use PHPUnit\Framework\TestCase;

final class SnowflakeTest extends TestCase
{
    /**
     * Verifies extracting Unix epoch millisecond timestamp from a Snowflake ID string.
     */
    public function testTimestampMs(): void
    {
        // Arrange
        $id = '1845187747970863384';

        // Act
        $ms = Snowflake::timestampMs($id);

        // Assert
        $this->assertNotNull($ms);
        $this->assertGreaterThan(1_700_000_000_000, $ms);
        $this->assertNull(Snowflake::timestampMs('not-a-digit'));
        $this->assertNull(Snowflake::timestampMs('123')); // too short
        $this->assertNull(Snowflake::timestampMs('123456789012345678901234567890')); // too long
    }

    /**
     * Verifies calculating the elapsed age in seconds since tweet creation.
     */
    public function testAgeSeconds(): void
    {
        // Arrange
        $id = '1845187747970863384';
        $now = 1728765432;

        // Act
        $age = Snowflake::ageSeconds($id, $now);

        // Assert
        $this->assertNotNull($age);
        $this->assertGreaterThanOrEqual(0, $age);
        $this->assertNull(Snowflake::ageSeconds('invalid'));
    }

    /**
     * Verifies numerical string comparison for Snowflake IDs of varying lengths.
     */
    public function testCompare(): void
    {
        // Act & Assert
        $this->assertLessThan(0, Snowflake::compare('100', '1000'));
        $this->assertGreaterThan(0, Snowflake::compare('1000', '100'));
        $this->assertSame(0, Snowflake::compare('500', '500'));
        $this->assertLessThan(0, Snowflake::compare('501', '502'));
    }

    /**
     * Verifies parsing Tweet IDs from raw ID strings or various URL formats.
     */
    public function testParse(): void
    {
        // Act & Assert
        $this->assertSame('1845187747970863384', Snowflake::parse('1845187747970863384'));
        $this->assertSame('1845187747970863384', Snowflake::parse('  1845187747970863384  '));
        $this->assertSame('1845187747970863384', Snowflake::parse('https://x.com/jack/status/1845187747970863384'));
        $this->assertSame('1845187747970863384', Snowflake::parse('https://twitter.com/user/statuses/1845187747970863384?s=20'));
    }

    /**
     * Verifies that unparseable non-status URLs throw InvalidArgumentException.
     */
    public function testParseInvalidThrows(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        Snowflake::parse('https://x.com/explore');
    }
}

