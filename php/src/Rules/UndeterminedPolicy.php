<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

/**
 * How a rule reports a check that cannot be decided from the analysis, e.g.
 * because the colour classification of a page is unknown or the content
 * analysis hit a resource limit.
 */
enum UndeterminedPolicy: string
{
    /** Report as error (default): nothing passes unverified. */
    case Error = 'error';
    case Warning = 'warning';
    /** Do not report; the rule result still has status "undetermined". */
    case Ignore = 'ignore';
}
