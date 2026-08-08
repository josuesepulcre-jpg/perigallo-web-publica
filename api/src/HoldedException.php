<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use RuntimeException;

final class HoldedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $retryable = false,
        public readonly string $safeCode = 'holded_error'
    ) {
        parent::__construct($message);
    }
}
