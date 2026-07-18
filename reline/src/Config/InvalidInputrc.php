<?php

declare(strict_types=1);

namespace PhPty\Reline\Config;

/**
 * Raised while parsing an inputrc, ported from `Reline::Config::InvalidInputrc`
 * (config.rb:6-8). Carries the offending file and line number; `read` catches it,
 * warns, and returns null, so a malformed inputrc never aborts startup.
 *
 * Upstream's `attr_accessor :file, :lineno` become `inputrcFile` / `lineno` here:
 * \Exception already reserves `$file` and `$line` (its own source location), so the
 * inputrc coordinates are stored under non-colliding names.
 */
final class InvalidInputrc extends \RuntimeException
{
    public ?string $inputrcFile = null;

    public ?int $lineno = null;
}
