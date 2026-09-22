<?php
/**
 * Minimal English/Tagalog toggle for resident-facing pages. Translation
 * strings live in lang/en.php and lang/tl.php (flat 'section.key' => string
 * arrays). Scope is deliberately resident-facing only -- admin/staff
 * dashboard pages are not wired to frs_t() and stay English-only.
 */
declare(strict_types=1);

if (!function_exists('frs_available_locales')) {
    /**
     * @return array<string, string> locale code => native display name
     */
    function frs_available_locales(): array
    {
        return [
            'en' => 'English',
            'tl' => 'Tagalog',
        ];
    }
}

if (!function_exists('frs_default_locale')) {
    function frs_default_locale(): string
    {
        return 'en';
    }
}

if (!function_exists('frs_current_locale')) {
    function frs_current_locale(): string
    {
        $locale = (string)($_SESSION['locale'] ?? '');
        if (!array_key_exists($locale, frs_available_locales())) {
            $locale = frs_default_locale();
        }
        return $locale;
    }
}

if (!function_exists('frs_set_locale')) {
    function frs_set_locale(string $locale): bool
    {
        if (!array_key_exists($locale, frs_available_locales())) {
            return false;
        }
        $_SESSION['locale'] = $locale;
        return true;
    }
}

if (!function_exists('frs_load_lang_strings')) {
    /**
     * @return array<string, string>
     */
    function frs_load_lang_strings(string $locale): array
    {
        static $cache = [];
        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        // Base file holds shared chrome (nav, sidebar, statuses, buttons).
        $strings = [];
        $base = dirname(__DIR__) . "/lang/{$locale}.php";
        if (is_file($base)) {
            $loaded = include $base;
            if (is_array($loaded)) {
                $strings = $loaded;
            }
        }

        // Per-page fragments in lang/{locale}/*.php are merged on top. Split
        // this way so each page's strings live in their own file instead of
        // one ever-growing array that every change has to touch.
        foreach (glob(dirname(__DIR__) . "/lang/{$locale}/*.php") ?: [] as $fragment) {
            $loaded = include $fragment;
            if (is_array($loaded)) {
                $strings = array_merge($strings, $loaded);
            }
        }

        return $cache[$locale] = $strings;
    }
}

if (!function_exists('frs_t')) {
    /**
     * Translate a key for the current session locale. Falls back to
     * English, then to the raw key, so a missing translation never breaks
     * the page -- it just silently shows English (or the key, in dev).
     *
     * @param array<string, string|int> $replace ':name' => value substitutions
     */
    function frs_t(string $key, array $replace = []): string
    {
        $locale = frs_current_locale();
        $strings = frs_load_lang_strings($locale);
        $text = $strings[$key]
            ?? frs_load_lang_strings(frs_default_locale())[$key]
            ?? $key;
        if ($replace !== []) {
            $pairs = [];
            foreach ($replace as $name => $value) {
                $pairs[':' . $name] = (string)$value;
            }
            // strtr() substitutes in a single longest-match pass. A
            // sequential str_replace() loop would let a short placeholder
            // corrupt a longer one that starts with it (:to eating the head
            // of :total), and would re-scan already-substituted values for
            // further placeholders.
            $text = strtr($text, $pairs);
        }
        return $text;
    }
}

if (!function_exists('frs_te')) {
    /**
     * frs_t() pre-escaped for direct HTML output: <?= frs_te('nav.home') ?>
     *
     * @param array<string, string|int> $replace
     */
    function frs_te(string $key, array $replace = []): string
    {
        return htmlspecialchars(frs_t($key, $replace), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('frs_language_switcher')) {
    /**
     * Icon-only English/Tagalog toggle. Renders a single round button (a
     * globe glyph, no visible text) that flips to the other locale via
     * /set-language and returns to the current page.
     *
     * Deliberately an SVG globe rather than flag emoji: Windows browsers
     * don't render regional-indicator flags and fall back to showing the
     * two letters ("PH"), which would put words back in the button.
     */
    function frs_language_switcher(string $extraClass = ''): string
    {
        $locales = frs_available_locales();
        $codes = array_keys($locales);
        $current = frs_current_locale();
        // Two-locale setup: "the other one".
        $next = ($current === ($codes[0] ?? 'en')) ? ($codes[1] ?? 'en') : ($codes[0] ?? 'en');

        $returnTo = urlencode((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $base = base_path();
        $class = trim('frs-lang-toggle ' . $extraClass);

        // Tooltip carries the language names so the icon stays wordless
        // without losing meaning for sighted or screen-reader users.
        $label = $current === 'tl'
            ? 'Wika: Tagalog — lumipat sa English'
            : 'Language: English — switch to Tagalog';

        $svg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
            . ' stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="9"></circle>'
            . '<path d="M3 12h18"></path>'
            . '<path d="M12 3c2.6 2.8 3.9 5.8 3.9 9s-1.3 6.2-3.9 9c-2.6-2.8-3.9-5.8-3.9-9S9.4 5.8 12 3z"></path>'
            . '</svg>';

        return '<a href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/set-language?lang=' . $next . '&amp;return=' . $returnTo . '"'
            . ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-locale="' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . $svg
            . '</a>';
    }
}
