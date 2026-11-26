<?php

namespace HiEvents\Services\Application\Handlers\Organizer\Integrations\DTO;

use HiEvents\Services\Infrastructure\Fastn\FastnEmbedToken;

class IntegrationsEmbedDTO
{
    /**
     * @param  bool|null  $productConnected  null when this server does not connect organizers to fastn itself
     */
    public function __construct(
        public readonly FastnEmbedToken $embedToken,
        public readonly ?bool $productConnected,
    ) {}
}
