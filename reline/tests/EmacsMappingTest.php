<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\KeyActor\Base;
use PhPty\Reline\KeyActor\Emacs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Integrity checks on the generated Emacs key map and the Base trie that indexes
 * it. Guards the mechanical transcription: a specific byte sequence must resolve
 * to the command upstream binds it to.
 */
final class EmacsMappingTest extends TestCase
{
    public function testEntryCountMatchesUpstream(): void
    {
        // Upstream EMACS_MAPPING is a flat 256-slot table (0..127 plain, 128..255 Meta).
        $this->assertCount(256, Emacs::MAPPING);
    }

    public function testPlainKeysResolveToUpstreamCommands(): void
    {
        $keymap = new Base(Emacs::MAPPING);

        $this->assertSame('ed_move_to_beg', $keymap->get([0x01])); // ^A
        $this->assertSame('ed_prev_char', $keymap->get([0x02])); // ^B
        $this->assertSame('ed_move_to_end', $keymap->get([0x05])); // ^E
        $this->assertSame('complete', $keymap->get([0x09])); // ^I / Tab
        $this->assertSame('ed_newline', $keymap->get([0x0D])); // ^M / Enter
        $this->assertSame('ed_kill_line', $keymap->get([0x0B])); // ^K
        $this->assertSame('ed_insert', $keymap->get([0x61])); // 'a'
        $this->assertSame('ed_digit', $keymap->get([0x30])); // '0'
        $this->assertSame('em_delete_prev_char', $keymap->get([0x7F])); // ^?
    }

    public function testMetaKeysBecomeEscPrefixedSequences(): void
    {
        $keymap = new Base(Emacs::MAPPING);

        // Slot k|0x80 is emitted as the two-byte sequence [27, k].
        $this->assertSame('em_next_word', $keymap->get([27, 0x66])); // M-f
        $this->assertSame('ed_prev_word', $keymap->get([27, 0x62])); // M-b
        $this->assertSame('em_upper_case', $keymap->get([27, 0x75])); // M-u
        $this->assertSame('ed_delete_prev_word', $keymap->get([27, 0x7F])); // M-^?
    }

    public function testUnboundAndPrefixBehaviour(): void
    {
        $keymap = new Base(Emacs::MAPPING);

        $this->assertNull($keymap->get([0x07])); // ^G is unbound (nil upstream)
        $this->assertSame('ed_ignore', $keymap->get([27])); // bare ESC
        // ESC is a prefix of every Meta sequence, so it must report as matching.
        $this->assertTrue($keymap->matching([27]));
        $this->assertFalse($keymap->matching([0x61])); // 'a' leads nowhere longer
    }
}
