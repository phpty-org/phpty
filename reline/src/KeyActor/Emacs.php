<?php

declare(strict_types=1);

namespace PhPty\Reline\KeyActor;

/**
 * Generated Emacs key map. DO NOT EDIT BY HAND.
 *
 * Source:    references/reline/lib/reline/key_actor/emacs.rb
 * Submodule: reline gem 0.6.3, commit edf8d6b
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
        #   0 ^@
        'em_set_mark',
        #   1 ^A
        'ed_move_to_beg',
        #   2 ^B
        'ed_prev_char',
        #   3 ^C
        'ed_ignore',
        #   4 ^D
        'em_delete',
        #   5 ^E
        'ed_move_to_end',
        #   6 ^F
        'ed_next_char',
        #   7 ^G
        null,
        #   8 ^H
        'em_delete_prev_char',
        #   9 ^I
        'complete',
        #  10 ^J
        'ed_newline',
        #  11 ^K
        'ed_kill_line',
        #  12 ^L
        'ed_clear_screen',
        #  13 ^M
        'ed_newline',
        #  14 ^N
        'ed_next_history',
        #  15 ^O
        'ed_ignore',
        #  16 ^P
        'ed_prev_history',
        #  17 ^Q
        'ed_quoted_insert',
        #  18 ^R
        'vi_search_prev',
        #  19 ^S
        'vi_search_next',
        #  20 ^T
        'ed_transpose_chars',
        #  21 ^U
        'unix_line_discard',
        #  22 ^V
        'ed_quoted_insert',
        #  23 ^W
        'em_kill_region',
        #  24 ^X
        null,
        #  25 ^Y
        'em_yank',
        #  26 ^Z
        'ed_ignore',
        #  27 ^[
        null,
        #  28 ^\
        'ed_ignore',
        #  29 ^]
        'ed_ignore',
        #  30 ^^
        null,
        #  31 ^_
        'undo',
        #  32 SPACE
        'ed_insert',
        #  33 !
        'ed_insert',
        #  34 "
        'ed_insert',
        #  35 #
        'ed_insert',
        #  36 $
        'ed_insert',
        #  37 %
        'ed_insert',
        #  38 &
        'ed_insert',
        #  39 '
        'ed_insert',
        #  40 (
        'ed_insert',
        #  41 )
        'ed_insert',
        #  42 *
        'ed_insert',
        #  43 +
        'ed_insert',
        #  44 ,
        'ed_insert',
        #  45 -
        'ed_insert',
        #  46 .
        'ed_insert',
        #  47 /
        'ed_insert',
        #  48 0
        'ed_digit',
        #  49 1
        'ed_digit',
        #  50 2
        'ed_digit',
        #  51 3
        'ed_digit',
        #  52 4
        'ed_digit',
        #  53 5
        'ed_digit',
        #  54 6
        'ed_digit',
        #  55 7
        'ed_digit',
        #  56 8
        'ed_digit',
        #  57 9
        'ed_digit',
        #  58 :
        'ed_insert',
        #  59 ;
        'ed_insert',
        #  60 <
        'ed_insert',
        #  61 =
        'ed_insert',
        #  62 >
        'ed_insert',
        #  63 ?
        'ed_insert',
        #  64 @
        'ed_insert',
        #  65 A
        'ed_insert',
        #  66 B
        'ed_insert',
        #  67 C
        'ed_insert',
        #  68 D
        'ed_insert',
        #  69 E
        'ed_insert',
        #  70 F
        'ed_insert',
        #  71 G
        'ed_insert',
        #  72 H
        'ed_insert',
        #  73 I
        'ed_insert',
        #  74 J
        'ed_insert',
        #  75 K
        'ed_insert',
        #  76 L
        'ed_insert',
        #  77 M
        'ed_insert',
        #  78 N
        'ed_insert',
        #  79 O
        'ed_insert',
        #  80 P
        'ed_insert',
        #  81 Q
        'ed_insert',
        #  82 R
        'ed_insert',
        #  83 S
        'ed_insert',
        #  84 T
        'ed_insert',
        #  85 U
        'ed_insert',
        #  86 V
        'ed_insert',
        #  87 W
        'ed_insert',
        #  88 X
        'ed_insert',
        #  89 Y
        'ed_insert',
        #  90 Z
        'ed_insert',
        #  91 [
        'ed_insert',
        #  92 \
        'ed_insert',
        #  93 ]
        'ed_insert',
        #  94 ^
        'ed_insert',
        #  95 _
        'ed_insert',
        #  96 `
        'ed_insert',
        #  97 a
        'ed_insert',
        #  98 b
        'ed_insert',
        #  99 c
        'ed_insert',
        # 100 d
        'ed_insert',
        # 101 e
        'ed_insert',
        # 102 f
        'ed_insert',
        # 103 g
        'ed_insert',
        # 104 h
        'ed_insert',
        # 105 i
        'ed_insert',
        # 106 j
        'ed_insert',
        # 107 k
        'ed_insert',
        # 108 l
        'ed_insert',
        # 109 m
        'ed_insert',
        # 110 n
        'ed_insert',
        # 111 o
        'ed_insert',
        # 112 p
        'ed_insert',
        # 113 q
        'ed_insert',
        # 114 r
        'ed_insert',
        # 115 s
        'ed_insert',
        # 116 t
        'ed_insert',
        # 117 u
        'ed_insert',
        # 118 v
        'ed_insert',
        # 119 w
        'ed_insert',
        # 120 x
        'ed_insert',
        # 121 y
        'ed_insert',
        # 122 z
        'ed_insert',
        # 123 {
        'ed_insert',
        # 124 |
        'ed_insert',
        # 125 }
        'ed_insert',
        # 126 ~
        'ed_insert',
        # 127 ^?
        'em_delete_prev_char',
        # 128 M-^@
        null,
        # 129 M-^A
        null,
        # 130 M-^B
        null,
        # 131 M-^C
        null,
        # 132 M-^D
        null,
        # 133 M-^E
        null,
        # 134 M-^F
        null,
        # 135 M-^G
        null,
        # 136 M-^H
        'ed_delete_prev_word',
        # 137 M-^I
        null,
        # 138 M-^J
        'key_newline',
        # 139 M-^K
        null,
        # 140 M-^L
        'ed_clear_screen',
        # 141 M-^M
        'key_newline',
        # 142 M-^N
        null,
        # 143 M-^O
        null,
        # 144 M-^P
        null,
        # 145 M-^Q
        null,
        # 146 M-^R
        null,
        # 147 M-^S
        null,
        # 148 M-^T
        null,
        # 149 M-^U
        null,
        # 150 M-^V
        null,
        # 151 M-^W
        null,
        # 152 M-^X
        null,
        # 153 M-^Y
        null,
        # 154 M-^Z
        null,
        # 155 M-^[
        null,
        # 156 M-^\
        null,
        # 157 M-^]
        null,
        # 158 M-^^
        null,
        # 159 M-^_
        'redo',
        # 160 M-SPACE
        'em_set_mark',
        # 161 M-!
        null,
        # 162 M-"
        null,
        # 163 M-#
        null,
        # 164 M-$
        null,
        # 165 M-%
        null,
        # 166 M-&
        null,
        # 167 M-'
        null,
        # 168 M-(
        null,
        # 169 M-)
        null,
        # 170 M-*
        null,
        # 171 M-+
        null,
        # 172 M-,
        null,
        # 173 M--
        null,
        # 174 M-.
        null,
        # 175 M-/
        null,
        # 176 M-0
        'ed_argument_digit',
        # 177 M-1
        'ed_argument_digit',
        # 178 M-2
        'ed_argument_digit',
        # 179 M-3
        'ed_argument_digit',
        # 180 M-4
        'ed_argument_digit',
        # 181 M-5
        'ed_argument_digit',
        # 182 M-6
        'ed_argument_digit',
        # 183 M-7
        'ed_argument_digit',
        # 184 M-8
        'ed_argument_digit',
        # 185 M-9
        'ed_argument_digit',
        # 186 M-:
        null,
        # 187 M-;
        null,
        # 188 M-<
        'beginning_of_history',
        # 189 M-=
        null,
        # 190 M->
        'end_of_history',
        # 191 M-?
        null,
        # 192 M-@
        null,
        # 193 M-A
        null,
        # 194 M-B
        'ed_prev_word',
        # 195 M-C
        'em_capitol_case',
        # 196 M-D
        'em_delete_next_word',
        # 197 M-E
        null,
        # 198 M-F
        'em_next_word',
        # 199 M-G
        null,
        # 200 M-H
        null,
        # 201 M-I
        null,
        # 202 M-J
        null,
        # 203 M-K
        null,
        # 204 M-L
        'em_lower_case',
        # 205 M-M
        null,
        # 206 M-N
        'vi_search_next',
        # 207 M-O
        null,
        # 208 M-P
        'vi_search_prev',
        # 209 M-Q
        null,
        # 210 M-R
        null,
        # 211 M-S
        null,
        # 212 M-T
        null,
        # 213 M-U
        'em_upper_case',
        # 214 M-V
        null,
        # 215 M-W
        null,
        # 216 M-X
        null,
        # 217 M-Y
        'em_yank_pop',
        # 218 M-Z
        null,
        # 219 M-[
        null,
        # 220 M-\
        null,
        # 221 M-]
        null,
        # 222 M-^
        null,
        # 223 M-_
        null,
        # 224 M-`
        null,
        # 225 M-a
        null,
        # 226 M-b
        'ed_prev_word',
        # 227 M-c
        'em_capitol_case',
        # 228 M-d
        'em_delete_next_word',
        # 229 M-e
        null,
        # 230 M-f
        'em_next_word',
        # 231 M-g
        null,
        # 232 M-h
        null,
        # 233 M-i
        null,
        # 234 M-j
        null,
        # 235 M-k
        null,
        # 236 M-l
        'em_lower_case',
        # 237 M-m
        null,
        # 238 M-n
        'vi_search_next',
        # 239 M-o
        null,
        # 240 M-p
        'vi_search_prev',
        # 241 M-q
        null,
        # 242 M-r
        null,
        # 243 M-s
        null,
        # 244 M-t
        'ed_transpose_words',
        # 245 M-u
        'em_upper_case',
        # 246 M-v
        null,
        # 247 M-w
        null,
        # 248 M-x
        null,
        # 249 M-y
        'em_yank_pop',
        # 250 M-z
        null,
        # 251 M-{
        null,
        # 252 M-|
        null,
        # 253 M-}
        null,
        # 254 M-~
        null,
        # 255 M-^?
        'ed_delete_prev_word',
    ];
}
