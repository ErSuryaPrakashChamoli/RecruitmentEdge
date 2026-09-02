<?php

namespace App\Enums;

use Filament\Support\Colors\Color;

/**
 * The single source of truth for Recruitment Edge's 8 brand themes — every theme-facing surface
 * (Profile's quick picker, the Theme Gallery, the `users.theme` column, the anti-flash script)
 * reads this list rather than hard-coding theme keys/labels independently. The actual color values
 * are CSS custom-property overrides in resources/css/filament/admin/theme.css
 * (`:root[data-app-theme="{value}"]`), generated with Filament\Support\Colors\Color::generatePalette()
 * from each theme's seed hex so every theme keeps identical perceptual lightness/chroma steps —
 * only hue (and, for a few themes, the neutral/gray tone) differs.
 */
enum AppTheme: string
{
    case ExecutiveNavy = 'navy';
    case ModernIndigo = 'indigo';
    case TealSlate = 'teal';
    case GraphiteElectricBlue = 'graphite';
    case SunsetCoral = 'coral';
    case EmeraldGreen = 'emerald';
    case PurpleRoyale = 'purple';
    case MinimalBeige = 'beige';

    public static function default(): self
    {
        return self::ExecutiveNavy;
    }

    /**
     * Resolves a stored value to a valid theme, falling back to the default for null/unknown
     * values (e.g. a theme that existed when a user picked it but was later retired).
     */
    public static function fromValueOrDefault(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    public function number(): int
    {
        return match ($this) {
            self::ExecutiveNavy => 1,
            self::ModernIndigo => 2,
            self::TealSlate => 3,
            self::GraphiteElectricBlue => 4,
            self::SunsetCoral => 5,
            self::EmeraldGreen => 6,
            self::PurpleRoyale => 7,
            self::MinimalBeige => 8,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ExecutiveNavy => 'Executive Navy',
            self::ModernIndigo => 'Modern Indigo',
            self::TealSlate => 'Teal & Slate',
            self::GraphiteElectricBlue => 'Graphite & Electric Blue',
            self::SunsetCoral => 'Sunset Coral',
            self::EmeraldGreen => 'Emerald Green',
            self::PurpleRoyale => 'Purple Royale',
            self::MinimalBeige => 'Minimal Beige',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ExecutiveNavy => 'Premium, corporate, and trustworthy — a banking-grade enterprise SaaS feel.',
            self::ModernIndigo => 'Modern, technology-driven, and intelligent — a contemporary AI/SaaS feel.',
            self::TealSlate => 'Calm, people-centric, and professional — a premium HR-platform feel.',
            self::GraphiteElectricBlue => 'Bold, high-tech, and command-center — enterprise-grade, never gaming-dashboard.',
            self::SunsetCoral => 'Energetic and action-oriented, kept corporate — never consumer or social-media.',
            self::EmeraldGreen => 'Growth, achievement, and productivity — premium and restrained, not loud.',
            self::PurpleRoyale => 'Luxury, premium, and creative — an executive HR-tech feel, used sparingly.',
            self::MinimalBeige => 'Elegant, minimal, and distraction-free, while still feeling like enterprise software.',
        };
    }

    public function bestFor(): string
    {
        return match ($this) {
            self::ExecutiveNavy => 'CHRO, VP HR, Management, executive dashboards',
            self::ModernIndigo => 'Analytics, AI, Performance, data-heavy screens',
            self::TealSlate => 'Recruiters, Candidate 360, Interviews, Joining',
            self::GraphiteElectricBlue => 'Analytics, Reports, Performance, Leadership',
            self::SunsetCoral => 'Recruitment Operations, high-activity recruiter teams',
            self::EmeraldGreen => 'Performance, Targets, recruiter productivity, Incentives',
            self::PurpleRoyale => 'Leadership, premium HR experience, AI, Analytics',
            self::MinimalBeige => 'Daily operations, recruiter productivity, simple workflows',
        };
    }

    /**
     * A literal Tailwind background class for the theme's primary swatch — used for previews
     * (topbar/profile picker, Theme Gallery cards). Deliberately literal strings, not dynamic
     * interpolation (see .ai/rules/providers-filament.md) so Tailwind's content scanner picks
     * them up.
     */
    public function swatchClass(): string
    {
        return match ($this) {
            self::ExecutiveNavy => 'bg-[#1B3B6F]',
            self::ModernIndigo => 'bg-indigo-600',
            self::TealSlate => 'bg-teal-600',
            self::GraphiteElectricBlue => 'bg-blue-600',
            self::SunsetCoral => 'bg-[#F4623A]',
            self::EmeraldGreen => 'bg-emerald-600',
            self::PurpleRoyale => 'bg-fuchsia-800',
            self::MinimalBeige => 'bg-[#8B6F47]',
        };
    }

    /**
     * The seed hex each theme's primary shade ramp was generated from (resources/css/filament/
     * admin/theme.css's `:root[data-app-theme="{value}"]` blocks were generated once from these
     * same hexes via Filament\Support\Colors\Color::generatePalette() — kept here too so the Theme
     * Gallery's isolated preview cards can regenerate the identical ramp at runtime for an inline
     * style scope, without duplicating oklch numbers by hand in two places).
     */
    public function seedHex(): string
    {
        return match ($this) {
            self::ExecutiveNavy => '#1B3B6F',
            self::ModernIndigo => '#4F46E5',
            self::TealSlate => '#0D9488',
            self::GraphiteElectricBlue => '#2563EB',
            self::SunsetCoral => '#F4623A',
            self::EmeraldGreen => '#059669',
            self::PurpleRoyale => '#A21CAF',
            self::MinimalBeige => '#8B6F47',
        };
    }

    /**
     * @return array<int, string> shades 50..950 as oklch() strings, for isolated inline-style
     *                            previews (Theme Gallery) that must never touch the real
     *                            :root[data-app-theme] cascade.
     */
    public function previewShades(): array
    {
        return Color::generatePalette($this->seedHex());
    }

    /**
     * This theme's seed hex as a translucent rgba() string — for Chart.js dataset fills
     * (TurnUpTrendChart, SourcePerformanceWidget), which need an actual alpha-blended color value,
     * not a CSS custom property (the chart payload is JSON serialized server-side into the page,
     * with no browser present yet to resolve var(--primary-500)).
     */
    public function chartFill(float $alpha = 0.12): string
    {
        [$red, $green, $blue] = sscanf($this->seedHex(), '#%02x%02x%02x');

        return "rgba({$red}, {$green}, {$blue}, {$alpha})";
    }

    /**
     * A monochromatic, theme-branded categorical palette for charts with more series than a single
     * "primary vs semantic" line chart needs (e.g. SourcePerformanceWidget's per-source doughnut) —
     * varying lightness of this theme's own seed hex rather than unrelated hues, so the chart still
     * visibly belongs to the active theme without becoming an illegible rainbow. Plain hex tint/
     * shade mixing, not Color::generatePalette()'s oklch() strings: Chart.js's bundled color parser
     * (@kurkle/color) isn't guaranteed to understand the oklch() function, and a chart segment
     * silently rendering as black/transparent on an unsupported browser is a worse failure mode
     * than a slightly less perceptually-uniform palette.
     *
     * @return array<int, string>
     */
    public function chartCategoricalPalette(): array
    {
        $seed = $this->seedHex();

        return [
            $seed,
            self::mixHex($seed, '#ffffff', 0.45),
            self::mixHex($seed, '#000000', 0.3),
            self::mixHex($seed, '#ffffff', 0.7),
            self::mixHex($seed, '#000000', 0.55),
            self::mixHex($seed, '#ffffff', 0.2),
            self::mixHex($seed, '#000000', 0.15),
            self::mixHex($seed, '#ffffff', 0.6),
        ];
    }

    private static function mixHex(string $hex, string $mixWith, float $amount): string
    {
        [$r1, $g1, $b1] = sscanf($hex, '#%02x%02x%02x');
        [$r2, $g2, $b2] = sscanf($mixWith, '#%02x%02x%02x');

        $mix = fn (int $a, int $b): int => (int) round($a + ($b - $a) * $amount);

        return sprintf('#%02x%02x%02x', $mix($r1, $r2), $mix($g1, $g2), $mix($b1, $b2));
    }
}
