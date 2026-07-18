<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\Core;
use PhPty\Reline\IO\Ansi;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit-tests Core::read_io's ESC disambiguation (reline.rb:378-406) with a
 * scripted gate: a lone ESC that "times out" (the scripted queue drains) resolves
 * to the bare-ESC binding, while ESC-[-A arriving in full resolves to the arrow
 * key. No real waits — the scripted gate returning null is exactly the timeout
 * signal read_io acts on when a match is pending.
 */
final class KeyseqTimeoutTest extends TestCase
{
    private function coreOver(array $bytes): Core
    {
        $config = new Config();
        // Register the CSI/SS3 arrow, home, and end bindings the way inner_readline
        // does — set_default_key_bindings only mutates the config, no terminal
        // needed — so ESC-[-A resolves to the arrow binding.
        (new Ansi())->set_default_key_bindings($config);

        return new Core(new ScriptedIO($bytes), $config);
    }

    public function testLoneEscTimesOutToBareEsc(): void
    {
        // [27] is MATCHING_MATCHED (a bare ESC that could still grow); with no
        // further byte the timeout resolves it to the ESC binding, ed_ignore.
        $keys = $this->coreOver([27])->read_keys(500);

        $this->assertCount(1, $keys);
        $this->assertSame('ed_ignore', $keys[0]->method_symbol);
    }

    public function testFullArrowSequenceResolvesToArrow(): void
    {
        // ESC [ A completes before any timeout is needed: MATCHING then MATCHED.
        $keys = $this->coreOver([27, 91, 65])->read_keys(500);

        $this->assertCount(1, $keys);
        $this->assertSame('ed_prev_history', $keys[0]->method_symbol);
    }

    public function testEmptyInputAtEofYieldsTheEofKey(): void
    {
        // Nothing scripted: read_io reports EOF as the null Key (char and symbol
        // both null), which drives em_delete/finish upstream.
        $keys = $this->coreOver([])->read_keys(500);

        $this->assertCount(1, $keys);
        $this->assertNull($keys[0]->char);
        $this->assertNull($keys[0]->method_symbol);
    }
}
