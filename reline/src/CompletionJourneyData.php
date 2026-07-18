<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The view of the current completion journey a dialog proc consumes, ported from
 * the CompletionJourneyData struct nested in DialogProcScope (line_editor.rb:576).
 * dialog_proc_scope_completion_journey_data builds it from the live
 * CompletionJourneyState, joining the surrounding buffer lines into preposing /
 * postposing so the autocomplete proc can position itself and read the list.
 */
final class CompletionJourneyData
{
    public string $preposing;

    public string $postposing;

    /** @var list<string> */
    public array $list;

    public int $pointer;

    /** @param list<string> $list */
    public function __construct(string $preposing, string $postposing, array $list, int $pointer)
    {
        $this->preposing = $preposing;
        $this->postposing = $postposing;
        $this->list = $list;
        $this->pointer = $pointer;
    }
}
