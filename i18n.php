<?php
// ============================================================
//  PizzaVote – Übersetzungs-Helper
//  Datei: i18n.php
// ============================================================

function loadLang(string $lang): array {
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];
    $path = __DIR__ . "/lang/{$lang}.json";
    $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    return $cache[$lang] = is_array($data) ? $data : [];
}

function t(string $key, array $vars = []): string {
    $lang = defined('APP_LANG') ? APP_LANG : 'de';
    $strings = loadLang($lang);
    if (array_key_exists($key, $strings)) {
        $text = $strings[$key];
    } else {
        $fallback = loadLang('de');
        $text = $fallback[$key] ?? $key;
    }
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string)$value, $text);
    }
    return $text;
}
