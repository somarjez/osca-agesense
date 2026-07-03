<?php

namespace App\Support;

use Carbon\Carbon;

class DateParser
{
    /**
     * @param  bool  $dobMode  Historical flag name, kept for call-site clarity. Both DOB and
     *                         survey-timestamp fields come from the same Philippine-locale
     *                         source (Google Form), so ambiguous slash-dates are resolved as
     *                         d/m/Y regardless of this flag.
     */
    public static function parse($value, bool $dobMode = false): ?string
    {
        $v = self::strVal($value);
        if ($v === null) {
            return null;
        }

        // Regex intentionally omits $ so it matches even when a time suffix like " 0:00" is present.
        $hasSlashDate = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $v, $m);

        if ($hasSlashDate) {
            $dateOnly = "{$m[1]}/{$m[2]}/{$m[3]}";
            $hasTimeSuffix = (bool) preg_match('/\s+\d/', $v);

            if ((int) $m[1] > 12) {
                // Day-first unambiguous (day can't be a month): always d/m/Y.
                try {
                    return Carbon::createFromFormat('d/m/Y', $dateOnly)->format('Y-m-d');
                } catch (\Throwable) {
                }
            } elseif ($hasTimeSuffix) {
                // Google-Form export, Philippine locale: d/m/Y.
                try {
                    return Carbon::createFromFormat('d/m/Y', $dateOnly)->format('Y-m-d');
                } catch (\Throwable) {
                }
            }
        }

        foreach (['m/d/Y H:i', 'm/d/Y', 'Y-m-d', 'd/m/Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $v)->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function strVal($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return ($v === '' || strtolower($v) === 'nan') ? null : $v;
    }
}
