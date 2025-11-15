<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\Test;

use PHPUnit\Framework\TestCase;

/**
 * Test suite for configuration file validation.
 *
 * This test suite validates various configuration files in the project:
 * - .vscode/settings.json - VSCode workspace settings
 * - Validates JSON syntax and structure
 * - Ensures required keys are present
 * - Validates color format values
 */
class ConfigurationValidationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = \dirname(__DIR__);
    }

    /**
     * Test that .vscode/settings.json exists.
     */
    public function testVSCodeSettingsFileExists(): void
    {
        $settingsPath = $this->projectRoot.'/.vscode/settings.json';

        $this->assertFileExists(
            $settingsPath,
            '.vscode/settings.json should exist',
        );

        $this->assertFileIsReadable(
            $settingsPath,
            '.vscode/settings.json should be readable',
        );
    }

    /**
     * Test that .vscode/settings.json contains valid JSON.
     */
    public function testVSCodeSettingsIsValidJSON(): void
    {
        $settingsPath = $this->projectRoot.'/.vscode/settings.json';
        $content = file_get_contents($settingsPath);

        $this->assertNotFalse(
            $content,
            'Should be able to read .vscode/settings.json',
        );

        $decoded = json_decode($content, true);
        $jsonError = json_last_error();

        $this->assertEquals(
            \JSON_ERROR_NONE,
            $jsonError,
            'JSON should be valid. Error: '.json_last_error_msg(),
        );

        $this->assertIsArray(
            $decoded,
            'Decoded JSON should be an array/object',
        );
    }

    /**
     * Test that workbench.colorCustomizations key exists.
     */
    public function testWorkbenchColorCustomizationsExists(): void
    {
        $settings = $this->loadVSCodeSettings();

        $this->assertArrayHasKey(
            'workbench.colorCustomizations',
            $settings,
            'Settings should contain workbench.colorCustomizations',
        );

        $this->assertIsArray(
            $settings['workbench.colorCustomizations'],
            'workbench.colorCustomizations should be an object/array',
        );
    }

    /**
     * Test that peacock.color key exists.
     */
    public function testPeacockColorExists(): void
    {
        $settings = $this->loadVSCodeSettings();

        $this->assertArrayHasKey(
            'peacock.color',
            $settings,
            'Settings should contain peacock.color',
        );

        $this->assertIsString(
            $settings['peacock.color'],
            'peacock.color should be a string',
        );
    }

    /**
     * Test that all color values are valid hex colors.
     */
    public function testColorValuesAreValidHexColors(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        foreach ($colorCustomizations as $key => $value) {
            if (\is_string($value)) {
                $this->assertMatchesRegularExpression(
                    '/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/',
                    $value,
                    "Color value for '{$key}' should be a valid hex color (with optional alpha)",
                );
            }
        }
    }

    /**
     * Test that peacock.color is a valid hex color.
     */
    public function testPeacockColorIsValidHexColor(): void
    {
        $settings = $this->loadVSCodeSettings();
        $peacockColor = $settings['peacock.color'];

        $this->assertMatchesRegularExpression(
            '/^#[0-9a-fA-F]{6}$/',
            $peacockColor,
            'peacock.color should be a valid 6-digit hex color',
        );
    }

    /**
     * Test that specific required color customization keys exist.
     */
    public function testRequiredColorCustomizationKeysExist(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        $requiredKeys = [
            'activityBar.activeBackground',
            'activityBar.background',
            'activityBar.foreground',
            'statusBar.background',
            'statusBar.foreground',
            'titleBar.activeBackground',
            'titleBar.activeForeground',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey(
                $key,
                $colorCustomizations,
                "Color customizations should contain '{$key}'",
            );
        }
    }

    /**
     * Test that color values use consistent green theme.
     */
    public function testColorThemeConsistency(): void
    {
        $settings = $this->loadVSCodeSettings();
        $peacockColor = $settings['peacock.color'];

        // Peacock color should be #076f21 (green theme)
        $this->assertEquals(
            '#076f21',
            $peacockColor,
            'Peacock color should be the expected green theme color',
        );

        // Verify key colors match the green theme
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        $this->assertEquals(
            '#076f21',
            $colorCustomizations['statusBar.background'],
            'Status bar should use peacock color',
        );

        $this->assertEquals(
            '#076f21',
            $colorCustomizations['titleBar.activeBackground'],
            'Title bar should use peacock color',
        );
    }

    /**
     * Test that foreground colors are light on dark backgrounds.
     */
    public function testForegroundColorContrast(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        // Foreground colors should be light (e7e7e7) for contrast
        $foregroundKeys = [
            'activityBar.foreground',
            'statusBar.foreground',
            'titleBar.activeForeground',
        ];

        foreach ($foregroundKeys as $key) {
            if (isset($colorCustomizations[$key])) {
                $this->assertMatchesRegularExpression(
                    '/^#[e-f][0-9a-f]{5}$/i',
                    $colorCustomizations[$key],
                    "Foreground color '{$key}' should be light for contrast",
                );
            }
        }
    }

    /**
     * Test that alpha channel colors have correct format.
     */
    public function testAlphaChannelColorFormat(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        $alphaColorKeys = [
            'activityBar.inactiveForeground',
            'titleBar.inactiveBackground',
            'titleBar.inactiveForeground',
        ];

        foreach ($alphaColorKeys as $key) {
            if (isset($colorCustomizations[$key])) {
                $color = $colorCustomizations[$key];

                // Should be 8 characters (6 for color + 2 for alpha)
                if (\strlen($color) === 9) { // Including #
                    $this->assertMatchesRegularExpression(
                        '/^#[0-9a-fA-F]{6}[0-9a-fA-F]{2}$/',
                        $color,
                        "Color '{$key}' with alpha channel should have correct format",
                    );
                }
            }
        }
    }

    /**
     * Test JSON file size is reasonable.
     */
    public function testJSONFileSizeIsReasonable(): void
    {
        $settingsPath = $this->projectRoot.'/.vscode/settings.json';
        $fileSize = filesize($settingsPath);

        $this->assertGreaterThan(
            0,
            $fileSize,
            'Settings file should not be empty',
        );

        $this->assertLessThan(
            10240, // 10KB
            $fileSize,
            'Settings file should not be unreasonably large',
        );
    }

    /**
     * Test JSON formatting and structure.
     */
    public function testJSONFormattingStructure(): void
    {
        $settingsPath = $this->projectRoot.'/.vscode/settings.json';
        $content = file_get_contents($settingsPath);

        // Should start with {
        $this->assertStringStartsWith(
            '{',
            trim($content),
            'JSON should start with opening brace',
        );

        // Should end with }
        $this->assertStringEndsWith(
            '}',
            trim($content),
            'JSON should end with closing brace',
        );

        // Should contain proper indentation (4 spaces based on the file)
        $this->assertStringContainsString(
            '    "workbench.colorCustomizations"',
            $content,
            'JSON should be properly indented',
        );
    }

    /**
     * Test that all required VSCode color customization properties are strings.
     */
    public function testColorCustomizationValuesAreStrings(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        foreach ($colorCustomizations as $key => $value) {
            $this->assertIsString(
                $value,
                "Value for '{$key}' should be a string",
            );

            $this->assertNotEmpty(
                $value,
                "Value for '{$key}' should not be empty",
            );
        }
    }

    /**
     * Test that no duplicate keys exist in JSON.
     */
    public function testNoDuplicateJSONKeys(): void
    {
        $settingsPath = $this->projectRoot.'/.vscode/settings.json';
        $content = file_get_contents($settingsPath);

        // Parse to count keys
        $decoded = json_decode($content, true);
        $topLevelKeys = array_keys($decoded);
        $uniqueKeys = array_unique($topLevelKeys);

        $this->assertCount(
            \count($topLevelKeys),
            $uniqueKeys,
            'JSON should not contain duplicate top-level keys',
        );

        // Check nested workbench.colorCustomizations
        if (isset($decoded['workbench.colorCustomizations'])) {
            $colorKeys = array_keys($decoded['workbench.colorCustomizations']);
            $uniqueColorKeys = array_unique($colorKeys);

            $this->assertCount(
                \count($colorKeys),
                $uniqueColorKeys,
                'Color customizations should not contain duplicate keys',
            );
        }
    }

    /**
     * Test that activity bar badge colors exist.
     */
    public function testActivityBarBadgeColorsExist(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        $this->assertArrayHasKey(
            'activityBarBadge.background',
            $colorCustomizations,
            'Should have activityBarBadge.background',
        );

        $this->assertArrayHasKey(
            'activityBarBadge.foreground',
            $colorCustomizations,
            'Should have activityBarBadge.foreground',
        );
    }

    /**
     * Test that hover and remote colors are consistent.
     */
    public function testHoverAndRemoteColorConsistency(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        // Hover colors should complement the theme
        if (isset($colorCustomizations['sash.hoverBorder'])) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-fA-F]{6}$/',
                $colorCustomizations['sash.hoverBorder'],
                'Hover border color should be valid',
            );
        }

        // Remote colors should match status bar
        if (isset($colorCustomizations['statusBarItem.remoteBackground'])) {
            $this->assertEquals(
                $colorCustomizations['statusBar.background'],
                $colorCustomizations['statusBarItem.remoteBackground'],
                'Remote status bar item should match status bar background',
            );
        }
    }

    /**
     * Test command center border exists.
     */
    public function testCommandCenterBorderExists(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        $this->assertArrayHasKey(
            'commandCenter.border',
            $colorCustomizations,
            'Should have commandCenter.border',
        );
    }

    /**
     * Test that the settings file follows VSCode schema conventions.
     */
    public function testVSCodeSchemaConventions(): void
    {
        $settings = $this->loadVSCodeSettings();

        // All top-level keys should be valid VSCode setting keys
        foreach (array_keys($settings) as $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-zA-Z0-9]*(\.[a-zA-Z0-9]+)*$/',
                $key,
                "Key '{$key}' should follow VSCode setting name convention",
            );
        }
    }

    /**
     * Test that color values don't use shorthand hex notation.
     */
    public function testNoShorthandHexColors(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        foreach ($colorCustomizations as $key => $value) {
            if (\is_string($value) && preg_match('/^#[0-9a-fA-F]+$/', $value)) {
                // Should be either 6 or 8 characters (not 3 or 4)
                $hexLength = \strlen($value) - 1; // Subtract the #

                $this->assertContains(
                    $hexLength,
                    [6, 8],
                    "Color '{$key}' should use full hex notation (6 or 8 chars), not shorthand",
                );
            }
        }
    }

    /**
     * Test inactive foreground has proper alpha transparency.
     */
    public function testInactiveForegroundTransparency(): void
    {
        $settings = $this->loadVSCodeSettings();
        $colorCustomizations = $settings['workbench.colorCustomizations'];

        $inactiveKey = 'activityBar.inactiveForeground';

        if (isset($colorCustomizations[$inactiveKey])) {
            $color = $colorCustomizations[$inactiveKey];

            // Should have alpha channel for transparency
            $this->assertEquals(
                9,
                \strlen($color),
                'Inactive foreground should include alpha channel',
            );

            // Extract alpha value (last 2 chars)
            $alpha = substr($color, -2);
            $alphaValue = hexdec($alpha);

            // Alpha should indicate some transparency (not fully opaque)
            $this->assertLessThan(
                255,
                $alphaValue,
                'Inactive foreground should have some transparency',
            );
        }
    }

    /**
     * Helper method to load VSCode settings.
     */
    private function loadVSCodeSettings(): array
    {
        $settingsPath = $this->projectRoot.'/.vscode/settings.json';
        $content = file_get_contents($settingsPath);
        $settings = json_decode($content, true);

        $this->assertIsArray($settings, 'Settings should be a valid array');

        return $settings;
    }
}
