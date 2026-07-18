<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * What a dialog proc returns to describe one frame of its dialog, ported from the
 * Reline::DialogRenderInfo struct (reline.rb:29-38). It is the DialogRenderInfo
 * seam the architecture map §3 names: pure data handed from the proc to
 * update_each_dialog, which turns it into positioned, coloured overlay rows.
 *
 * Upstream builds it with keyword arguments; this port takes them positionally
 * (named-argument call syntax is avoided so the release downgrade to PHP 7.4 does
 * not have to rewrite the call sites). `bg_color` upstream is IRB-compatibility
 * padding only and is not carried here.
 */
final class DialogRenderInfo
{
    public ?CursorPos $pos;

    /** @var list<string>|null */
    public ?array $contents;

    public ?string $face;

    public ?int $width;

    public ?int $height;

    public bool $scrollbar;

    /** @param list<string>|null $contents */
    public function __construct(
        ?CursorPos $pos = null,
        ?array $contents = null,
        ?string $face = null,
        ?int $height = null,
        bool $scrollbar = false,
        ?int $width = null
    ) {
        $this->pos = $pos;
        $this->contents = $contents;
        $this->face = $face;
        $this->height = $height;
        $this->scrollbar = $scrollbar;
        $this->width = $width;
    }
}
