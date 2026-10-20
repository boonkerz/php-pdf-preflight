<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

use PrintPreflight\Exceptions\AnalysisException;

/**
 * Analyses one kind of file. Implement this to add support for further file
 * types (e.g. images) without changing the Analyzer or the rule engine.
 */
interface FileAnalyzerInterface
{
    public function supports(string $fileType): bool;

    /**
     * @throws AnalysisException
     */
    public function analyze(string $path, string $fileType): DocumentAnalysis;
}
