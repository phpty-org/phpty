<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\KeyActor\Base;
use PhPty\Reline\KeyActor\ViCommand;
use PhPty\Reline\KeyActor\ViInsert;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Integrity checks on the two generated vi key maps and the Base trie that
 * indexes them. Guards the mechanical transcription of vi_insert.rb /
 * vi_command.rb: a specific byte must resolve to the command upstream binds it to.
 * Sibling of EmacsMappingTest.
 */
final class ViMappingTest extends TestCase
{
    public function testEntryCountsMatchUpstream(): void
    {
        // Both upstream tables are flat 256-slot arrays (0..127 plain, 128..255 Meta).
        $this->assertCount(256, ViInsert::MAPPING);
        $this->assertCount(256, ViCommand::MAPPING);
    }

    public function testViInsertBindsPrintablesToEdInsert(): void
    {
        $keymap = new Base(ViInsert::MAPPING);

        // Almost every printable inserts; the notable controls do their own thing.
        $this->assertSame('ed_insert', $keymap->get([0x61])); // 'a'
        $this->assertSame('ed_insert', $keymap->get([0x20])); // space
        $this->assertSame('ed_digit', $keymap->get([0x30])); // '0'
        $this->assertSame('vi_command_mode', $keymap->get([0x1B])); // ^[ leaves insert mode
        $this->assertSame('vi_delete_prev_char', $keymap->get([0x08])); // ^H
        $this->assertSame('vi_delete_prev_char', $keymap->get([0x7F])); // ^?
        $this->assertSame('vi_list_or_eof', $keymap->get([0x04])); // ^D
        $this->assertSame('complete', $keymap->get([0x09])); // ^I
        $this->assertSame('menu_complete', $keymap->get([0x0E])); // ^N
        $this->assertSame('menu_complete_backward', $keymap->get([0x10])); // ^P
    }

    public function testViCommandBindsMotionsAndOperators(): void
    {
        $keymap = new Base(ViCommand::MAPPING);

        $this->assertSame('ed_prev_char', $keymap->get([0x68])); // h
        $this->assertSame('ed_next_char', $keymap->get([0x6C])); // l
        $this->assertSame('ed_next_history', $keymap->get([0x6A])); // j
        $this->assertSame('ed_prev_history', $keymap->get([0x6B])); // k
        $this->assertSame('vi_next_word', $keymap->get([0x77])); // w
        $this->assertSame('vi_prev_word', $keymap->get([0x62])); // b
        $this->assertSame('vi_end_word', $keymap->get([0x65])); // e
        $this->assertSame('vi_delete_meta', $keymap->get([0x64])); // d
        $this->assertSame('vi_change_meta', $keymap->get([0x63])); // c
        $this->assertSame('vi_yank', $keymap->get([0x79])); // y
        $this->assertSame('vi_insert', $keymap->get([0x69])); // i
        $this->assertSame('vi_add', $keymap->get([0x61])); // a
        $this->assertSame('vi_paste_next', $keymap->get([0x70])); // p
        $this->assertSame('vi_paste_prev', $keymap->get([0x50])); // P
        $this->assertSame('vi_zero', $keymap->get([0x30])); // 0
        $this->assertSame('ed_argument_digit', $keymap->get([0x31])); // 1
        $this->assertSame('vi_to_column', $keymap->get([0x7C])); // |
        $this->assertSame('vi_first_print', $keymap->get([0x5E])); // ^
        $this->assertSame('ed_delete_next_char', $keymap->get([0x78])); // x
        $this->assertSame('ed_delete_prev_char', $keymap->get([0x58])); // X
        $this->assertSame('vi_replace_char', $keymap->get([0x72])); // r
        $this->assertSame('vi_next_char', $keymap->get([0x66])); // f
        $this->assertSame('vi_to_next_char', $keymap->get([0x74])); // t
        $this->assertSame('vi_prev_char', $keymap->get([0x46])); // F
        $this->assertSame('vi_to_prev_char', $keymap->get([0x54])); // T
        $this->assertSame('vi_histedit', $keymap->get([0x76])); // v
        $this->assertSame('em_delete_prev_char', $keymap->get([0x7F])); // ^?
    }

    public function testViCommandUnboundSlots(): void
    {
        $keymap = new Base(ViCommand::MAPPING);

        // vi_alias / vi_comment_out are referenced but unimplemented; still bound.
        $this->assertSame('vi_alias', $keymap->get([0x40])); // @
        $this->assertSame('vi_comment_out', $keymap->get([0x23])); // #
        // Genuinely unbound slots stay null.
        $this->assertNull($keymap->get([0x71])); // q
        $this->assertNull($keymap->get([0x7E])); // ~
    }
}
