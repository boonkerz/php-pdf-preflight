<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Results\RuleResult;

/**
 * A preflight rule. Rules only see the parser-independent DocumentAnalysis.
 */
interface RuleInterface
{
    public function validate(DocumentAnalysis $document): RuleResult;
}
