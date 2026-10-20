<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Brand color calculator for tenant booking pages.
 *
 * Derives dynamic brand tokens from a single hex brand_color:
 * - brand-hover: up to 8% darker while preserving WCAG AA text contrast
 * - brand-text: white, dark, or black text chosen by WCAG contrast ratios
 * - brand-rgb: comma-separated RGB for use in rgba()
 *
 * The "brand-light" token (selected-state backgrounds) is NOT emitted
 * server-side. It is derived purely in CSS from --vb-brand-rgb:
 *   Light mode: rgba(var(--vb-brand-rgb), 0.08)
 *   Dark mode:  rgba(var(--vb-brand-rgb), 0.15)
 * This avoids cascade conflicts where a solid inline hex would override
 * the dark-mode translucent variant.
 *
 * Per .ai/11 §3 (Booking Page Aesthetics): these are the ONLY per-tenant
 * CSS variations. The compiled booking.css is identical for all tenants.
 */
final class BrandColorHelper
{
    /**
     * Calculate all derived brand tokens from a hex color.
     *
     * @return array{brand: string, brand_hover: string, brand_text: string, brand_rgb: string, brand_action: string, brand_action_hover: string}
     */
    public static function derive(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            $hex = '2563EB'; // Default blue
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        [$h, $s, $l] = self::rgbToHsl($r, $g, $b);

        $brandLuminance = self::relativeLuminance($r, $g, $b);
        $darkLuminance = self::relativeLuminance(17, 24, 39);
        $whiteContrast = self::contrastRatio(1.0, $brandLuminance);
        $darkContrast = self::contrastRatio($darkLuminance, $brandLuminance);
        $brandText = $darkContrast > $whiteContrast ? '#111827' : '#FFFFFF';
        $textLuminance = $darkContrast > $whiteContrast ? $darkLuminance : 1.0;

        // Near-black and white leave a narrow gap below 4.5:1. Pure black
        // closes it without altering the tenant's chosen brand color.
        if (max($whiteContrast, $darkContrast) < 4.5) {
            $brandText = '#000000';
            $textLuminance = 0.0;
        }

        $hoverL = max(0, $l - 0.08);
        [$hr, $hg, $hb] = self::hslToRgb($h, $s, $hoverL);

        if (self::contrastRatio($textLuminance, self::relativeLuminance($hr, $hg, $hb)) < 4.5) {
            // Keep the same foreground in both states. Find the darkest
            // rounded RGB variant that still passes, using the brand as a
            // known passing upper bound.
            $lower = $hoverL;
            $upper = $l;
            [$hr, $hg, $hb] = [$r, $g, $b];

            for ($i = 0; $i < 16; $i++) {
                $candidateL = ($lower + $upper) / 2;
                [$cr, $cg, $cb] = self::hslToRgb($h, $s, $candidateL);
                if (self::contrastRatio($textLuminance, self::relativeLuminance($cr, $cg, $cb)) >= 4.5) {
                    $upper = $candidateL;
                    [$hr, $hg, $hb] = [$cr, $cg, $cb];
                } else {
                    $lower = $candidateL;
                }
            }
        }

        $brandHover = sprintf('#%02X%02X%02X', $hr, $hg, $hb);

        // White action labels have a consistent visual weight. Deepen only
        // the action fill when needed; identity/selection tokens keep the
        // exact brand color and their independently measured foreground.
        [$ar, $ag, $ab] = [$r, $g, $b];
        $actionL = $l;
        if ($whiteContrast < 4.5) {
            $lower = 0.0;
            $upper = $l;
            [$ar, $ag, $ab] = [0, 0, 0];
            for ($i = 0; $i < 20; $i++) {
                $candidate = ($lower + $upper) / 2;
                [$cr, $cg, $cb] = self::hslToRgb($h, $s, $candidate);
                if (self::contrastRatio(1.0, self::relativeLuminance($cr, $cg, $cb)) >= 4.55) {
                    $lower = $candidate;
                    [$ar, $ag, $ab] = [$cr, $cg, $cb];
                } else {
                    $upper = $candidate;
                }
            }
            $actionL = $lower;
        }
        [$ahr, $ahg, $ahb] = self::hslToRgb($h, $s, max(0, $actionL - 0.06));

        // brand-rgb: for use in CSS rgba(var(--vb-brand-rgb), opacity)
        $brandRgb = "{$r}, {$g}, {$b}";

        return [
            'brand'       => '#' . strtoupper($hex),
            'brand_hover' => $brandHover,
            'brand_text'  => $brandText,
            'brand_rgb'   => $brandRgb,
            'brand_action' => sprintf('#%02X%02X%02X', $ar, $ag, $ab),
            'brand_action_hover' => sprintf('#%02X%02X%02X', $ahr, $ahg, $ahb),
        ];
    }

    /**
     * Generate the inline <style> block for brand injection.
     */
    public static function inlineStyle(string $hex): string
    {
        $tokens = self::derive($hex);

        // Note: --vb-brand-light is NOT emitted here. It's derived from
        // --vb-brand-rgb in CSS using rgba() for both light (8% opacity)
        // and dark (15% opacity) modes, avoiding cascade conflicts.
        return sprintf(
            ':root { --vb-brand: %s; --vb-brand-hover: %s; --vb-brand-text: %s; --vb-brand-rgb: %s; --vb-brand-action: %s; --vb-brand-action-hover: %s; }',
            $tokens['brand'],
            $tokens['brand_hover'],
            $tokens['brand_text'],
            $tokens['brand_rgb'],
            $tokens['brand_action'],
            $tokens['brand_action_hover'],
        );
    }

    /**
     * RGB to HSL conversion.
     * @return array{0: float, 1: float, 2: float} [H 0-360, S 0-1, L 0-1]
     */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match (true) {
            $max === $r => (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6,
            $max === $g => (($b - $r) / $d + 2) / 6,
            default     => (($r - $g) / $d + 4) / 6,
        };

        return [$h * 360, $s, $l];
    }

    /**
     * HSL to RGB conversion.
     * @return array{0: int, 1: int, 2: int} [R 0-255, G 0-255, B 0-255]
     */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h /= 360;

        if ($s === 0.0) {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return [
            (int) round(self::hueToRgb($p, $q, $h + 1 / 3) * 255),
            (int) round(self::hueToRgb($p, $q, $h) * 255),
            (int) round(self::hueToRgb($p, $q, $h - 1 / 3) * 255),
        ];
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    }

    /**
     * WCAG 2.1 relative luminance.
     */
    private static function relativeLuminance(int $r, int $g, int $b): float
    {
        $srgb = function (int $c): float {
            $c /= 255;
            return $c <= 0.04045
                ? $c / 12.92
                : pow(($c + 0.055) / 1.055, 2.4);
        };

        return 0.2126 * $srgb($r) + 0.7152 * $srgb($g) + 0.0722 * $srgb($b);
    }

    private static function contrastRatio(float $first, float $second): float
    {
        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }
}
