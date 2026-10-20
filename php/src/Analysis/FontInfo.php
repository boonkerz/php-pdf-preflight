<?php

declare(strict_types=1);

namespace PrintPreflight\Analysis;

final class FontInfo
{
    public const EMBEDDED = 'embedded';
    public const NOT_EMBEDDED = 'not_embedded';
    /** A font program is referenced but missing, empty or undecodable. */
    public const INVALID = 'invalid';

    /**
     * @param list<string> $contexts Where text was shown: page, form, pattern, annotation
     * @param list<int> $pages Pages using the font (document-level summary only)
     * @param list<string> $problems
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $embedding,
        public readonly bool $subset = false,
        public readonly bool $standard14 = false,
        public readonly bool $used = true,
        public readonly bool $visible = true,
        public readonly string $baseFont = '',
        public readonly ?string $object = null,
        public readonly array $contexts = [],
        public readonly array $pages = [],
        public readonly array $problems = [],
    ) {
    }

    public function isEmbedded(): bool
    {
        return $this->embedding === self::EMBEDDED;
    }

    /**
     * Name for messages; Type3 fonts may have none.
     */
    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : ($this->object ?? $this->type);
    }

    public static function fromArray(ArrayReader $r): self
    {
        return new self(
            name: $r->string('name', ''),
            type: $r->string('type', 'Unknown'),
            embedding: $r->string('embedding'),
            subset: $r->bool('subset', false),
            standard14: $r->bool('standard14', false),
            used: $r->bool('used', true),
            visible: $r->bool('visible', true),
            baseFont: $r->string('base_font', ''),
            object: $r->nullableString('object'),
            contexts: $r->stringList('contexts'),
            pages: $r->intList('pages'),
            problems: $r->stringList('problems'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'base_font' => $this->baseFont,
            'type' => $this->type,
            'embedding' => $this->embedding,
            'embedded' => $this->isEmbedded(),
            'subset' => $this->subset,
            'standard14' => $this->standard14,
            'used' => $this->used,
            'visible' => $this->visible,
            'object' => $this->object,
            'contexts' => $this->contexts,
            'pages' => $this->pages,
            'problems' => $this->problems,
        ];
    }
}
