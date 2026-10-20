<?php

declare(strict_types=1);

namespace PrintPreflight;

use PrintPreflight\Analysis\DocumentAnalysis;
use PrintPreflight\Analysis\FileAnalyzerInterface;
use PrintPreflight\Analysis\NativePdfAnalyzer;
use PrintPreflight\Exceptions\AnalysisException;

/**
 * @phpstan-import-type NativeOptions from NativePdfAnalyzer
 *
 * Entry point for analysing files. Detects the file type and delegates to the
 * first registered analyzer that supports it. Files without a matching
 * analyzer yield an "unanalyzed" DocumentAnalysis, so that a FileTypeRule can
 * still report them.
 */
final class Analyzer
{
    /** @var list<FileAnalyzerInterface> */
    private array $analyzers;

    /**
     * @param list<FileAnalyzerInterface>|null $analyzers Defaults to the native PDF analyzer
     */
    public function __construct(?array $analyzers = null)
    {
        $this->analyzers = $analyzers ?? [new NativePdfAnalyzer()];
    }

    /**
     * Analyses a file with the default analyzers.
     *
     * @param NativeOptions $options Options for the native PDF analyzer
     * @throws AnalysisException
     */
    public static function open(string $path, array $options = []): DocumentAnalysis
    {
        return (new self([new NativePdfAnalyzer($options)]))->analyze($path);
    }

    public function withAnalyzer(FileAnalyzerInterface $analyzer): self
    {
        $clone = clone $this;
        $clone->analyzers = [$analyzer, ...$this->analyzers];
        return $clone;
    }

    /**
     * @throws AnalysisException
     */
    public function analyze(string $path): DocumentAnalysis
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AnalysisException(AnalysisException::REASON_UNREADABLE, "{$path}: file not found or not readable");
        }
        $type = FileType::detect($path);
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->supports($type)) {
                return $analyzer->analyze($path, $type);
            }
        }
        $size = @filesize($path);
        return DocumentAnalysis::unanalyzed($type, basename($path), $size === false ? null : $size);
    }
}
