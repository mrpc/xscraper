<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Snowflake;
use PHPUnit\Framework\TestCase;

final class SnowflakeTest extends TestCase
{
    public function testTimestampMs(): void
    {
        // Sample tweet id: 1845187747970863384
        $id = '1845187747970863384';
        $ms = Snowflake::timestampMs($id);
        $this->assertNotNull($ms);
        $this->assertGreaterThan(1_700_000_000_000, $ms);

        $this->assertNull(Snowflake::timestampMs('not-a-digit'));
        $this->assertNull(Snowflake::timestampMs('123')); // too short
        $this->assertNull(Snowflake::timestampMs('123456789012345678901234567890')); // too long
    }

    public function testAgeSeconds(): void
    {
        $id = '1845187747970863384';
        $now = 1728765432;
        $age = Snowflake::ageSeconds($id, $now);
        $this->assertNotNull($age);
        $this->assertGreaterThanOrEqual(0, $age);

        $this->assertNull(Snowflake::ageSeconds('invalid'));
    }

    public function testCompare(): void
    {
        $this->assertLessThan(0, Snowflake::compare('100', '1000'));
        $this->assertGreaterThan(0, Snowflake::compare('1000', '100'));
        $this->assertSame(0, Snowflake::compare('500', '500'));
        $this->assertLessThan(0, Snowflake::compare('501', '502'));
    }

    public function testParse(): void
    {
        $this->assertSame('1845187747970863384', Snowflake::parse('1845187747970863384'));
        $this->assertSame('1845187747970863384', Snowflake::parse('  1845187747970863384  '));
        $this->assertSame('1845187747970863384', Snowflake::parse('https://x.com/jack/status/1845187747970863384'));
        $this->assertSame('1845187747970863384', Snowflake::parse('https://twitter.com/user/statuses/1845187747970863384?s=20'));
    }

    public function testParseInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Snowflake::parse('https://x.com/explore');
    }
}
