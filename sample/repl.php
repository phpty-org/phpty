#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * A small, deliberately naive PHP REPL built to show off phpty/reline.
 *
 * "readline を使う以外の部分は素朴に作る" — the line editing (history recall,
 * emacs keys, Tab completion, C-r search, ...) comes entirely from reline;
 * everything else here (the eval loop, the scope handling, the completion
 * word list) is written as simply as possible. It is a demo, not a real
 * REPL implementation — see the README for the "why is eval() ok here" note.
 *
 * This file lives in sample/ at the monorepo root and is NOT a module: no
 * composer.json of its own, no split, no tests. It runs straight off the
 * root autoload, which already maps PhPty\Reline\ and PhPty\Tty\.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhPty\Reline\Reline;

// ---------------------------------------------------------------------
// Naive persistent scope.
//
// eval() runs in the scope of the function that calls it. To make
// `$x = 41` on one line visible to `$x + 1` on the next, we keep the
// REPL's variables in a plain associative array ($scope) and run each
// eval inside a tiny helper that:
//   1. extract()s $scope into local variables just before evaluating,
//   2. evaluates the user's line,
//   3. captures every local variable again with get_defined_vars() and
//      folds it back into $scope.
// This is the simplest correct way to carry state across eval() calls
// without reaching for globals or a class with dynamic properties.
// ---------------------------------------------------------------------
$scope = [];

/**
 * Evaluate one line of PHP against $scope, naive-REPL style.
 *
 * Strategy: try it as an *expression* first (`return <line>;`) so that
 * `41 + 1` or `$x + 1` prints its value. If that's a parse error (e.g. the
 * line is a statement like `$x = 5;` or `foreach (...) { ... }`), fall
 * back to evaluating it as statements. Either path can also throw at
 * runtime (undefined function, division by zero, etc.) — both are caught
 * so the REPL survives and keeps looping.
 *
 * @param array<string, mixed> $scope
 * @return array{0: mixed, 1: bool, 2: array<string, mixed>} [value, wasExpression, newScope]
 */
function repl_eval(string $line, array $scope): array
{
    // Bring prior variables into this function's local scope so the
    // eval'd code can see and update them.
    extract($scope);

    $wasExpression = true;
    try {
        $value = eval('return ' . $line . ';');
    } catch (\ParseError) {
        // Not a bare expression (e.g. `$x = 5;`, `if (...) {...}`) — run
        // it as statements instead. No return value in this path.
        $wasExpression = false;
        $value = eval($line);
    }

    // Fold whatever ended up as local variables back into $scope so the
    // next line sees them too. get_defined_vars() includes this
    // function's own parameters, so drop those back out.
    $after = get_defined_vars();
    unset($after['line'], $after['scope'], $after['wasExpression'], $after['value']);

    return [$value, $wasExpression, $after];
}

// ---------------------------------------------------------------------
// Completion: naive word list + the user's own variables.
//
// reline calls the completion proc with the word fragment under the
// cursor (arity-1 form of call_completion_proc_with_checking_args, see
// reline/src/LineEditor.php). We return every candidate — fixed tokens
// plus "$name" for each variable currently in $scope — that starts with
// that fragment; reline handles narrowing, cycling and inserting.
// ---------------------------------------------------------------------
$keywords = [
    'function', 'return', 'echo', 'foreach', 'count', 'array_map',
    'strlen', 'var_dump', 'true', 'false', 'null', 'exit',
];

Reline::set_completion_proc(function (string $target) use (&$scope, $keywords): array {
    $candidates = $keywords;
    foreach (array_keys($scope) as $name) {
        $candidates[] = '$' . $name;
    }

    return array_values(array_filter(
        $candidates,
        static fn (string $c): bool => $target !== '' && str_starts_with($c, $target),
    ));
});

// After completing to the one remaining candidate, append a space so you
// can keep typing without hitting space yourself.
Reline::set_completion_append_character(' ');

// The live dropdown-style completion dialog. Tried it against a real
// terminal while building this sample and it behaved fine (no flicker,
// draws under the cursor, clears cleanly) — left on to show it off. If
// it ever looks noisy in your terminal, comment this line out; Tab-based
// completion above still works without it.
Reline::set_autocompletion(true);

// ---------------------------------------------------------------------
// Banner
// ---------------------------------------------------------------------
echo "PhPty::Reline sample REPL\n";
echo "Line editing (history via \xe2\x86\x91/\xe2\x86\x93, Tab completion, emacs keys, C-r search) is provided by phpty/reline.\n";
echo "The eval loop itself is intentionally naive -- see sample/README.md.\n";
echo "Type 'exit' or 'quit' or press Ctrl-D to leave.\n\n";

// ---------------------------------------------------------------------
// The loop.
// ---------------------------------------------------------------------
while (true) {
    // add_history=true records the line so ↑ recalls it and C-r can find it.
    $line = Reline::readline('>>> ', true);

    if ($line === null) {
        // Ctrl-D on an empty line: reline's own EOF signal.
        echo "\n";
        break;
    }

    $trimmed = trim($line);

    if ($trimmed === 'exit' || $trimmed === 'quit') {
        break;
    }

    if ($trimmed === '') {
        continue;
    }

    try {
        [$value, $wasExpression, $scope] = repl_eval($trimmed, $scope);
    } catch (\Throwable $e) {
        // A REPL must never die on a bad line -- print the error and
        // keep going, same as every real REPL does.
        echo '// Error: ' . $e->getMessage() . "\n";
        continue;
    }

    // Skip printing a bare `null` that came from a *statement* (e.g.
    // `$x = 5;` naturally has no useful "return value"); always print
    // the value of a genuine expression, even when it happens to be null.
    if ($wasExpression || $value !== null) {
        echo var_export($value, true) . "\n";
    }
}
