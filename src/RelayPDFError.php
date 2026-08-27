<?php

declare(strict_types=1);

namespace RelayPDF;

final class RelayPDFError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
