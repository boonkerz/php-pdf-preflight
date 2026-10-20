<?php

declare(strict_types=1);

namespace PrintPreflight;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Exceptions\AnalysisException;
use PrintPreflight\Results\MessageFormatter;
use PrintPreflight\Results\PreflightResult;
use PrintPreflight\Results\RuleResult;
use PrintPreflight\Results\RuleStatus;
use PrintPreflight\Results\Violation;
use PrintPreflight\Rules\BlockingRuleInterface;
use PrintPreflight\Rules\RuleInterface;
use PrintPreflight\Rules\Severity;

/**
 * The rule engine: runs rules against a DocumentAnalysis and renders
 * customer-facing messages.
 */
final class Preflight
{
    private readonly MessageFormatter $formatter;

    /**
     * @param list<RuleInterface> $rules Evaluated in order
     */
    public function __construct(
        private readonly array $rules,
        private readonly ?string $profile = null,
        ?MessageFormatter $formatter = null,
    ) {
        $this->formatter = $formatter ?? new MessageFormatter();
    }

    public static function fromProfile(Profile $profile, ?RuleRegistry $registry = null): self
    {
        return new self($profile->createRules($registry), $profile->id, new MessageFormatter($profile->locale));
    }

    public function check(DocumentAnalysis $document): PreflightResult
    {
        $results = [];
        $blocked = false;
        foreach ($this->rules as $rule) {
            if ($blocked) {
                $results[] = $this->skipped($rule, $document);
                continue;
            }
            $result = $rule->validate($document);
            $results[] = $result->withViolations(array_map(
                fn (Violation $v): Violation => $v->withMessage($this->formatter->format($v)),
                $result->violations,
            ));
            if ($rule instanceof BlockingRuleInterface && $result->status === RuleStatus::Failed) {
                $blocked = true;
            }
        }
        return new PreflightResult($this->profile, $results);
    }

    /**
     * Analyses and checks a file in one step. Files that cannot be analysed
     * (damaged, password protected, too large, …) yield an invalid result
     * with a "document.*" error instead of an exception.
     */
    public function checkFile(string $path, ?Analyzer $analyzer = null): PreflightResult
    {
        try {
            $document = ($analyzer ?? new Analyzer())->analyze($path);
        } catch (AnalysisException $e) {
            $violation = new Violation('document', 'document.' . $e->getReason(), Severity::Error, actual: ['reason' => $e->getReason(), 'detail' => $e->getMessage()]);
            $violation = $violation->withMessage($this->formatter->format($violation));
            return new PreflightResult($this->profile, [new RuleResult('document', 'document', RuleStatus::Failed, [$violation])]);
        }
        return $this->check($document);
    }

    private function skipped(RuleInterface $rule, DocumentAnalysis $document): RuleResult
    {
        // Only the id/type of the rule are needed; derive them without evaluating.
        if ($rule instanceof Rules\AbstractRule) {
            return RuleResult::skipped($rule->getId(), $rule::type());
        }
        return RuleResult::skipped($rule::class, $rule::class);
    }
}
