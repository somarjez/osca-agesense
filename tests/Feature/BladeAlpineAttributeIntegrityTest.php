<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression guard for a bug class this repo has already shipped twice:
 * a literal `"` inside a `//` JS comment sitting inside a double-quoted
 * Alpine/Livewire attribute (x-data="...") terminates the attribute early.
 * The browser's HTML parser then closes the tag at the next `>` it finds
 * (often a `>` comparison operator inside the script), and everything after
 * that renders as raw visible text on the page instead of running as JS.
 *
 * beda942 ("fix(ui): stray quote in code comment breaks Re-run Assessment
 * button (#190)") fixed exactly this in resources/views/ml/batch.blade.php
 * by rewording two comments — with no guard. Two later commits reintroduced
 * it with new comments, breaking the entire Batch Analysis panel (raw JS
 * visible on /ml/batch, "Run Full Batch Assessment" unusable). Rewording
 * alone has failed twice; this test is the actual fix.
 *
 * Detection: within every double-quoted x-data/x-init/x-show/x-effect/
 * x-text/x-model/wire:click/wire:submit attribute value that spans multiple
 * lines, the value must close on `}`, `)` or `'` — the natural end of a JS
 * object/expression/string. A value that instead closes on some other
 * character means the closing `"` we found isn't the real one; a stray `"`
 * earlier in the value (almost always inside a `//` comment) closed the
 * attribute prematurely. Verified against this exact repo: flags nothing on
 * the current (fixed) tree, and flags ml/batch.blade.php when the two fixed
 * comments are reverted to their pre-fix wording.
 */
class BladeAlpineAttributeIntegrityTest extends TestCase
{
    /**
     * Deliberately narrower than "every Alpine-ish attribute" — `@click`-style
     * shorthand and `:prop` bindings are excluded because Blade components
     * also use `:prop="[...]"` to pass plain PHP arrays (e.g. <x-page-header
     * :links="[...]" />), which legitimately span multiple lines and would
     * be false positives here. The attributes below are never used to pass
     * a PHP array literal — only Alpine/Livewire JS — so this list has zero
     * false positives across the current view tree (confirmed during this
     * test's construction).
     */
    private const ATTR_PATTERN = '(?:x-data|x-init|x-show|x-effect|x-text|x-model(?:\.[\w.\-]+)?'
        .'|wire:click(?:\.[\w.\-]+)?|wire:submit(?:\.[\w.\-]+)?)';

    #[Test]
    public function no_multi_line_alpine_attribute_is_terminated_early_by_a_stray_quote(): void
    {
        $viewsPath = resource_path('views');
        $pattern = '/\s('.self::ATTR_PATTERN.')="/';

        $failures = [];

        foreach ($this->bladeFiles($viewsPath) as $file) {
            $src = file_get_contents($file);
            $relative = str_replace($viewsPath.DIRECTORY_SEPARATOR, '', $file);

            if (! preg_match_all($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $i => $wholeMatch) {
                $attrName = $matches[1][$i][0];
                $matchStart = $wholeMatch[1];
                $valueStart = $matchStart + strlen($wholeMatch[0]);

                $closeQuote = strpos($src, '"', $valueStart);
                if ($closeQuote === false) {
                    continue;
                }

                $value = substr($src, $valueStart, $closeQuote - $valueStart);
                if (! str_contains($value, "\n")) {
                    continue; // Single-line values can't hide a mid-comment stray quote this way.
                }

                $trimmed = rtrim($value);
                $lastChar = $trimmed === '' ? '' : $trimmed[strlen($trimmed) - 1];

                if (! in_array($lastChar, ['}', ')', "'"], true)) {
                    $line = substr_count($src, "\n", 0, $matchStart) + 1;
                    $failures[] = sprintf(
                        '%s:%d — %s="..." is terminated early by a stray quote (closes on %s, not a JS-balanced }/)/\'). '
                            .'Check for a literal " inside a // comment within this attribute.',
                        $relative,
                        $line,
                        $attrName,
                        $lastChar === '' ? '(empty)' : "'{$lastChar}'"
                    );
                }
            }
        }

        $this->assertSame([], $failures, "Found Alpine attribute(s) broken by a stray quote:\n".implode("\n", $failures));
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.blade.php')) {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }
}
