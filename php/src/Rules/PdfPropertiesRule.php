<?php

declare(strict_types=1);

namespace PrintPreflight\Rules;

use PrintPreflight\Analysis\DocumentAnalysis;

/**
 * Document-level properties: encryption and the PDF/X *identification*.
 * A declared PDF/X version is no proof of conformance; this rule only checks
 * the declaration.
 */
final class PdfPropertiesRule extends AbstractRule
{
    /** @var list<string>|null */
    private readonly ?array $requirePdfx;

    /**
     * @param bool $allowEncrypted Accept encrypted files that open without a password
     * @param list<string>|null $requirePdfx Accepted PDF/X declarations (prefix match,
     *     e.g. ["PDF/X-1a", "PDF/X-4"]); null disables the check
     * @param string|null $maxVersion Highest accepted PDF version, e.g. "1.7"
     */
    public function __construct(
        private readonly bool $allowEncrypted = true,
        ?array $requirePdfx = null,
        private readonly ?string $maxVersion = null,
        ?RuleOptions $options = null,
    ) {
        parent::__construct($options);
        foreach ($requirePdfx ?? [] as $v) {
            if (!is_string($v) || $v === '') {
                throw new \InvalidArgumentException('require_pdfx must be a list of version strings');
            }
        }
        if ($maxVersion !== null && preg_match('/^\d\.\d$/', $maxVersion) !== 1) {
            throw new \InvalidArgumentException('max_version must look like "1.7"');
        }
        $this->requirePdfx = $requirePdfx === null ? null : array_values($requirePdfx);
    }

    public static function type(): string
    {
        return 'pdf_properties';
    }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        if (!$this->allowEncrypted && $document->encrypted) {
            $findings->fail('pdf_properties.encrypted', null, ['encrypted' => false], ['encrypted' => true]);
        }
        if ($this->requirePdfx !== null) {
            $declared = $document->pdfxVersion;
            $ok = $declared !== null && array_filter($this->requirePdfx, static fn (string $v): bool => str_starts_with($declared, $v)) !== [];
            if (!$ok) {
                $findings->fail(
                    'pdf_properties.pdfx_not_declared',
                    null,
                    ['pdfx_declared' => $this->requirePdfx, 'validated' => false],
                    ['pdfx_declared' => $declared],
                    ['expected' => $this->requirePdfx, 'actual' => $declared ?? '–'],
                );
            }
        }
        if ($this->maxVersion !== null && $document->pdfVersion !== null && version_compare($document->pdfVersion, $this->maxVersion, '>')) {
            $findings->fail(
                'pdf_properties.version_too_high',
                null,
                ['max_version' => $this->maxVersion],
                ['version' => $document->pdfVersion],
                ['max' => $this->maxVersion, 'actual' => $document->pdfVersion],
            );
        }
    }
}
