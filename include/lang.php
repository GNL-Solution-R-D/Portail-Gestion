<?php
// Language management

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$supportedLanguages = ['fr', 'en', 'es', 'de'];
$defaultLanguage = 'fr';

if (!function_exists('normalize_lang_code')) {
    function normalize_lang_code(?string $value, array $supportedLanguages, string $defaultLanguage = 'fr'): string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return $defaultLanguage;
        }

        $aliases = [
            'fr' => 'fr',
            'fr-fr' => 'fr',
            'français' => 'fr',
            'francais' => 'fr',
            'french' => 'fr',
            'en' => 'en',
            'en-us' => 'en',
            'en-gb' => 'en',
            'english' => 'en',
            'es' => 'es',
            'es-es' => 'es',
            'español' => 'es',
            'espanol' => 'es',
            'spanish' => 'es',
            'de' => 'de',
            'de-de' => 'de',
            'deutsch' => 'de',
            'german' => 'de',
        ];

        $normalized = $aliases[$value] ?? substr($value, 0, 2);
        return in_array($normalized, $supportedLanguages, true) ? $normalized : $defaultLanguage;
    }
}

if (!function_exists('detect_browser_language')) {
    function detect_browser_language(array $supportedLanguages, string $defaultLanguage = 'fr'): string
    {
        $header = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($header === '') {
            return $defaultLanguage;
        }

        foreach (explode(',', $header) as $entry) {
            $candidate = trim((string)explode(';', $entry)[0]);
            $normalized = normalize_lang_code($candidate, $supportedLanguages, '');
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $defaultLanguage;
    }
}

if (!function_exists('can_set_language_cookie')) {
    function can_set_language_cookie(): bool
    {
        if (!isset($_COOKIE['slapia_consent'])) {
            return false;
        }

        $consent = json_decode((string)$_COOKIE['slapia_consent'], true);
        return is_array($consent)
            && isset($consent['preferences'])
            && $consent['preferences'] === true;
    }
}

if (!function_exists('is_https_request')) {
    function is_https_request(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }

        return false;
    }
}

if (!function_exists('set_language_cookie')) {
    function set_language_cookie(string $language): void
    {
        if (headers_sent()) {
            return;
        }

        $options = [
            'expires' => time() + (365 * 24 * 60 * 60),
            'path' => '/',
            'secure' => is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie('lang', $language, $options);
    }
}

if (!function_exists('expire_language_cookie')) {
    function expire_language_cookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie('lang', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => is_https_request(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('find_translation_directory')) {
    function find_translation_directory(): ?string
    {
        $candidates = [
            __DIR__ . '/../assets/lang',
            dirname(__DIR__) . '/assets/lang',
            __DIR__ . '/assets/lang',
            __DIR__ . '/../i18n_output',
            dirname(__DIR__) . '/i18n_output',
            __DIR__ . '/i18n_output',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }
}

if (!function_exists('load_translation_file')) {
    function load_translation_file(string $language, string $defaultLanguage = 'fr'): array
    {
        $dir = find_translation_directory();
        if ($dir === null) {
            return [];
        }

        $fallbackFile = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $defaultLanguage . '.json';
        $languageFile = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $language . '.json';

        $fallbackTranslations = [];
        if (is_file($fallbackFile)) {
            $data = json_decode((string)file_get_contents($fallbackFile), true);
            if (is_array($data)) {
                $fallbackTranslations = $data;
            }
        }

        if ($language === $defaultLanguage) {
            return $fallbackTranslations;
        }

        $currentTranslations = [];
        if (is_file($languageFile)) {
            $data = json_decode((string)file_get_contents($languageFile), true);
            if (is_array($data)) {
                $currentTranslations = $data;
            }
        }

        return array_replace($fallbackTranslations, $currentTranslations);
    }
}

$requestedLanguage = isset($_GET['lang'])
    ? normalize_lang_code((string)$_GET['lang'], $supportedLanguages, $defaultLanguage)
    : null;

$sessionLanguage = isset($_SESSION['language'])
    ? normalize_lang_code((string)$_SESSION['language'], $supportedLanguages, $defaultLanguage)
    : null;

$cookieLanguage = isset($_COOKIE['lang'])
    ? normalize_lang_code((string)$_COOKIE['lang'], $supportedLanguages, $defaultLanguage)
    : null;

$browserLanguage = detect_browser_language($supportedLanguages, $defaultLanguage);

$lang = $requestedLanguage
    ?? $sessionLanguage
    ?? $cookieLanguage
    ?? $browserLanguage
    ?? $defaultLanguage;

$_SESSION['language'] = $lang;

if ($requestedLanguage !== null) {
    if (can_set_language_cookie()) {
        set_language_cookie($lang);
    } elseif (isset($_COOKIE['lang'])) {
        expire_language_cookie();
    }
}

$translations = load_translation_file($lang, $defaultLanguage);

if (!function_exists('t')) {
    function t(string $key, array $replace = []): string
    {
        global $translations;

        $text = isset($translations[$key]) ? (string)$translations[$key] : $key;

        if ($replace !== []) {
            $replacements = [];
            foreach ($replace as $replaceKey => $replaceValue) {
                $replacements[':' . $replaceKey] = (string)$replaceValue;
            }
            $text = strtr($text, $replacements);
        }

        return $text;
    }
}
?>
