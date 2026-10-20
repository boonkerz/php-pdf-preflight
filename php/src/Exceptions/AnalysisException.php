<?php

declare(strict_types=1);

namespace PrintPreflight\Exceptions;

/**
 * A file could not be analysed. `getReason()` is a stable machine-readable
 * identifier (see the REASON_* constants).
 */
final class AnalysisException extends \RuntimeException implements PreflightException
{
    public const REASON_UNREADABLE = 'unreadable';
    public const REASON_INVALID = 'invalid';
    public const REASON_PASSWORD_REQUIRED = 'password_required';
    public const REASON_UNSUPPORTED_ENCRYPTION = 'unsupported_encryption';
    public const REASON_TOO_LARGE = 'too_large';
    public const REASON_LIMIT_EXCEEDED = 'limit_exceeded';
    public const REASON_INTERNAL = 'internal';

    public function __construct(
        private readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
