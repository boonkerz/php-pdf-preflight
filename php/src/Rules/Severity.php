<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
