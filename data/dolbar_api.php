<?php

declare(strict_types=1);

if (!function_exists('dolbarApiExtractErrorCode')) {
    function dolbarApiExtractErrorCode(Throwable $e): ?string
    {
        $code = $e->getCode();
        if ((is_int($code) || preg_match('/^-?\d+$/', (string)$code)) && (int)$code !== 0) {
            return (string)(int)$code;
        }

        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $e->getMessage(), $matches)) {
            return $matches[1];
        }

        if (preg_match('/\bstatus(?:\s+code)?\s*[:=]?\s*(\d{3})\b/i', $e->getMessage(), $matches)) {
            return $matches[1];
        }

        return null;
    }
}

if (!function_exists('dolbarApiConfigValue')) {
    function dolbarApiConfigValue(array $keys, array $userContext = []): ?string
    {
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($userContext !== [] && array_key_exists($key, $userContext)) {
                $value = $userContext[$key];
                if ($value !== null && $value !== '') {
                    return trim((string)$value);
                }
            }

            if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null && $_ENV[$key] !== '') {
                return trim((string)$_ENV[$key]);
            }

            if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && $_SERVER[$key] !== '') {
                return trim((string)$_SERVER[$key]);
            }

            $envValue = getenv($key);
            if ($envValue !== false && $envValue !== '') {
                return trim((string)$envValue);
            }

            if (defined($key)) {
                $constantValue = constant($key);
                if ($constantValue !== null && $constantValue !== '') {
                    return trim((string)$constantValue);
                }
            }
        }

        return null;
    }
}




if (!function_exists('dolbarApiIntegrationEnabled')) {
    function dolbarApiIntegrationEnabled(): bool
    {
        $value = dolbarApiConfigValue(['DOLIBARR_ENABLED', 'DOLBAR_ENABLED']);
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}

if (!function_exists('dolbarApiAssertIntegrationEnabled')) {
    function dolbarApiAssertIntegrationEnabled(): void
    {
        if (!dolbarApiIntegrationEnabled()) {
            throw new RuntimeException('Connexion Dolibarr désactivée.', 0);
        }
    }
}

if (!function_exists('dolbarApiCandidateUrlKeys')) {
    function dolbarApiCandidateUrlKeys(): array
    {
        return [
            'dolbar_api_url', 'dolibarr_api_url', 'DOLBAR_API_URL', 'DOLIBARR_API_URL',
            // Variantes fréquemment utilisées dans les environnements existants.
            'dolbar_url', 'dolibarr_url', 'DOLBAR_URL', 'DOLIBARR_URL',
        ];
    }
}

if (!function_exists('dolbarApiCandidateKeyKeys')) {
function dolbarApiCandidateKeyKeys(): array
{
    return [
        'dolbar_api_key', 'dolibarr_api_key', 'DOLBAR_API_KEY', 'DOLIBARR_API_KEY',
        // Variantes fréquemment utilisées dans les environnements existants.
        'dolbar_key', 'dolibarr_key', 'DOLBAR_KEY', 'DOLIBARR_KEY',
        'dolapikey', 'DOLAPIKEY',
        // Compatibilité SSO Keycloak (mapper token_dolibarr).
        'token_dolibarr', 'dolibarr_token',
        'TOKEN_DOLIBARR', 'DOLIBARR_TOKEN',
        'dolibarr.token',
    ];
}
}

if (!function_exists('dolbarApiCandidateLoginKeys')) {
    function dolbarApiCandidateLoginKeys(): array
    {
        return [
            'dolbar_login', 'dolibarr_login', 'DOLBAR_LOGIN', 'DOLIBARR_LOGIN',
            'dolbar_username', 'dolibarr_username', 'DOLBAR_USERNAME', 'DOLIBARR_USERNAME',
        ];
    }
}

if (!function_exists('dolbarApiCandidatePasswordKeys')) {
    function dolbarApiCandidatePasswordKeys(): array
    {
        return [
            'dolbar_password', 'dolibarr_password', 'DOLBAR_PASSWORD', 'DOLIBARR_PASSWORD',
            'dolbar_pass', 'dolibarr_pass', 'DOLBAR_PASS', 'DOLIBARR_PASS',
        ];
    }
}

if (!function_exists('dolbarApiNormalizeBaseUrl')) {
    function dolbarApiNormalizeBaseUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('URL Dolbar vide.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('URL Dolbar invalide.');
        }

        $path = $parts['path'] ?? '';
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        if (!preg_match('#/api(?:/index\.php)?$#i', $path)) {
            $path = rtrim($path, '/') . '/api/index.php';
        } elseif (preg_match('#/api$#i', $path)) {
            $path .= '/index.php';
        }

        $base = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $base .= ':' . (int)$parts['port'];
        }

        return $base . $path;
    }
}

if (!function_exists('dolbarApiHttpRequest')) {
    function dolbarApiHttpRequest(
        string $baseApiUrl,
        string $endpoint,
        string $method = 'GET',
        array $query = [],
        array $body = [],
        array $headers = [],
        int $timeout = 8
    ): array {
        dolbarApiAssertIntegrationEnabled();

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension cURL indisponible.', 500);
        }

        $endpoint = '/' . ltrim(trim($endpoint), '/');
        $url = rtrim($baseApiUrl, '/') . $endpoint;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Initialisation cURL impossible.', 500);
        }

        $method = strtoupper(trim($method));
        $json = null;
        if ($body !== []) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Encodage JSON impossible.', 500);
            }
        }

        $httpHeaders = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
        ], $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => max(3, $timeout),
            CURLOPT_FAILONERROR => false,
        ]);

        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Erreur réseau Dolbar: ' . $curlError, 500);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode . ' retourné par Dolbar.', $httpCode);
        }

        if ($responseBody === '' || $responseBody === null) {
            return [];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse JSON Dolbar invalide.', 500);
        }

        return $decoded;
    }
}

if (!function_exists('dolbarApiCall')) {
    function dolbarApiCall(
        string $baseApiUrl,
        string $endpoint,
        string $apiKey,
        string $method = 'GET',
        array $query = [],
        array $body = [],
        int $timeout = 8
    ): array {
        dolbarApiAssertIntegrationEnabled();

        if (trim($apiKey) === '') {
            throw new RuntimeException('Clé API Dolbar absente.', 0);
        }

        $headers = [
            'DOLAPIKEY: ' . $apiKey,
        ];

        return dolbarApiHttpRequest($baseApiUrl, $endpoint, $method, $query, $body, $headers, $timeout);
    }
}

if (!function_exists('dolbarApiLoginToken')) {
    function dolbarApiLoginToken(
        string $baseApiUrl,
        string $login,
        string $password,
        int $timeout = 8
    ): string {
        dolbarApiAssertIntegrationEnabled();

        if (trim($login) === '' || trim($password) === '') {
            throw new RuntimeException('Identifiants Dolibarr absents.', 0);
        }

        $payload = dolbarApiHttpRequest(
            $baseApiUrl,
            '/login',
            'GET',
            [
                'login' => $login,
                'password' => $password,
            ],
            [],
            [],
            $timeout
        );

        $candidates = [
            $payload['token'] ?? null,
            $payload['access_token'] ?? null,
            $payload['success']['token'] ?? null,
            $payload['data']['token'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        throw new RuntimeException('Token Dolibarr introuvable dans la réponse login.', 500);
    }
}

if (!function_exists('dolbarApiCallWithToken')) {
    function dolbarApiCallWithToken(
        string $baseApiUrl,
        string $endpoint,
        string $token,
        string $method = 'GET',
        array $query = [],
        array $body = [],
        int $timeout = 8
    ): array {
        dolbarApiAssertIntegrationEnabled();

        if (trim($token) === '') {
            throw new RuntimeException('Token Dolibarr absent.', 0);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'DOLAPIKEY: ' . $token,
        ];

        return dolbarApiHttpRequest($baseApiUrl, $endpoint, $method, $query, $body, $headers, $timeout);
    }
}



if (!function_exists('dolbarApiNormalizeSiret')) {
    function dolbarApiNormalizeSiret($value): string
    {
        $raw = trim((string)$value);
        if ($raw == '') {
            return '';
        }

        return preg_replace('/\D+/', '', $raw) ?? '';
    }
}

if (!function_exists('dolbarApiResolveSessionToken')) {
    function dolbarApiResolveSessionToken(array $session): string
    {
        $directCandidates = [
            $session['dolibarr_token'] ?? null,
            $session['dolbar_token'] ?? null,
        ];

        foreach ($directCandidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $userContext = isset($session['user']) && is_array($session['user']) ? $session['user'] : [];
        $fromUser = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $userContext);
        return $fromUser !== null ? trim($fromUser) : '';
    }
}

if (!function_exists('dolbarApiFirstScalarValue')) {
    function dolbarApiFirstScalarValue(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || !array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (is_string($value) || is_numeric($value)) {
                $trimmed = trim((string)$value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }
}

if (!function_exists('dolbarApiDateToTimestamp')) {
    function dolbarApiDateToTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            foreach (['timestamp', 'ts', 'date'] as $subKey) {
                if (array_key_exists($subKey, $value)) {
                    $candidate = dolbarApiDateToTimestamp($value[$subKey]);
                    if ($candidate !== null) {
                        return $candidate;
                    }
                }
            }

            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int)$value;
            return $timestamp > 0 ? $timestamp : null;
        }

        $timestamp = strtotime((string)$value);
        return $timestamp !== false ? $timestamp : null;
    }
}

if (!function_exists('dolbarApiRowMatchesSiret')) {
    function dolbarApiRowMatchesSiret(array $row, string $expectedSiret): bool
    {
        $expected = dolbarApiNormalizeSiret($expectedSiret);
        if ($expected === '') {
            return false;
        }
        $expectedSiren = strlen($expected) >= 9 ? substr($expected, 0, 9) : $expected;

        $stack = [$row];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }

                $normalizedKey = strtolower((string)$key);
                if ($normalizedKey !== 'siret' && $normalizedKey !== 'siren') {
                    continue;
                }

                $normalizedValue = dolbarApiNormalizeSiret($value);
                if ($normalizedValue === '') {
                    continue;
                }

                if ($normalizedKey === 'siret' && $normalizedValue === $expected) {
                    return true;
                }

                if ($normalizedKey === 'siren' && $normalizedValue === $expectedSiren) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('dolbarApiHealthcheck')) {
    function dolbarApiHealthcheck(array $userContext = []): array
    {
        try {
            dolbarApiAssertIntegrationEnabled();
            $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $userContext);
            $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $userContext);

            if ($apiUrl === null) {
                throw new RuntimeException('Configuration Dolbar absente: URL API.', 0);
            }
            if ($apiKey === null) {
                throw new RuntimeException('Configuration Dolbar absente: clé API.', 0);
            }

            $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);

            // Endpoint léger pour vérifier le bon fonctionnement de l'API REST.
            dolbarApiCall($apiUrl, '/status', $apiKey, 'GET', [], [], 6);

            return [
                'ok' => true,
                'error_code' => null,
                'message' => 'Dolbar API joignable.',
            ];
        } catch (Throwable $e) {
            $lowerMessage = strtolower($e->getMessage());
            $errorCode = dolbarApiExtractErrorCode($e)
                ?? (str_contains($lowerMessage, 'config') ? 'CONFIG' : 'DLB');

            return [
                'ok' => false,
                'error_code' => $errorCode,
                'message' => $e->getMessage(),
            ];
        }
    }
}
