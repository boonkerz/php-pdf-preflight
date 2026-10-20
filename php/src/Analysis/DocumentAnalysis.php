<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

/**
 * Parser-independent result of the technical analysis of one file. Rules only
 * depend on this class, never on the native extension, so a stored analysis
 * (`toJson()`) can be re-checked against other profiles without re-parsing.
 */
final class DocumentAnalysis
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param string $fileType Detected type: pdf, jpeg, png, tiff or unknown
     * @param bool $analyzed False if no analyzer supports the file type
     * @param list<PageAnalysis> $pages
     * @param list<FontInfo> $fonts Document-level font summary with pages
     * @param list<Issue> $issues Document structure issues
     * @param array<string, string|null> $metadata Info dictionary (Title, Author, …)
     * @param string|null $pdfxVersion Declared PDF/X version (identification only, never validated)
     */
    public function __construct(
        public readonly string $fileType,
        public readonly bool $analyzed,
        public readonly array $pages = [],
        public readonly array $fonts = [],
        public readonly array $issues = [],
        public readonly ?string $fileName = null,
        public readonly ?int $fileSize = null,
        public readonly ?string $pdfVersion = null,
        public readonly bool $encrypted = false,
        public readonly array $metadata = [],
        public readonly ?string $pdfxVersion = null,
    ) {
        foreach (array_values($pages) as $i => $page) {
            if ($page->number !== $i + 1) {
                throw new \InvalidArgumentException('pages must be numbered consecutively starting at 1');
            }
        }
    }

    /**
     * For files that no analyzer supports (e.g. images without an image analyzer).
     */
    public static function unanalyzed(string $fileType, ?string $fileName = null, ?int $fileSize = null): self
    {
        return new self($fileType, false, fileName: $fileName, fileSize: $fileSize);
    }

    public function isAnalyzed(): bool
    {
        return $this->analyzed;
    }

    public function getPageCount(): int
    {
        return count($this->pages);
    }

    /**
     * @param int $number 1-based page number
     */
    public function getPage(int $number): PageAnalysis
    {
        return $this->pages[$number - 1]
            ?? throw new \OutOfRangeException("page {$number} does not exist (1..{$this->getPageCount()})");
    }

    /**
     * @return list<PageAnalysis>
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    /** True if the content of every page was analysed completely. */
    public function isContentComplete(): bool
    {
        foreach ($this->pages as $page) {
            if (!$page->contentComplete) {
                return false;
            }
        }
        return true;
    }

    /**
     * Builds the analysis from the output of `PdfNative::analyze()` or from
     * `toArray()`.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, ?string $fileName = null, ?int $fileSize = null): self
    {
        $r = new ArrayReader($data);
        $doc = $r->child('document');
        $info = [];
        if ($doc->has('info')) {
            foreach ($doc->array('info') as $key => $value) {
                if (is_string($key) && (is_string($value) || $value === null)) {
                    $info[$key] = $value;
                }
            }
        }
        $pdfx = $doc->has('pdfx') ? $doc->child('pdfx') : null;

        return new self(
            fileType: $r->string('file_type', 'pdf'),
            analyzed: $r->bool('analyzed', true),
            pages: array_map(PageAnalysis::fromArray(...), $r->children('pages')),
            fonts: array_map(FontInfo::fromArray(...), $r->children('fonts')),
            issues: array_map(Issue::fromArray(...), $doc->children('issues')),
            fileName: $fileName ?? ($r->has('file_name') ? $r->string('file_name') : null),
            fileSize: $fileSize ?? ($r->has('file_size') ? $r->int('file_size') : ($doc->has('file_size') ? $doc->int('file_size') : null)),
            pdfVersion: $doc->nullableString('version'),
            encrypted: $doc->bool('encrypted', false),
            metadata: $info,
            pdfxVersion: $pdfx?->nullableString('version'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'file_type' => $this->fileType,
            'analyzed' => $this->analyzed,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'document' => [
                'version' => $this->pdfVersion,
                'encrypted' => $this->encrypted,
                'page_count' => $this->getPageCount(),
                'info' => $this->metadata,
                'pdfx' => ['declared' => $this->pdfxVersion !== null, 'version' => $this->pdfxVersion, 'validated' => false],
                'issues' => array_map(static fn (Issue $i): array => $i->toArray(), $this->issues),
            ],
            'pages' => array_map(static fn (PageAnalysis $p): array => $p->toArray(), $this->pages),
            'fonts' => array_map(static fn (FontInfo $f): array => $f->toArray(), $this->fonts),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('analysis JSON must be an object');
        }
        return self::fromArray($data);
    }
}
