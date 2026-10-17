<?php
declare(strict_types=1);

namespace Mrpc\XScraper\RateLimit;

use Mrpc\XScraper\Exception\RateLimitException;

/**
 * Shared, cross-process request pacing for one session.
 *
 * - A random delay (uniform between minDelay and maxDelay) is enforced between any two
 *   requests made with the same session, across all PHP processes on this host.
 * - The x-rate-limit-remaining / x-rate-limit-reset headers are remembered per GraphQL
 *   operation; when the budget is spent nothing is sent until the window resets.
 * - After a 429 the session is put on cooldown for at least $cooldown seconds (or until
 *   X's reset time, whichever is later). No other session is used instead.
 *
 * State lives in a small JSON file per session, read-modify-written under flock().
 */
final class Throttle
{
    public function __construct(
        private string $directory,
        private float $minDelay = 1.5,
        private float $maxDelay = 4.5,
        private int $cooldown = 900,
        private int $keepInReserve = 1,
        /** @var callable(int):void */
        private $sleeper = null,
    ) {
        if ($minDelay < 0 || $maxDelay < $minDelay) {
            throw new \InvalidArgumentException('Require 0 <= minDelay <= maxDelay');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create throttle directory $directory");
        }
        $this->sleeper ??= static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Block until a request may be sent, then reserve the slot.
     *
     * @throws RateLimitException when the session is cooling down or the operation's budget is spent
     */
    public function acquire(string $sessionKey, string $operation): void
    {
        $this->withState($sessionKey, function (array $state) use ($operation): array {
            $now = time();
            if (($state['cooldownUntil'] ?? 0) > $now) {
                throw new RateLimitException(
                    'Session is cooling down after a 429 until ' . date('c', $state['cooldownUntil']),
                    $state['cooldownUntil'],
                );
            }
            $op = $state['ops'][$operation] ?? null;
            if ($op !== null && $op['reset'] > $now && $op['remaining'] <= $this->keepInReserve) {
                throw new RateLimitException(
                    "Budget for $operation exhausted until " . date('c', $op['reset']),
                    $op['reset'],
                );
            }

            $delay = $this->minDelay + (random_int(0, 1_000_000) / 1_000_000) * ($this->maxDelay - $this->minDelay);
            $wait = ($state['last'] ?? 0.0) + $delay - microtime(true);
            if ($wait > 0) {
                ($this->sleeper)((int) round($wait * 1_000_000));
            }
            $state['last'] = microtime(true);
            return $state;
        });
    }

    /** Record what the server told us about the budget. */
    public function record(string $sessionKey, string $operation, int $status, array $headers): void
    {
        $this->withState($sessionKey, function (array $state) use ($operation, $status, $headers): array {
            $reset = isset($headers['x-rate-limit-reset']) ? (int) $headers['x-rate-limit-reset'] : null;
            if (isset($headers['x-rate-limit-remaining'])) {
                $state['ops'][$operation] = [
                    'remaining' => (int) $headers['x-rate-limit-remaining'],
                    'reset'     => $reset ?? time() + 900,
                ];
            }
            if ($status === 429) {
                $state['cooldownUntil'] = max(time() + $this->cooldown, $reset ?? 0);
            }
            return $state;
        });
    }

    /** @return array{cooldownUntil?:int, last?:float, ops?:array<string,array{remaining:int,reset:int}>} */
    public function status(string $sessionKey): array
    {
        return $this->withState($sessionKey, static fn (array $s): array => $s, readOnly: true);
    }

    private function withState(string $sessionKey, callable $mutate, bool $readOnly = false): array
    {
        $file = $this->directory . '/' . hash('sha256', $sessionKey) . '.json';
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open throttle state');
        }
        try {
            flock($fp, $readOnly ? LOCK_SH : LOCK_EX);
            $state = json_decode((string) stream_get_contents($fp), true);
            $state = is_array($state) ? $state : [];
            $new = $mutate($state);
            if (!$readOnly) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, (string) json_encode($new));
                fflush($fp);
            }
            return $new;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
