<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Model;

/** Author of a post. */
final class User
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $screenName,
        public readonly ?string $name,
        public readonly bool $verified = false,
        public readonly ?int $followers = null,
        public readonly ?string $avatarUrl = null,
    ) {
    }

    public function profileUrl(): ?string
    {
        return $this->screenName === null ? null : 'https://x.com/' . $this->screenName;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'screen_name' => $this->screenName,
            'name' => $this->name,
            'verified' => $this->verified,
            'followers' => $this->followers,
            'avatar_url' => $this->avatarUrl,
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['id'] ?? ''),
            $a['screen_name'] ?? null,
            $a['name'] ?? null,
            (bool) ($a['verified'] ?? false),
            isset($a['followers']) ? (int) $a['followers'] : null,
            $a['avatar_url'] ?? null,
        );
    }
}
