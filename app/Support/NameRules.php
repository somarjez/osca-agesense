<?php

namespace App\Support;

/**
 * Single source of truth for Senior Citizen name character validation —
 * shared, in spirit, between the server (Laravel `regex:` rule, via
 * person()/suffix()) and the client (Alpine nameGuard() in resources/js/app.js,
 * seeded via jsConfig()). Both sides compile the exact same pattern BODY
 * (no delimiters, no flags) so "valid on the client" and "valid on the
 * server" can never disagree.
 *
 * Why a person name and a suffix are different patterns: legitimate suffixes
 * (Jr., Sr., II, III, IV, V) never contain an apostrophe, so PERSON's
 * apostrophe allowance (O'Connor) is deliberately dropped for SUFFIX — see
 * ProfileSurvey::step1Rules() for where each is applied.
 *
 * Why \p{L} anchors the start: it's what rejects a name that is only digits
 * or leads with digits (123Juan, 12345, J0hn is caught by the character
 * class instead, since the 0 isn't at position 0). Why \p{M} rides along
 * with \p{L} in the character class: some legitimate Unicode letters
 * (accented forms not in Laravel's own charset) are represented as a base
 * letter + combining mark; excluding \p{M} would silently reject valid
 * international names built that way.
 *
 * PCRE (Laravel's regex: rule) accepts literal '’' and '\x{2019}'
 * interchangeably; JavaScript's RegExp does NOT accept \x{2019} without the
 * /u flag's \u{2019} syntax, so both patterns below use the literal
 * characters — the one form that compiles identically on both engines.
 */
final class NameRules
{
    /** First / Middle / Last name: letters, spaces, hyphens, apostrophes, periods. */
    public const PERSON_PATTERN = "^\p{L}[\p{L}\p{M}\s'’.\-]*$";

    /** Suffix (Jr., Sr., II, III, IV, V): letters, spaces, periods, hyphens — no apostrophe. */
    public const SUFFIX_PATTERN = "^\p{L}[\p{L}\p{M}\s.\-]*$";

    public const PERSON_MESSAGE = 'Use letters only. Spaces, hyphens, apostrophes, and periods are allowed.';

    public const SUFFIX_MESSAGE = 'Use letters only (e.g. Jr., Sr., II, III). Spaces, hyphens, and periods are allowed.';

    /** Laravel validation rule fragment for a required-elsewhere person-name field. */
    public static function person(): string
    {
        return 'regex:/'.self::PERSON_PATTERN.'/u';
    }

    /** Laravel validation rule fragment for a suffix field. */
    public static function suffix(): string
    {
        return 'regex:/'.self::SUFFIX_PATTERN.'/u';
    }

    /** Config handed to the client-side Alpine nameGuard() component via @js(). */
    public static function jsConfig(): array
    {
        return [
            'personPattern' => self::PERSON_PATTERN,
            'suffixPattern' => self::SUFFIX_PATTERN,
            'personMessage' => self::PERSON_MESSAGE,
            'suffixMessage' => self::SUFFIX_MESSAGE,
        ];
    }
}
