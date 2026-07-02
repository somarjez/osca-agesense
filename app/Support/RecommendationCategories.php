<?php

namespace App\Support;

/**
 * Single source of truth for recommendation category display.
 *
 * Keys mirror the categories emitted by python/services/recommendation_rules.py.
 * Legacy keys are kept for older recommendation rows still stored in the DB.
 */
final class RecommendationCategories
{
    public const MAP = [
        'health'            => ['label' => 'Health',            'icon' => 'heroicon-o-heart'],
        'financial'         => ['label' => 'Financial',         'icon' => 'heroicon-o-banknotes'],
        'healthcare_access' => ['label' => 'Healthcare Access', 'icon' => 'heroicon-o-building-office-2'],
        'functional'        => ['label' => 'Functional',        'icon' => 'heroicon-o-bolt'],
        'social'            => ['label' => 'Social',            'icon' => 'heroicon-o-user-group'],
        'livelihood'        => ['label' => 'Livelihood',        'icon' => 'heroicon-o-briefcase'],
        'mental_health'     => ['label' => 'Mental Health',     'icon' => 'heroicon-o-face-smile'],
        // Legacy category keys (older stored rows only)
        'hc_access'         => ['label' => 'Healthcare Access', 'icon' => 'heroicon-o-building-office-2'],
        'sensory'           => ['label' => 'Sensory',           'icon' => 'heroicon-o-eye'],
        'general'           => ['label' => 'General',           'icon' => 'heroicon-o-clipboard-document-list'],
    ];

    public static function label(string $category): string
    {
        return self::MAP[$category]['label'] ?? ucwords(str_replace('_', ' ', $category));
    }

    public static function icon(string $category): string
    {
        return self::MAP[$category]['icon'] ?? 'heroicon-o-tag';
    }
}
