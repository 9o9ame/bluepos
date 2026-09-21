<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiException extends HttpException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $errorKey,
        string $message,
        int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($status, $message);
    }
}
