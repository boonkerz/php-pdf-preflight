<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

/**
 * Marker: if this rule fails, the following rules are skipped. Used for
 * checks after which further results would be meaningless (file type).
 */
interface BlockingRuleInterface extends RuleInterface
{
}
