<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Face;
use PhPty\Reline\Face\Config as FaceConfig;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Ported from test/reline/test_face.rb. Upstream's nested test classes become flat
 * methods here (PHPUnit has no `class < self` inheritance of setup); each rebuilds
 * the faces it needs. COLORTERM is manipulated with putenv and restored in
 * tearDown, and force_truecolor is cleared, mirroring the Ruby ENV backup/restore.
 *
 * The two Ruby-introspection cases (`respond_to?(:another_label)` and the
 * private-constant check) are not portable and are omitted; everything with
 * observable SGR output is ported.
 */
final class FaceTest extends TestCase
{
    private const RESET_SGR = "\e[0m";

    /** @var string|false */
    private $colortermBackup;

    protected function set_up(): void
    {
        $this->colortermBackup = \getenv('COLORTERM');
        \putenv('COLORTERM=truecolor');
    }

    protected function tear_down(): void
    {
        Face::reset_to_initial_configs();
        Face::unset_force_truecolor();
        if ($this->colortermBackup === false) {
            \putenv('COLORTERM');
        } else {
            \putenv('COLORTERM=' . $this->colortermBackup);
        }
    }

    // --- WithInsufficientSetupTest -----------------------------------------

    public function testInsufficientConfigDefaultsAllSlotsToReset(): void
    {
        Face::config('my_insufficient_config', static function (FaceConfig $face): void {
        });
        $face = Face::getConfig('my_insufficient_config');

        self::assertSame(self::RESET_SGR, $face->get('default'));
        self::assertSame(self::RESET_SGR, $face->get('enhanced'));
        self::assertSame(self::RESET_SGR, $face->get('scrollbar'));
    }

    public function testInsufficientConfigDefinition(): void
    {
        Face::config('my_insufficient_config', static function (FaceConfig $face): void {
        });
        $expected = [
            'default' => ['style' => 'reset', 'escape_sequence' => self::RESET_SGR],
            'enhanced' => ['style' => 'reset', 'escape_sequence' => self::RESET_SGR],
            'scrollbar' => ['style' => 'reset', 'escape_sequence' => self::RESET_SGR],
        ];
        self::assertEquals($expected, Face::configs()['my_insufficient_config']);
    }

    // --- WithSetupTest ------------------------------------------------------

    private function buildWithSetup(): void
    {
        Face::config('my_config', static function (FaceConfig $face): void {
            $face->define('default', ['foreground' => 'blue']);
            $face->define('enhanced', ['foreground' => '#FF1020', 'background' => 'black', 'style' => ['bold', 'underlined']]);
        });
        Face::config('another_config', static function (FaceConfig $face): void {
            $face->define('another_label', ['foreground' => 'red']);
        });
    }

    public function testNowThereAreFourConfigs(): void
    {
        $this->buildWithSetup();
        self::assertSame(['default', 'completion_dialog', 'my_config', 'another_config'], \array_keys(Face::configs()));
    }

    public function testResettingConfigDiscardsUserDefinedConfigs(): void
    {
        $this->buildWithSetup();
        Face::reset_to_initial_configs();
        self::assertSame(['default', 'completion_dialog'], \array_keys(Face::configs()));
    }

    public function testMyConfigsDefinition(): void
    {
        $this->buildWithSetup();
        $expected = [
            'default' => [
                'foreground' => 'blue',
                'escape_sequence' => self::RESET_SGR . "\e[34m",
            ],
            'enhanced' => [
                'foreground' => '#FF1020',
                'background' => 'black',
                'style' => ['bold', 'underlined'],
                'escape_sequence' => "\e[0m\e[38;2;255;16;32;40;1;4m",
            ],
            'scrollbar' => [
                'style' => 'reset',
                'escape_sequence' => "\e[0m",
            ],
        ];
        self::assertEquals($expected, Face::configs()['my_config']);
    }

    public function testMyConfigLine(): void
    {
        $this->buildWithSetup();
        self::assertSame(self::RESET_SGR . "\e[34m", Face::getConfig('my_config')->get('default'));
    }

    public function testMyConfigEnhanced(): void
    {
        $this->buildWithSetup();
        self::assertSame(self::RESET_SGR . "\e[38;2;255;16;32;40;1;4m", Face::getConfig('my_config')->get('enhanced'));
    }

    // --- WithoutSetupTest ---------------------------------------------------

    public function testEmptyConfigDefaultSlotIsReset(): void
    {
        Face::config('my_config', static function (FaceConfig $face): void {
        });
        self::assertSame(self::RESET_SGR, Face::getConfig('my_config')->get('default'));
    }

    public function testUnknownSlotRaises(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Face::getConfig('default')->get('style_does_not_exist');
    }

    public function testInvalidKeyword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Face::config('invalid_config', static function (FaceConfig $face): void {
            $face->define('default', ['invalid_keyword' => 'red']);
        });
    }

    public function testInvalidForegroundName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Face::config('invalid_config', static function (FaceConfig $face): void {
            $face->define('default', ['foreground' => 'invalid_name']);
        });
    }

    public function testInvalidBackgroundName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Face::config('invalid_config', static function (FaceConfig $face): void {
            $face->define('default', ['background' => 'invalid_name']);
        });
    }

    public function testInvalidStyleName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Face::config('invalid_config', static function (FaceConfig $face): void {
            $face->define('default', ['style' => 'invalid_name']);
        });
    }

    // --- ConfigTest (format_to_sgr / rgb / truecolor) -----------------------

    private function newConfig(): FaceConfig
    {
        return new FaceConfig(static function (FaceConfig $face): void {
        });
    }

    /** Exercise the private format_to_sgr through a real define + get. */
    private function formatToSgr(array $orderedValues): string
    {
        $config = new FaceConfig(static function (FaceConfig $face) use ($orderedValues): void {
            $face->define('probe', $orderedValues);
        });

        return $config->get('probe');
    }

    public function testFormatToSgrPreservesOrder(): void
    {
        self::assertSame(
            self::RESET_SGR . "\e[37;41;1;3m",
            $this->formatToSgr(['foreground' => 'white', 'background' => 'red', 'style' => ['bold', 'italicized']]),
        );
        self::assertSame(
            self::RESET_SGR . "\e[37;1;3;41m",
            $this->formatToSgr(['foreground' => 'white', 'style' => ['bold', 'italicized'], 'background' => 'red']),
        );
    }

    public function testFormatToSgrWithReset(): void
    {
        self::assertSame(self::RESET_SGR, $this->formatToSgr(['style' => 'reset']));
        self::assertSame(
            self::RESET_SGR . "\e[37;0;41m",
            $this->formatToSgr(['foreground' => 'white', 'style' => 'reset', 'background' => 'red']),
        );
    }

    public function testFormatToSgrWithSingleStyle(): void
    {
        self::assertSame(
            self::RESET_SGR . "\e[37;41;1m",
            $this->formatToSgr(['foreground' => 'white', 'background' => 'red', 'style' => 'bold']),
        );
    }

    public function testTruecolor(): void
    {
        \putenv('COLORTERM=truecolor');
        self::assertTrue(Face::truecolor());
        \putenv('COLORTERM=24bit');
        self::assertTrue(Face::truecolor());
        \putenv('COLORTERM');
        self::assertFalse(Face::truecolor());
        Face::force_truecolor();
        self::assertTrue(Face::truecolor());
    }

    public function testSgrRgbTruecolor(): void
    {
        \putenv('COLORTERM=truecolor');
        self::assertSame(self::RESET_SGR . "\e[38;2;255;255;255m", $this->formatToSgr(['foreground' => '#ffffff']));
        self::assertSame(self::RESET_SGR . "\e[48;2;18;52;86m", $this->formatToSgr(['background' => '#123456']));
    }

    public function testSgrRgb256color(): void
    {
        \putenv('COLORTERM');
        // Color steps are [0x00, 0x5f, 0x87, 0xaf, 0xd7, 0xff]
        self::assertSame(self::RESET_SGR . "\e[38;5;231m", $this->formatToSgr(['foreground' => '#ffffff']));
        self::assertSame(self::RESET_SGR . "\e[48;5;16m", $this->formatToSgr(['background' => '#000000']));
        self::assertSame(self::RESET_SGR . "\e[38;5;24m", $this->formatToSgr(['foreground' => '#005f87']));
        self::assertSame(self::RESET_SGR . "\e[38;5;67m", $this->formatToSgr(['foreground' => '#5f87af']));
        self::assertSame(self::RESET_SGR . "\e[48;5;110m", $this->formatToSgr(['background' => '#87afd7']));
        self::assertSame(self::RESET_SGR . "\e[48;5;153m", $this->formatToSgr(['background' => '#afd7ff']));
        // Boundary values are [0x30, 0x73, 0x9b, 0xc3, 0xeb]
        self::assertSame(self::RESET_SGR . "\e[38;5;24m", $this->formatToSgr(['foreground' => '#2f729a']));
        self::assertSame(self::RESET_SGR . "\e[38;5;67m", $this->formatToSgr(['foreground' => '#30739b']));
        self::assertSame(self::RESET_SGR . "\e[48;5;110m", $this->formatToSgr(['background' => '#9ac2ea']));
        self::assertSame(self::RESET_SGR . "\e[48;5;153m", $this->formatToSgr(['background' => '#9bc3eb']));
    }

    public function testForceTruecolorReconfigure(): void
    {
        \putenv('COLORTERM');
        Face::config('my_config', static function (FaceConfig $face): void {
            $face->define('default', ['foreground' => '#005f87']);
            $face->define('enhanced', ['background' => '#afd7ff']);
        });
        self::assertSame("\e[0m\e[38;5;24m", Face::getConfig('my_config')->get('default'));
        self::assertSame("\e[0m\e[48;5;153m", Face::getConfig('my_config')->get('enhanced'));

        Face::force_truecolor();

        self::assertSame("\e[0m\e[38;2;0;95;135m", Face::getConfig('my_config')->get('default'));
        self::assertSame("\e[0m\e[48;2;175;215;255m", Face::getConfig('my_config')->get('enhanced'));
    }

    public function testRgbExpressionRecognition(): void
    {
        // rgb_expression? is private; exercise it via the truecolor rgb path
        // (a valid #rrggbb produces truecolor bytes) and via a non-rgb name.
        \putenv('COLORTERM=truecolor');
        self::assertSame(self::RESET_SGR . "\e[38;2;255;255;255m", $this->formatToSgr(['foreground' => '#FFFFFF']));
        // "FFFFFF" (no #) is not an rgb expression, so it is treated as a colour
        // name, is unknown, and raises.
        try {
            $this->formatToSgr(['foreground' => 'FFFFFF']);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('invalid SGR parameter', $e->getMessage());
        }
    }

    // --- Tier-4 continuity: the dialog bytes are unchanged -------------------

    public function testDefaultFaceIsAllReset(): void
    {
        self::assertSame(
            ['default' => self::RESET_SGR, 'enhanced' => self::RESET_SGR, 'scrollbar' => self::RESET_SGR],
            Face::get('default'),
        );
        self::assertSame(Face::get('default'), Face::get(null));
    }

    public function testCompletionDialogBytesMatchTier4Seam(): void
    {
        // The exact bytes the tier-4 hardcoded seam produced (CONTEXT.md tier 4).
        // Named colours are truecolor-independent, so this holds under any COLORTERM.
        self::assertSame(
            [
                'default' => self::RESET_SGR . "\e[97;100m",
                'enhanced' => self::RESET_SGR . "\e[30;47m",
                'scrollbar' => self::RESET_SGR . "\e[37;100m",
            ],
            Face::get('completion_dialog'),
        );
    }

    public function testCompletionDialogBytesUnchangedWithoutTruecolor(): void
    {
        \putenv('COLORTERM');
        self::assertSame(
            [
                'default' => self::RESET_SGR . "\e[97;100m",
                'enhanced' => self::RESET_SGR . "\e[30;47m",
                'scrollbar' => self::RESET_SGR . "\e[37;100m",
            ],
            Face::get('completion_dialog'),
        );
    }
}
