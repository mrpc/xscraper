<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Model;

/** A photo, video or animated GIF attached to a post. */
final class Media
{
    public function __construct(
        public readonly string $type,        // photo | video | animated_gif
        public readonly string $url,         // full-size image, or best-bitrate MP4 for video/gif
        public readonly ?string $previewUrl = null,
        public readonly ?string $altText = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'url' => $this->url, 'preview_url' => $this->previewUrl, 'alt_text' => $this->altText];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self((string) $a['type'], (string) $a['url'], $a['preview_url'] ?? null, $a['alt_text'] ?? null);
    }
}
