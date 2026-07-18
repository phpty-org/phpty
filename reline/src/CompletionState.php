<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The completion-state labels, ported from the CompletionState module nested in
 * line_editor.rb:37-42. Upstream's Ruby Symbols become plain string constants
 * here (the Symbols-to-strings mapping in CONTEXT.md); @completion_state walks
 * NORMAL -> MENU_WITH_PERFECT_MATCH / MENU -> PERFECT_MATCH across repeated Tab
 * presses, exactly as upstream's perform_completion drives it.
 */
final class CompletionState
{
    public const NORMAL = 'normal';

    public const MENU = 'menu';

    public const MENU_WITH_PERFECT_MATCH = 'menu_with_perfect_match';

    public const PERFECT_MATCH = 'perfect_match';

    private function __construct()
    {
    }
}
