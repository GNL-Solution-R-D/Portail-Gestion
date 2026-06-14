<?php

declare(strict_types=1);

if (!function_exists('zabbixApiExtractErrorCode')) {
    function zabbixApiExtractErrorCode(Throwable $e): ?string
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

if (!function_exists('zabbixApiConfigValue')) {
    function zabbixApiConfigValue(array $keys, array $userContext = []): ?string
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

if (!function_exists('zabbixApiNormalizeUrl')) {
    function zabbixApiNormalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('URL Zabbix vide.');
        }

        if (!preg_match('#/api_jsonrpc\.php$#i', $url)) {
            $url = rtrim($url, '/');
            $url .= '/api_jsonrpc.php';
        }

        return $url;
    }
}

if (!function_exists('zabbixApiHttpRequest')) {
    function zabbixApiHttpRequest(string $apiUrl, array $payload, array $headers = [], int $timeout = 5): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extension cURL indisponible.', 500);
        }

        $ch = curl_init($apiUrl);
        if ($ch === false) {
            throw new RuntimeException('Initialisation cURL impossible.', 500);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Encodage JSON impossible.', 500);
        }

        $httpHeaders = array_merge([
            'Content-Type: application/json-rpc',
            'Accept: application/json',
        ], $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => max(3, $timeout),
            CURLOPT_FAILONERROR => false,
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Erreur réseau Zabbix: ' . $curlError, 500);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode . ' retourné par Zabbix.', $httpCode);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse JSON Zabbix invalide.', 500);
        }

        return $decoded;
    }
}

if (!function_exists('zabbixApiCall')) {
    function zabbixApiCall(
        string $apiUrl,
        string $method,
        array $params,
        ?string $authorizationToken = null,
        ?string $authFieldToken = null,
        int $timeout = 5
    ): array {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ];

        if ($authFieldToken !== null && $authFieldToken !== '') {
            $payload['auth'] = $authFieldToken;
        }

        $headers = [];
        if ($authorizationToken !== null && $authorizationToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $authorizationToken;
        }

        $response = zabbixApiHttpRequest($apiUrl, $payload, $headers, $timeout);

        if (isset($response['error']) && is_array($response['error'])) {
            $errorCode = (int)($response['error']['code'] ?? 0);
            $errorMessage = (string)($response['error']['message'] ?? 'Erreur Zabbix');
            $errorData = isset($response['error']['data']) ? ' - ' . (string)$response['error']['data'] : '';
            throw new RuntimeException('Zabbix API ' . $method . ': ' . $errorMessage . $errorData, $errorCode);
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }
}

if (!function_exists('zabbixApiLogin')) {
    function zabbixApiLogin(string $apiUrl, string $username, string $password): string
    {
        try {
            $response = zabbixApiHttpRequest($apiUrl, [
                'jsonrpc' => '2.0',
                'method' => 'user.login',
                'params' => [
                    'username' => $username,
                    'password' => $password,
                ],
                'id' => 1,
            ]);
        } catch (Throwable $e) {
            $response = zabbixApiHttpRequest($apiUrl, [
                'jsonrpc' => '2.0',
                'method' => 'user.login',
                'params' => [
                    'user' => $username,
                    'password' => $password,
                ],
                'id' => 1,
            ]);
        }

        if (isset($response['error']) && is_array($response['error'])) {
            $errorCode = (int)($response['error']['code'] ?? 0);
            $errorMessage = (string)($response['error']['message'] ?? 'Connexion Zabbix refusée');
            $errorData = isset($response['error']['data']) ? ' - ' . (string)$response['error']['data'] : '';
            throw new RuntimeException('Zabbix login: ' . $errorMessage . $errorData, $errorCode);
        }

        $token = $response['result'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Jeton Zabbix invalide.', 500);
        }

        return $token;
    }
}

if (!function_exists('zabbixApiFormatAvailabilityPercent')) {
    function zabbixApiFormatAvailabilityPercent(float $value): string
    {
        $decimals = $value >= 99 ? 3 : 2;
        return number_format($value, $decimals, ',', ' ') . ' %';
    }
}

if (!function_exists('zabbixApiGetAnnualAvailabilityDisplay')) {
    function zabbixApiGetAnnualAvailabilityDisplay(array $userContext = []): array
    {
        $display = '---';
        $errorCode = null;

        try {
            $zabbixApiUrl = zabbixApiConfigValue([
                'zabbix_api_url', 'zabbix_url', 'ZABBIX_API_URL', 'ZABBIX_URL', 'ZABBIX_FRONTEND_URL',
            ], $userContext);

            if ($zabbixApiUrl === null) {
                throw new RuntimeException('Configuration Zabbix absente: URL API.', 0);
            }

            $zabbixApiUrl = zabbixApiNormalizeUrl($zabbixApiUrl);

            $zabbixApiToken = zabbixApiConfigValue([
                'zabbix_api_token', 'zabbix_token', 'ZABBIX_API_TOKEN', 'ZABBIX_TOKEN',
            ], $userContext);

            $zabbixUsername = zabbixApiConfigValue([
                'zabbix_api_username', 'zabbix_username', 'ZABBIX_API_USERNAME', 'ZABBIX_USERNAME',
            ], $userContext);

            $zabbixPassword = zabbixApiConfigValue([
                'zabbix_api_password', 'zabbix_password', 'ZABBIX_API_PASSWORD', 'ZABBIX_PASSWORD',
            ], $userContext);

            $authorizationToken = $zabbixApiToken;
            $authFieldToken = null;

            if (($authorizationToken === null || $authorizationToken === '') && $zabbixUsername && $zabbixPassword) {
                $loginToken = zabbixApiLogin($zabbixApiUrl, $zabbixUsername, $zabbixPassword);
                $authorizationToken = $loginToken;
                $authFieldToken = $loginToken;
            }

            if ($authorizationToken === null || $authorizationToken === '') {
                throw new RuntimeException('Configuration Zabbix absente: jeton API ou identifiants.', 0);
            }

            $configuredSlaId = zabbixApiConfigValue([
                'zabbix_sla_id', 'sla_id', 'ZABBIX_SLA_ID',
            ], $userContext);
            $configuredSlaName = zabbixApiConfigValue([
                'zabbix_sla_name', 'sla_name', 'ZABBIX_SLA_NAME',
            ], $userContext);

            $slaResult = [];
            if ($configuredSlaId !== null && $configuredSlaId !== '') {
                $slaResult = zabbixApiCall(
                    $zabbixApiUrl,
                    'sla.get',
                    [
                        'output' => ['slaid', 'name', 'timezone'],
                        'slaids' => [$configuredSlaId],
                        'limit' => 1,
                    ],
                    $authorizationToken,
                    $authFieldToken
                );
            } elseif ($configuredSlaName !== null && $configuredSlaName !== '') {
                $slaResult = zabbixApiCall(
                    $zabbixApiUrl,
                    'sla.get',
                    [
                        'output' => ['slaid', 'name', 'timezone'],
                        'filter' => ['name' => [$configuredSlaName]],
                        'limit' => 1,
                    ],
                    $authorizationToken,
                    $authFieldToken
                );
            } else {
                throw new RuntimeException('Configuration Zabbix absente: SLA non définie.', 0);
            }

            $selectedSla = is_array($slaResult) ? reset($slaResult) : null;
            if (!is_array($selectedSla) || empty($selectedSla['slaid'])) {
                throw new RuntimeException('SLA Zabbix introuvable.', 404);
            }

            $slaTimezone = (string)($selectedSla['timezone'] ?? 'Europe/Paris');
            try {
                $reportTimezone = new DateTimeZone($slaTimezone !== '' ? $slaTimezone : 'Europe/Paris');
            } catch (Throwable $e) {
                $reportTimezone = new DateTimeZone('Europe/Paris');
            }

            $now = new DateTimeImmutable('now', $reportTimezone);
            $yearStart = new DateTimeImmutable($now->format('Y') . '-01-01 00:00:00', $reportTimezone);

            $sliResult = zabbixApiCall(
                $zabbixApiUrl,
                'sla.getsli',
                [
                    'slaid' => (string)$selectedSla['slaid'],
                    'period_from' => $yearStart->getTimestamp(),
                    'period_to' => $now->getTimestamp(),
                ],
                $authorizationToken,
                $authFieldToken,
                8
            );

            $uptimeTotal = 0;
            $downtimeTotal = 0;

            $periodSlices = $sliResult['sli'] ?? [];
            if (is_array($periodSlices)) {
                foreach ($periodSlices as $slice) {
                    if (!is_array($slice)) {
                        continue;
                    }

                    foreach ($slice as $serviceSlice) {
                        if (!is_array($serviceSlice)) {
                            continue;
                        }

                        $uptimeTotal += (int)($serviceSlice['uptime'] ?? 0);
                        $downtimeTotal += (int)($serviceSlice['downtime'] ?? 0);
                    }
                }
            }

            $scheduledTime = $uptimeTotal + $downtimeTotal;
            if ($scheduledTime > 0) {
                $annualAvailabilityPercent = ($uptimeTotal / $scheduledTime) * 100;
                $display = zabbixApiFormatAvailabilityPercent($annualAvailabilityPercent);
            }
        } catch (Throwable $e) {
            $lowerMessage = strtolower($e->getMessage());
            $errorCode = zabbixApiExtractErrorCode($e)
                ?? (str_contains($lowerMessage, 'config') || str_contains($lowerMessage, 'sla non définie') ? 'CONFIG' : 'ZBX');
        }

        return [
            'display' => $display,
            'error_code' => $errorCode,
        ];
    }
}
