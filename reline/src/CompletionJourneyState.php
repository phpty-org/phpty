<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The cycling-completion cursor, ported from the CompletionJourneyState struct
 * nested in line_editor.rb:46. It carries the line the journey began on, the
 * word being completed (pre/target/post around it), the candidate list (with the
 * original target prepended at index 0), and the pointer into that list. A Ruby
 * Struct is a mutable value object; this final class mirrors it with public
 * fields (the pointer is advanced in place by move_completed_list).
 */
final class CompletionJourneyState
{
    public int $line_index;

    public string $pre;

    public string $target;

    public string $post;

    /** @var list<string> */
    public array $list;

    public int $pointer;

    /** @param list<string> $list */
    public function __construct(int $line_index, string $pre, string $target, string $post, array $list, int $pointer)
    {
        $this->line_index = $line_index;
        $this->pre = $pre;
        $this->target = $target;
        $this->post = $post;
        $this->list = $list;
        $this->pointer = $pointer;
    }
}
