<?php

namespace App\Exceptions;

use RuntimeException;

class ContractCloseConflictException extends RuntimeException
{
    public const RETRY_MESSAGE = 'Der Vertrag wird gerade geschlossen. Bitte versuche es erneut.';

    public function __construct()
    {
        parent::__construct(self::RETRY_MESSAGE);
    }
}
