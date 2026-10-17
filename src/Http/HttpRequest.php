<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

/** Transport-neutral outgoing request. Headers keep insertion order. */
final class HttpRequest
{
    /**
     * @param list<array{0:string,1:string}> $headers ordered name/value pairs
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly int $timeout = 25,
        public readonly int $maxBodyBytes = 8_388_608,
    ) {
    }

    /** @return array<string,string> headers as a name => value map (order preserved) */
    public function headerMap(): array
    {
        $map = [];
        foreach ($this->headers as [$name, $value]) {
            $map[$name] = $value;
        }
        return $map;
    }
}
