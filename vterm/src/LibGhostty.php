<?php

declare(strict_types=1);

namespace PhPty\VTerm;

use FFI;

/**
 * Loads libghostty-vt and declares the slice of its C ABI that VTerm uses.
 *
 * The declarations are transcribed from the pinned headers (ghostty/vt/*.h).
 * Only what VTerm needs is declared; the surface grows as VTerm does. See
 * docs/adr/0002-vterm-binds-libghostty-vt.md.
 */
final class LibGhostty
{
    private const CDEF = <<<'C'
        typedef void *GhosttyTerminal;
        typedef int GhosttyResult;
        typedef int GhosttyPointTag;

        typedef struct {
            uint16_t cols;
            uint16_t rows;
            size_t max_scrollback;
        } GhosttyTerminalOptions;

        // The header declares value as a union { GhosttyPointCoordinate; uint64_t[2]; }.
        // PHP 7.4's FFI cannot pass a struct containing a union by value — it is
        // what breaks ghostty_terminal_grid_ref there. We only ever use the
        // coordinate variant, so value is declared as an ABI-equivalent struct
        // (16 bytes, same layout) with no union. See VTerm::cellAt.
        typedef struct {
            uint16_t x;
            uint32_t y;
            uint64_t _reserved;
        } GhosttyPointValue;

        typedef struct {
            GhosttyPointTag tag;
            GhosttyPointValue value;
        } GhosttyPoint;

        typedef struct {
            size_t size;
            void *node;
            uint16_t x;
            uint16_t y;
        } GhosttyGridRef;

        typedef uint64_t GhosttyCell;
        typedef int GhosttyCellData;
        typedef int GhosttyCellWide;
        typedef int GhosttyTerminalOption;
        typedef void (*GhosttyTerminalWritePtyFn)(GhosttyTerminal terminal, void *userdata, const uint8_t *data, size_t len);

        GhosttyResult ghostty_terminal_new(const void *allocator, GhosttyTerminal *terminal, GhosttyTerminalOptions options);
        GhosttyResult ghostty_terminal_set(GhosttyTerminal terminal, GhosttyTerminalOption option, GhosttyTerminalWritePtyFn value);
        void ghostty_terminal_free(GhosttyTerminal terminal);
        void ghostty_terminal_vt_write(GhosttyTerminal terminal, const uint8_t *data, size_t len);
        GhosttyResult ghostty_terminal_grid_ref(GhosttyTerminal terminal, GhosttyPoint point, GhosttyGridRef *out_ref);
        GhosttyResult ghostty_grid_ref_graphemes(const GhosttyGridRef *ref, uint32_t *buf, size_t buf_len, size_t *out_len);
        GhosttyResult ghostty_grid_ref_cell(const GhosttyGridRef *ref, GhosttyCell *out_cell);
        GhosttyResult ghostty_cell_get(GhosttyCell cell, GhosttyCellData data, void *out);
        C;

    public static function load(?string $libraryDir = null): FFI
    {
        $libraryDir = $libraryDir ?? (getenv('PHPTY_LIBGHOSTTY_VT') ?: null);
        if ($libraryDir === null) {
            throw new \RuntimeException(
                'libghostty-vt not found: set PHPTY_LIBGHOSTTY_VT to the directory containing it '
                . '(the Nix dev shell sets this automatically).'
            );
        }

        $extension = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $path = $libraryDir . '/libghostty-vt.' . $extension;
        if (!is_file($path)) {
            throw new \RuntimeException("libghostty-vt shared object not found at {$path}.");
        }

        return FFI::cdef(self::CDEF, $path);
    }
}
