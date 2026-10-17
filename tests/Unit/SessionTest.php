<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function testConstructValid(): void
    {
        $session = new Session('auth123', 'ct0token', 'custom_name');
        $this->assertSame('auth123', $session->authToken);
        $this->assertSame('ct0token', $session->ct0);
        $this->assertSame('custom_name', $session->name);
        $this->assertStringStartsWith('custom_name-', $session->key());

        $debug = $session->__debugInfo();
        $this->assertSame('***', $debug['authToken']);
        $this->assertSame('***', $debug['ct0']);
    }

    public function testConstructInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Session('', 'ct0');
    }

    public function testConstructWhitespaceThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Session('auth token', 'ct0');
    }

    public function testConstructInvalidNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Session('auth', 'ct0', 'invalid name with spaces!');
    }

    public function testFromCookieHeader(): void
    {
        $header = 'guest_id=123; auth_token=token_abc; ct0=csrf_xyz; personalization_id="v1_xxx"';
        $session = Session::fromCookieHeader($header, 'my_session');
        $this->assertSame('token_abc', $session->authToken);
        $this->assertSame('csrf_xyz', $session->ct0);
        $this->assertSame('my_session', $session->name);
    }

    public function testFromCookieHeaderMissingThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Session::fromCookieHeader('other_cookie=123');
    }

    public function testFromEnvironment(): void
    {
        putenv('X_AUTH_TOKEN=env_auth');
        putenv('X_CT0=env_ct0');
        putenv('X_COOKIES');

        $session = Session::fromEnvironment();
        $this->assertSame('env_auth', $session->authToken);
        $this->assertSame('env_ct0', $session->ct0);

        putenv('X_COOKIES=auth_token=cookie_auth; ct0=cookie_ct0');
        $fromCookies = Session::fromEnvironment('env_sess');
        $this->assertSame('cookie_auth', $fromCookies->authToken);
        $this->assertSame('cookie_ct0', $fromCookies->ct0);

        // cleanup
        putenv('X_AUTH_TOKEN');
        putenv('X_CT0');
        putenv('X_COOKIES');
    }
}
