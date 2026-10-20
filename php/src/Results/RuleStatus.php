<?php

declare(strict_types=1);

namespace PrintPreflight\Results;

enum RuleStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    /** The rule could not decide (see UndeterminedPolicy). */
    case Undetermined = 'undetermined';
    /** Not evaluated, e.g. because a blocking rule failed before. */
    case Skipped = 'skipped';
}
