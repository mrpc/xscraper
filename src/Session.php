<?php
declare(strict_types=1);

namespace Mrpc\XScraper;

use InvalidArgumentException;

/**
 * One logged-in x.com browser session, identified by its auth_token and ct0 cookies.
 *
 * The ct0 value is the CSRF token; X may rotate it in a Set-Cookie header. The scraper
 * picks up the new value and persists it in its state cache, keyed by {@see key()},
 * so the original object stays immutable.
 */
final class Session
{
    public function __construct(
        public readonly string $authToken,
        public readonly string $ct0,
        public readonly string $name = 'default',
    ) {
        if ($authToken === '' || $ct0 === '') {
            throw new InvalidArgumentException('auth_token and ct0 are both required');
        }
        if (preg_match('/[\s;,]/', $authToken . $ct0)) {
            throw new InvalidArgumentException('Cookie values must not contain whitespace, ";" or ","');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name)) {
            throw new InvalidArgumentException('Session name must be 1-64 chars of [A-Za-z0-9_.-]');
        }
    }

    /** Build from a raw Cookie header copied from the browser ("auth_token=..; ct0=..; ..."). */
    public static function fromCookieHeader(string $cookieHeader, string $name = 'default'): self
    {
        $jar = [];
        foreach (explode(';', $cookieHeader) as $pair) {
            $pos = strpos($pair, '=');
            if ($pos === false) {
                continue;
            }
            $jar[trim(substr($pair, 0, $pos))] = trim(substr($pair, $pos + 1));
        }
        if (!isset($jar['auth_token'], $jar['ct0'])) {
            throw new InvalidArgumentException('Cookie header must contain auth_token and ct0');
        }
        return new self($jar['auth_token'], $jar['ct0'], $name);
    }

    /**
     * Build from environment variables: X_COOKIES (a full Cookie header) or
     * X_AUTH_TOKEN + X_CT0.
     */
    public static function fromEnvironment(string $name = 'default'): self
    {
        $cookies = getenv('X_COOKIES');
        if (is_string($cookies) && $cookies !== '') {
            return self::fromCookieHeader($cookies, $name);
        }
        return new self((string) getenv('X_AUTH_TOKEN'), (string) getenv('X_CT0'), $name);
    }

    /** Stable, non-reversible identifier used for cache and throttle keys. */
    public function key(): string
    {
        return $this->name . '-' . substr(hash('sha256', $this->authToken), 0, 16);
    }

    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'authToken' => '***', 'ct0' => '***'];
    }
}
