<?php
declare(strict_types=1);

namespace Mrpc\XScraper;

/** Helpers for X's snowflake ids, which embed their creation time. */
final class Snowflake
{
    private const EPOCH_MS = 1288834974657;

    /** Creation time in milliseconds since the Unix epoch, or null for non-snowflake ids. */
    public static function timestampMs(string $id): ?int
    {
        if (!ctype_digit($id) || strlen($id) < 15 || strlen($id) > 19) {
            return null;
        }
        return ((int) $id >> 22) + self::EPOCH_MS;
    }

    /** Age in seconds relative to $now (Unix seconds). */
    public static function ageSeconds(string $id, ?int $now = null): ?int
    {
        $ms = self::timestampMs($id);
        return $ms === null ? null : max(0, ($now ?? time()) - intdiv($ms, 1000));
    }

    /** Numeric comparison of two id strings (works without relying on 64-bit casts). */
    public static function compare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    /** Extract a post id from a URL (x.com/twitter.com/mobile, /status/ or /statuses/) or a bare id. */
    public static function parse(string $input): string
    {
        $input = trim($input);
        if (ctype_digit($input) && strlen($input) <= 25) {
            return $input;
        }
        if (preg_match('~/status(?:es)?/(\d{1,25})~', $input, $m)) {
            return $m[1];
        }
        throw new \InvalidArgumentException('Could not find a post id in input');
    }
}
