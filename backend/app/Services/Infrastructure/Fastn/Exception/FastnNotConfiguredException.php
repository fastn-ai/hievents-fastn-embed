<?php

namespace HiEvents\Services\Infrastructure\Fastn\Exception;

use RuntimeException;

class FastnNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('FASTN_API_KEY and FASTN_IDV_SECRET are not set');
    }
}
