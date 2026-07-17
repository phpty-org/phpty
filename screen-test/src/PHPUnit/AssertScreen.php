<?php

declare(strict_types=1);

namespace PhPty\ScreenTest\PHPUnit;

use PhPty\ScreenTest\Session;

/**
 * A thin PHPUnit integration for the framework-agnostic Session. Mix it into a
 * TestCase to assert on a rendered Screen. It calls assertion methods it does
 * not own (assertSame, from the TestCase it is used in), so PHPUnit stays a
 * `suggest` of this package, never a `require` — see
 * docs/adr/0010-testing-spans-74-to-85-via-polyfills.md.
 */
trait AssertScreen
{
    /**
     * Drain any pending output, then assert the Screen renders as expected.
     *
     * @param list<string> $expected the lines a human should see
     */
    protected function assertScreen(array $expected, Session $session): void
    {
        $session->sync();

        // assertSame is provided by the PHPUnit TestCase this trait is mixed into.
        $this->assertSame($expected, $session->render());
    }
}
