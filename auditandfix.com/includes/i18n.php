<?php
/**
 * Internationalisation helper for auditandfix.com
 *
 * Language selection priority:
 *   1. ?lang= GET param  (buy link override — most explicit)
 *   2. af_lang cookie    (persisted from a previous visit)
 *   3. Accept-Language HTTP header (browser default)
 *   4. 'en' fallback
 *
 * Supported languages: en, de, fr, es, ja, pt, nl, da, sv, ko, it, pl, zh, id
 * English (en.json) is always the master / fallback.
 */

define('SUPPORTED_LANGS', ['en', 'de', 'fr', 'es', 'ja', 'pt', 'nl', 'da', 'sv', 'ko', 'it', 'pl', 'zh', 'id', 'ru', 'hi', 'ar', 'tr', 'th', 'nb']);
define('LANG_DIR', __DIR__ . '/../lang/');

/**
 * Detect the best language for this request.
 */
function detectLang(): string {
    // 1. Explicit ?lang= param
    if (!empty($_GET['lang'])) {
        $l = strtolower(substr((string)$_GET['lang'], 0, 5));
        // Accept both 'en' and 'en-AU' style codes
        $code = substr($l, 0, 2);
        if (in_array($code, SUPPORTED_LANGS, true) && file_exists(LANG_DIR . $code . '.json')) {
            return $code;
        }
    }

    // 2. Cookie
    if (!empty($_COOKIE['af_lang'])) {
        $code = strtolower(substr((string)$_COOKIE['af_lang'], 0, 2));
        if (in_array($code, SUPPORTED_LANGS, true) && file_exists(LANG_DIR . $code . '.json')) {
            return $code;
        }
    }

    // 3. Accept-Language header
    $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($acceptLang) {
        // Parse first preference, e.g. "de-AT,de;q=0.9,en;q=0.8" → "de"
        $code = strtolower(substr($acceptLang, 0, 2));
        if (in_array($code, SUPPORTED_LANGS, true) && file_exists(LANG_DIR . $code . '.json')) {
            return $code;
        }
    }

    return 'en';
}

/**
 * Load translations. Falls back to English for any missing keys.
 */
function loadTranslations(string $lang): array {
    $enPath   = LANG_DIR . 'en.json';
    $langPath = LANG_DIR . $lang . '.json';

    $en = json_decode(file_get_contents($enPath), true) ?? [];

    if ($lang === 'en' || !file_exists($langPath)) {
        return $en;
    }

    $translations = json_decode(file_get_contents($langPath), true) ?? [];
    // Merge: language-specific values override English, missing keys fall back to English
    return array_merge($en, $translations);
}

// ── Bootstrap ──────────────────────────────────────────────────────────────

$lang = detectLang();

// Persist language choice in a cookie (30 days)
if (!isset($_COOKIE['af_lang']) || $_COOKIE['af_lang'] !== $lang) {
    setcookie('af_lang', $lang, time() + 60 * 60 * 24 * 30, '/', '', true, true);
}

$i18n = loadTranslations($lang);

/**
 * Translate a key. Returns the translated string (may contain safe HTML).
 * Substitutes {placeholder} tokens with provided $vars values.
 *
 * Use for all user-facing strings. Dynamic values (prices, emails) must be
 * passed as $vars so they get htmlspecialchars() applied.
 *
 * @param string $key  Dot-notation key from lang/en.json
 * @param array  $vars Associative array of {token} → value substitutions
 */
function t(string $key, array $vars = []): string {
    global $i18n;
    $str = $i18n[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace('{' . $k . '}', htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $str);
    }
    return $str;
}

/**
 * Language name map for the switcher UI.
 */
function langNames(): array {
    return [
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'ja' => 'Japanese / 日本語',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'da' => 'Dansk',
        'sv' => 'Svenska',
        'ko' => 'Korean / 한국어',
        'it' => 'Italiano',
        'pl' => 'Polski',
        'zh' => 'Chinese / 中文',
        'id' => 'Bahasa Indonesia',
        'ru' => 'Русский',
        'hi' => 'Hindi / हिन्दी',
        'ar' => 'العربية',
        'tr' => 'Türkçe',
        'th' => 'Thai / ไทย',
        'nb' => 'Norsk',
    ];
}
