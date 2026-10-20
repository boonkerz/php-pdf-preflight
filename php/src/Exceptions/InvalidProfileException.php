<?php

declare(strict_types=1);

namespace PrintPreflight\Exceptions;

/**
 * A profile or rule configuration is invalid. `getPath()` points to the
 * offending entry, e.g. `rules[2].params.tolerance`.
 */
class InvalidProfileException extends \InvalidArgumentException implements PreflightException
{
    public function __construct(
        private readonly string $path,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($path === '' ? $message : "{$path}: {$message}", 0, $previous);
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
