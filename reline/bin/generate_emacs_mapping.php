<?php

declare(strict_types=1);

/*
 * Regenerates reline/src/KeyActor/Emacs.php from upstream's Ruby EMACS_MAPPING.
 *
 * The table is 256 entries long: a byte-value -> editing-command index, with the
 * high half (128..255) being the Meta/ESC-prefixed rows. Transcribing 256 lines
 * by hand is where a stray nil creeps in, so it is generated. Ruby method
 * symbols (`:ed_insert`) become PHP strings kept snake_case verbatim; `nil`
 * becomes null. The per-entry `# NNN key` comments are preserved so the emitted
 * file diffs line-for-line against upstream.
 *
 * Usage: php reline/bin/generate_emacs_mapping.php
 */

$root = dirname(__DIR__, 2);
$source = $root . '/references/reline/lib/reline/key_actor/emacs.rb';
$target = dirname(__DIR__) . '/src/KeyActor/Emacs.php';

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
    fwrite(STDERR, 'Expected 256 entries, parsed ' . count($entries) . "\n");
    exit(1);
}

$body = '';
foreach ($entries as $entry) {
    if ($entry['comment'] !== null) {
        $body .= '        ' . $entry['comment'] . "\n";
    }
    $literal = $entry['value'] === null ? 'null' : "'" . $entry['value'] . "'";
    $body .= '        ' . $literal . ",\n";
}

$commit = trim((string) shell_exec(
    'git -C ' . escapeshellarg(dirname($source)) . ' rev-parse --short HEAD 2>/dev/null'
));
if ($commit === '') {
    $commit = 'edf8d6b';
}

$php = <<<PHP
<?php

declare(strict_types=1);

namespace PhPty\\Reline\\KeyActor;

/**
 * Generated Emacs key map. DO NOT EDIT BY HAND.
 *
 * Source:    references/reline/lib/reline/key_actor/emacs.rb
 * Submodule: reline gem 0.6.3, commit {$commit}
 * Generator: reline/bin/generate_emacs_mapping.php
 *
 * A 256-slot table indexed by byte value: 0..127 are the plain keys, 128..255
 * the Meta (ESC-prefixed) rows. Each slot names the editing command bound to
 * that byte, or null for unbound. Command names are upstream's Ruby symbols kept
 * snake_case (ADR-0005): a diff of upstream's table maps onto a diff of this one.
 */
final class Emacs
{
    /** @var array<int, string|null> */
    public const MAPPING = [
{$body}    ];
}

PHP;

file_put_contents($target, $php);
fwrite(STDERR, "Wrote 256 entries to {$target}\n");
