<?php
declare(strict_types=1);

namespace Pramnos\Http;

class ClientException extends \Exception
{
}

class FakeResponse
{
    public function __construct(
        private int $status = 200,
        private array $headers = ['Content-Type' => 'application/json'],
        private string $body = '{"ok":true}',
        private bool $truncated = false,
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}

class Client
{
    public static ?self $instance = null;
    public string $url = '';
    public string $method = '';
    public array $headers = [];
    public int $connectTimeout = 0;
    public int $timeout = 0;
    public bool $withoutRedirectsCalled = false;
    public int $maxBytes = 0;
    public ?\Throwable $throw = null;
    public ?FakeResponse $response = null;

    public static function get(string $url): self
    {
        $c = self::$instance ?? new self();
        $c->method = 'GET';
        $c->url = $url;
        return $c;
    }

    public static function post(string $url): self
    {
        $c = self::$instance ?? new self();
        $c->method = 'POST';
        $c->url = $url;
        return $c;
    }

    public function headers(array $h): self
    {
        $this->headers = $h;
        return $this;
    }

    public function connectTimeout(int $t): self
    {
        $this->connectTimeout = $t;
        return $this;
    }

    public function timeout(int $t): self
    {
        $this->timeout = $t;
        return $this;
    }

    public function withoutRedirects(): self
    {
        $this->withoutRedirectsCalled = true;
        return $this;
    }

    public function maxResponseBytes(int $b): self
    {
        $this->maxBytes = $b;
        return $this;
    }

    public function send(): FakeResponse
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->response ?? new FakeResponse();
    }
}
