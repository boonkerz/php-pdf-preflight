<?php

declare(strict_types=1);

namespace PrintPreflight\Exceptions;

final class ExtensionNotLoadedException extends \RuntimeException implements PreflightException
{
    public static function create(): self
    {
        return new self(
            'The pdf_preflight extension is not loaded. Add "extension=pdf_preflight.so" to php.ini.',
        );
    }
}
