<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\BrandColorHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BrandColorHelper — brand color token derivation.
 */
class BrandColorHelperTest extends TestCase
{
    #[Test]
    public function derive_returns_all_required_keys(): void
    {
        $tokens = BrandColorHelper::derive('#2563EB');

        $this->assertArrayHasKey('brand', $tokens);
        $this->assertArrayHasKey('brand_hover', $tokens);
        $this->assertArrayHasKey('brand_text', $tokens);
        $this->assertArrayHasKey('brand_rgb', $tokens);
        // brand_light is NOT in derive() — it's CSS-derived from brand-rgb
        $this->assertArrayNotHasKey('brand_light', $tokens);
    }

    #[Test]
    public function derive_returns_uppercase_hex(): void
    {
        $tokens = BrandColorHelper::derive('#2563eb');

        $this->assertSame('#2563EB', $tokens['brand']);
    }

    #[Test]
    public function derive_strips_leading_hash(): void
    {
        $tokens = BrandColorHelper::derive('#FF5733');
        $this->assertSame('#FF5733', $tokens['brand']);

        $tokens2 = BrandColorHelper::derive('FF5733');
        $this->assertSame('#FF5733', $tokens2['brand']);
    }

    #[Test]
    public function derive_fallback_for_invalid_hex(): void
    {
        $tokens = BrandColorHelper::derive('xyz');

        // Should fall back to default blue
        $this->assertSame('#2563EB', $tokens['brand']);
    }

    #[Test]
    public function brand_hover_is_darker(): void
    {
        $tokens = BrandColorHelper::derive('#2563EB');

        // Hover should be different from brand
        $this->assertNotSame($tokens['brand'], $tokens['brand_hover']);
    }



    #[Test]
    public function brand_text_white_for_dark_colors(): void
    {
        // Dark navy — should get white text
        $tokens = BrandColorHelper::derive('#1E3A5F');
        $this->assertSame('#FFFFFF', $tokens['brand_text']);
    }

    #[Test]
    public function brand_text_dark_for_light_colors(): void
    {
        // Bright yellow — should get dark text
        $tokens = BrandColorHelper::derive('#FFFF00');
        $this->assertSame('#111827', $tokens['brand_text']);
    }

    #[Test]
    public function brand_rgb_format(): void
    {
        $tokens = BrandColorHelper::derive('#FF0000');

        $this->assertSame('255, 0, 0', $tokens['brand_rgb']);
    }

    #[Test]
    public function inline_style_contains_all_variables(): void
    {
        $style = BrandColorHelper::inlineStyle('#2563EB');

        $this->assertStringContainsString('--vb-brand:', $style);
        $this->assertStringContainsString('--vb-brand-hover:', $style);
        // brand-light is CSS-derived from brand-rgb, not emitted in inline style
        $this->assertStringNotContainsString('--vb-brand-light:', $style);
        $this->assertStringContainsString('--vb-brand-text:', $style);
        $this->assertStringContainsString('--vb-brand-rgb:', $style);
        $this->assertStringStartsWith(':root {', $style);
    }

    #[Test]
    public function pure_white_gets_dark_text(): void
    {
        $tokens = BrandColorHelper::derive('#FFFFFF');
        $this->assertSame('#111827', $tokens['brand_text']);
    }

    #[Test]
    public function pure_black_gets_white_text(): void
    {
        $tokens = BrandColorHelper::derive('#000000');
        $this->assertSame('#FFFFFF', $tokens['brand_text']);
    }

    #[Test]
    public function brand_presets_keep_aa_text_contrast_in_default_and_hover_states(): void
    {
        foreach (['#2563EB', '#D97706', '#16A34A', '#0D9488', '#0EA5E9', '#F97316', '#8B5CF6', '#777777', '#FFFFFF', '#000000'] as $brand) {
            $tokens = BrandColorHelper::derive($brand);

            $this->assertSame($brand, $tokens['brand'], 'The tenant brand color stays intact.');
            foreach (['brand', 'brand_hover'] as $background) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    $this->contrastRatio($tokens['brand_text'], $tokens[$background]),
                    "{$brand}: {$tokens['brand_text']} on {$background} {$tokens[$background]} must pass WCAG AA.",
                );
            }
        }
    }

    #[Test]
    public function action_fills_keep_white_labels_readable_without_replacing_the_brand(): void
    {
        foreach (['#2563EB', '#0EA5E9', '#F97316', '#8B5CF6', '#FFFF00', '#FFFFFF', '#000000'] as $brand) {
            $tokens = BrandColorHelper::derive($brand);
            $this->assertSame($brand, $tokens['brand']);
            foreach (['brand_action', 'brand_action_hover'] as $fill) {
                $this->assertArrayHasKey($fill, $tokens);
                $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio('#FFFFFF', $tokens[$fill]));
            }
        }
    }

    #[Test]
    public function foreground_and_hover_contrast_cover_the_rgb_color_space(): void
    {
        // Cover every channel combination on a 17-step grid, including extremes
        // and the narrow middle-luminance range where near-black can fail AA.
        for ($r = 0; $r <= 255; $r += 17) {
            for ($g = 0; $g <= 255; $g += 17) {
                for ($b = 0; $b <= 255; $b += 17) {
                    $tokens = BrandColorHelper::derive(sprintf('#%02X%02X%02X', $r, $g, $b));
                    foreach (['brand', 'brand_hover'] as $background) {
                        $this->assertGreaterThanOrEqual(
                            4.5,
                            $this->contrastRatio($tokens['brand_text'], $tokens[$background]),
                            "{$tokens['brand']}: {$tokens['brand_text']} on {$tokens[$background]} must pass WCAG AA.",
                        );
                    }
                }
            }
        }
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (string $channel): float {
                $value = hexdec($channel) / 255;
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
        };
        $foregroundLuminance = $luminance($foreground);
        $backgroundLuminance = $luminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }
}
