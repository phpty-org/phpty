<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\Tty\SttyRunner;

/**
 * Records every stty argument list and returns canned output, so SttyBackendTest
 * can assert the arguments the Backend builds without a real Tty — the seam
 * SttyRunner exists for.
 */
final class FakeSttyRunner implements SttyRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var array<string, string> */
    private array $responses;

    /**
     * @param array<string, string> $responses stdout keyed by the first argument
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function run(array $args): string
    {
        $this->calls[] = $args;

        return $this->responses[$args[0] ?? ''] ?? '';
    }
}
