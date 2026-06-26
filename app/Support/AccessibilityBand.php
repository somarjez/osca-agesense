<?php

namespace App\Support;

/**
 * Single source of truth for the "Facility access" classification bands.
 *
 * The composite accessibility score (senior_accessibility_metrics.accessibility_score,
 * 0–1, surfaced as a 0–100 percent) is a proximity index anchored to per-facility
 * distance caps, so absolute cutoffs are interpretable. Every surface — the profile
 * card, the GIS map popups, and the accessibility heatmap legend — classifies the
 * percent through this one table so they always tell the same story.
 *
 * `tone` maps to the project's semantic risk ramp (low → moderate → high → critical),
 * which already has matching `text-*`, `bg-*`, `bar-fill-*` and `badge-*` classes.
 * Consumers compose the literal Tailwind classes from `tone` themselves (the build
 * does not scan app/ for class names), keeping this class framework-agnostic.
 */
class AccessibilityBand
{
    /**
     * Ordered high → low. `min` is the inclusive lower bound on the 0–100 percent;
     * the first band whose `min` the percent meets wins.
     */
    public const BANDS = [
        [
            'key' => 'good',
            'label' => 'Good access',
            'short' => 'Good',
            'min' => 75,
            'tone' => 'low',
            'description' => 'Health, civic, and market services are close by.',
        ],
        [
            'key' => 'moderate',
            'label' => 'Moderate access',
            'short' => 'Moderate',
            'min' => 50,
            'tone' => 'moderate',
            'description' => 'Most services are reachable, but some are a distance away.',
        ],
        [
            'key' => 'limited',
            'label' => 'Limited access',
            'short' => 'Limited',
            'min' => 25,
            'tone' => 'high',
            'description' => 'Several key services are far from this senior.',
        ],
        [
            'key' => 'priority',
            'label' => 'Priority — very limited',
            'short' => 'Priority',
            'min' => 0,
            'tone' => 'critical',
            'description' => 'Services are very far; flag for outreach support.',
        ],
    ];

    /**
     * Classify a 0–100 percent into its band. Accepts a 0–1 score too (auto-scaled).
     * Returns null when the value is null.
     *
     * @return array{key:string,label:string,short:string,min:int,tone:string,description:string}|null
     */
    public static function classify(int|float|null $percent): ?array
    {
        if ($percent === null) {
            return null;
        }

        $value = (float) $percent;
        if ($value <= 1.0) {
            $value *= 100;
        }
        $value = max(0.0, min(100.0, $value));

        foreach (self::BANDS as $band) {
            if ($value >= $band['min']) {
                return $band;
            }
        }

        // Unreachable (last band has min 0), but keep the contract total.
        return self::BANDS[count(self::BANDS) - 1];
    }

    /** Plain-language band label (e.g. "Limited access") or null. */
    public static function label(int|float|null $percent): ?string
    {
        return self::classify($percent)['label'] ?? null;
    }

    /** The full band table — injected to the frontend so JS shares this definition. */
    public static function all(): array
    {
        return self::BANDS;
    }
}
