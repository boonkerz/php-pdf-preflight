<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * Print classification of a page. `Unknown` is used whenever the analysis
 * cannot decide reliably – it is never guessed from declared colour spaces.
 */
enum ColorClass: string
{
    case Color = 'color';
    case Grayscale = 'grayscale';
    case Unknown = 'unknown';
}
