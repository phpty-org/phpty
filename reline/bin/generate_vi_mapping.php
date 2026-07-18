<?php

declare(strict_types=1);

/*
 * Regenerates reline/src/KeyActor/ViInsert.php and ViCommand.php from upstream's
 * Ruby VI_INSERT_MAPPING and VI_COMMAND_MAPPING.
 *
 * This is the sibling of bin/generate_emacs_mapping.php — same parse and same
 * emitter, only with two 256-slot tables instead of one, so the vi keymaps stay
 * as diffable against upstream as the emacs one. Ruby method symbols
 * (`:vi_insert`) become PHP strings kept snake_case verbatim; `nil` becomes null.
 * The per-entry `# NNN key` comments are preserved so the emitted files diff
 * line-for-line against upstream. Idempotent: re-running produces byte-identical
 * output.
 *
 * Usage: php reline/bin/generate_vi_mapping.php
 */

$root = dirname(__DIR__, 2);

/**
 * Parse a 256-slot Ruby mapping table into [['comment' => ?string, 'value' => ?string], ...].
 *
 * @return list<array{comment: string|null, value: string|null}>
 */
function parse_mapping(string $source): array
{
    $lines = file($source, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Cannot read {$source}\n");
        exit(1);
    }

    $entries = [];
    $pendingComment = null;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            // Upstream annotates every slot with `# NNN key`; carry it across.
            if (strpos($trimmed, '#') === 0 && preg_match('/^#\s+\d+\s/', $trimmed)) {
                $pendingComment = $trimmed;
            }
            continue;
        }
        if (preg_match('/^(:([a-z_]+)|nil),?$/', $trimmed, $m)) {
            $value = $m[1] === 'nil' ? null : $m[2];
            $entries[] = ['comment' => $pendingComment, 'value' => $value];
            $pendingComment = null;
        }
    }

    if (count($entries) !== 256) {
        fwrite(STDERR, "Expected 256 entries in {$source}, parsed " . count($entries) . "\n");
        exit(1);
    }

    return $entries;
}

/**
 * @param list<array{comment: string|null, value: string|null}> $entries
 */
function emit_table(string $source, string $target, string $className, string $constName, string $commit, array $entries): void
{
    $body = '';
    foreach ($entries as $entry) {
        if ($entry['comment'] !== null) {
            $body .= '        ' . $entry['comment'] . "\n";
        }
        $literal = $entry['value'] === null ? 'null' : "'" . $entry['value'] . "'";
        $body .= '        ' . $literal . ",\n";
    }

    $relSource = 'references/reline/lib/reline/key_actor/' . basename($source);

    $php = <<<PHP
<?php

declare(strict_types=1);

namespace PhPty\\Reline\\KeyActor;

/**
 * Generated {$className} key map. DO NOT EDIT BY HAND.
 *
 * Source:    {$relSource}
 * Submodule: reline gem 0.6.3, commit {$commit}
 * Generator: reline/bin/generate_vi_mapping.php
 *
 * A 256-slot table indexed by byte value: 0..127 are the plain keys, 128..255
 * the Meta (ESC-prefixed) rows. Each slot names the editing command bound to
 * that byte, or null for unbound. Command names are upstream's Ruby symbols kept
 * snake_case (ADR-0005): a diff of upstream's table maps onto a diff of this one.
 */
final class {$className}
{
    /** @var array<int, string|null> */
    public const {$constName} = [
{$body}    ];
}

PHP;

    file_put_contents($target, $php);
    fwrite(STDERR, "Wrote 256 entries to {$target}\n");
}

$insertSource = $root . '/references/reline/lib/reline/key_actor/vi_insert.rb';
$commandSource = $root . '/references/reline/lib/reline/key_actor/vi_command.rb';

$commit = trim((string) shell_exec(
    'git -C ' . escapeshellarg(dirname($insertSource)) . ' rev-parse --short HEAD 2>/dev/null'
));
if ($commit === '') {
    $commit = 'edf8d6b';
}

emit_table(
    $insertSource,
    dirname(__DIR__) . '/src/KeyActor/ViInsert.php',
    'ViInsert',
    'MAPPING',
    $commit,
    parse_mapping($insertSource),
);

emit_table(
    $commandSource,
    dirname(__DIR__) . '/src/KeyActor/ViCommand.php',
    'ViCommand',
    'MAPPING',
    $commit,
    parse_mapping($commandSource),
);
