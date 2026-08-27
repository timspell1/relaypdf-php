<?php

declare(strict_types=1);

namespace RelayPDF;

final class RelayPDFError extends \RuntimeException
{
    /**
     * API error code (`rate_limited`, `unauthorized`, …).
     * Distinct from {@see \Exception::$code}, which is an integer.
     */
    public readonly string $errorCode;

    public function __construct(
        public readonly int $status,
        string $code,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly mixed $details = null,
    ) {
        $this->errorCode = $code;
        parent::__construct($message);
    }
}
