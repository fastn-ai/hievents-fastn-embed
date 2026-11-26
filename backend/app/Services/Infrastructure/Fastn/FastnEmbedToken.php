<?php

namespace HiEvents\Services\Infrastructure\Fastn;

class FastnEmbedToken
{
    public function __construct(
        public readonly string $token,
        public readonly string $iframeUrl,
        public readonly ?string $expiresAt,
    ) {}
}
