<?php

namespace App\Exceptions;

use RuntimeException;

class EmptyCartException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Кошик порожній — немає чого оформлювати.');
    }
}
