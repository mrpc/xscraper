<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    /**
     * Verifies successful session construction, property access, key generation, and masked debug info.
     */
    public function testConstructValid(): void
    {
        // Arrange & Act
        $session = new Session('auth123', 'ct0token', 'custom_name');

        // Assert
        $this->assertSame('auth123', $session->authToken);
        $this->assertSame('ct0token', $session->ct0);
        $this->assertSame('custom_name', $session->name);
        $this->assertStringStartsWith('custom_name-', $session->key());

        $debug = $session->__debugInfo();
        $this->assertSame('***', $debug['authToken']);
        $this->assertSame('***', $debug['ct0']);
    }

    /**
     * Verifies that empty tokens throw InvalidArgumentException.
     */
    public function testConstructInvalidThrows(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Session('', 'ct0');
    }

    /**
     * Verifies that tokens with whitespace throw InvalidArgumentException.
     */
    public function testConstructWhitespaceThrows(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Session('auth token', 'ct0');
    }

    /**
     * Verifies that invalid session names throw InvalidArgumentException.
     */
    public function testConstructInvalidNameThrows(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Session('auth', 'ct0', 'invalid name with spaces!');
    }

    /**
     * Verifies parsing session credentials from a standard Cookie header string.
     */
    public function testFromCookieHeader(): void
    {
        // Arrange
        $header = 'guest_id=123; auth_token=token_abc; ct0=csrf_xyz; personalization_id="v1_xxx"';

        // Act
        $session = Session::fromCookieHeader($header, 'my_session');

        // Assert
        $this->assertSame('token_abc', $session->authToken);
        $this->assertSame('csrf_xyz', $session->ct0);
        $this->assertSame('my_session', $session->name);
    }

    /**
     * Verifies that missing required cookies throws InvalidArgumentException.
     */
    public function testFromCookieHeaderMissingThrows(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        Session::fromCookieHeader('other_cookie=123');
    }

    /**
     * Verifies loading session credentials from environment variables.
     */
    public function testFromEnvironment(): void
    {
        // Arrange
        putenv('X_AUTH_TOKEN=env_auth');
        putenv('X_CT0=env_ct0');
        putenv('X_COOKIES');

        // Act
        $session = Session::fromEnvironment();

        // Assert
        $this->assertSame('env_auth', $session->authToken);
        $this->assertSame('env_ct0', $session->ct0);

        // Arrange (from X_COOKIES)
        putenv('X_COOKIES=auth_token=cookie_auth; ct0=cookie_ct0');

        // Act
        $fromCookies = Session::fromEnvironment('env_sess');

        // Assert
        $this->assertSame('cookie_auth', $fromCookies->authToken);
        $this->assertSame('cookie_ct0', $fromCookies->ct0);

        // Cleanup
        putenv('X_AUTH_TOKEN');
        putenv('X_CT0');
        putenv('X_COOKIES');
    }
}

