<?php

declare(strict_types=1);

namespace PhPty\Reline\KeyActor;

/**
 * Generated ViCommand key map. DO NOT EDIT BY HAND.
 *
 * Source:    references/reline/lib/reline/key_actor/vi_command.rb
 * Submodule: reline gem 0.6.3, commit edf8d6b
 * Generator: reline/bin/generate_vi_mapping.php
 *
 * A 256-slot table indexed by byte value: 0..127 are the plain keys, 128..255
 * the Meta (ESC-prefixed) rows. Each slot names the editing command bound to
 * that byte, or null for unbound. Command names are upstream's Ruby symbols kept
 * snake_case (ADR-0005): a diff of upstream's table maps onto a diff of this one.
 */
final class ViCommand
{
    /** @var array<int, string|null> */
    public const MAPPING = [
        #   0 ^@
        null,
        #   1 ^A
        'ed_move_to_beg',
        #   2 ^B
        null,
        #   3 ^C
        'ed_ignore',
        #   4 ^D
        'vi_end_of_transmission',
        #   5 ^E
        'ed_move_to_end',
        #   6 ^F
        null,
        #   7 ^G
        null,
        #   8 ^H
        'ed_prev_char',
        #   9 ^I
        null,
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
        'ed_ignore',
        #  18 ^R
        'vi_search_prev',
        #  19 ^S
        'ed_ignore',
        #  20 ^T
        'ed_transpose_chars',
        #  21 ^U
        'vi_kill_line_prev',
        #  22 ^V
        'ed_quoted_insert',
        #  23 ^W
        'ed_delete_prev_word',
        #  24 ^X
        null,
        #  25 ^Y
        'em_yank',
        #  26 ^Z
        null,
        #  27 ^[
        null,
        #  28 ^\
        'ed_ignore',
        #  29 ^]
        null,
        #  30 ^^
        null,
        #  31 ^_
        null,
        #  32 SPACE
        'ed_next_char',
        #  33 !
        null,
        #  34 "
        null,
        #  35 #
        'vi_comment_out',
        #  36 $
        'ed_move_to_end',
        #  37 %
        null,
        #  38 &
        null,
        #  39 '
        null,
        #  40 (
        null,
        #  41 )
        null,
        #  42 *
        null,
        #  43 +
        'ed_next_history',
        #  44 ,
        null,
        #  45 -
        'ed_prev_history',
        #  46 .
        null,
        #  47 /
        'vi_search_prev',
        #  48 0
        'vi_zero',
        #  49 1
        'ed_argument_digit',
        #  50 2
        'ed_argument_digit',
        #  51 3
        'ed_argument_digit',
        #  52 4
        'ed_argument_digit',
        #  53 5
        'ed_argument_digit',
        #  54 6
        'ed_argument_digit',
        #  55 7
        'ed_argument_digit',
        #  56 8
        'ed_argument_digit',
        #  57 9
        'ed_argument_digit',
        #  58 :
        null,
        #  59 ;
        null,
        #  60 <
        null,
        #  61 =
        null,
        #  62 >
        null,
        #  63 ?
        'vi_search_next',
        #  64 @
        'vi_alias',
        #  65 A
        'vi_add_at_eol',
        #  66 B
        'vi_prev_big_word',
        #  67 C
        'vi_change_to_eol',
        #  68 D
        'ed_kill_line',
        #  69 E
        'vi_end_big_word',
        #  70 F
        'vi_prev_char',
        #  71 G
        'vi_to_history_line',
        #  72 H
        null,
        #  73 I
        'vi_insert_at_bol',
        #  74 J
        'vi_join_lines',
        #  75 K
        'vi_search_prev',
        #  76 L
        null,
        #  77 M
        null,
        #  78 N
        null,
        #  79 O
        null,
        #  80 P
        'vi_paste_prev',
        #  81 Q
        null,
        #  82 R
        null,
        #  83 S
        null,
        #  84 T
        'vi_to_prev_char',
        #  85 U
        null,
        #  86 V
        null,
        #  87 W
        'vi_next_big_word',
        #  88 X
        'ed_delete_prev_char',
        #  89 Y
        null,
        #  90 Z
        null,
        #  91 [
        null,
        #  92 \
        null,
        #  93 ]
        null,
        #  94 ^
        'vi_first_print',
        #  95 _
        null,
        #  96 `
        null,
        #  97 a
        'vi_add',
        #  98 b
        'vi_prev_word',
        #  99 c
        'vi_change_meta',
        # 100 d
        'vi_delete_meta',
        # 101 e
        'vi_end_word',
        # 102 f
        'vi_next_char',
        # 103 g
        null,
        # 104 h
        'ed_prev_char',
        # 105 i
        'vi_insert',
        # 106 j
        'ed_next_history',
        # 107 k
        'ed_prev_history',
        # 108 l
        'ed_next_char',
        # 109 m
        null,
        # 110 n
        null,
        # 111 o
        null,
        # 112 p
        'vi_paste_next',
        # 113 q
        null,
        # 114 r
        'vi_replace_char',
        # 115 s
        null,
        # 116 t
        'vi_to_next_char',
        # 117 u
        null,
        # 118 v
        'vi_histedit',
        # 119 w
        'vi_next_word',
        # 120 x
        'ed_delete_next_char',
        # 121 y
        'vi_yank',
        # 122 z
        null,
        # 123 {
        null,
        # 124 |
        'vi_to_column',
        # 125 }
        null,
        # 126 ~
        null,
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
        null,
        # 137 M-^I
        null,
        # 138 M-^J
        null,
        # 139 M-^K
        null,
        # 140 M-^L
        null,
        # 141 M-^M
        null,
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
        null,
        # 160 M-SPACE
        null,
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
        null,
        # 177 M-1
        null,
        # 178 M-2
        null,
        # 179 M-3
        null,
        # 180 M-4
        null,
        # 181 M-5
        null,
        # 182 M-6
        null,
        # 183 M-7
        null,
        # 184 M-8
        null,
        # 185 M-9
        null,
        # 186 M-:
        null,
        # 187 M-;
        null,
        # 188 M-<
        null,
        # 189 M-=
        null,
        # 190 M->
        null,
        # 191 M-?
        null,
        # 192 M-@
        null,
        # 193 M-A
        null,
        # 194 M-B
        null,
        # 195 M-C
        null,
        # 196 M-D
        null,
        # 197 M-E
        null,
        # 198 M-F
        null,
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
        null,
        # 205 M-M
        null,
        # 206 M-N
        null,
        # 207 M-O
        null,
        # 208 M-P
        null,
        # 209 M-Q
        null,
        # 210 M-R
        null,
        # 211 M-S
        null,
        # 212 M-T
        null,
        # 213 M-U
        null,
        # 214 M-V
        null,
        # 215 M-W
        null,
        # 216 M-X
        null,
        # 217 M-Y
        null,
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
        null,
        # 227 M-c
        null,
        # 228 M-d
        null,
        # 229 M-e
        null,
        # 230 M-f
        null,
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
        null,
        # 237 M-m
        null,
        # 238 M-n
        null,
        # 239 M-o
        null,
        # 240 M-p
        null,
        # 241 M-q
        null,
        # 242 M-r
        null,
        # 243 M-s
        null,
        # 244 M-t
        null,
        # 245 M-u
        null,
        # 246 M-v
        null,
        # 247 M-w
        null,
        # 248 M-x
        null,
        # 249 M-y
        null,
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
        null,
    ];
}
