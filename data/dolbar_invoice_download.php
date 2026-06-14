<?php
session_start();

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    http_response_code(401);
    exit('Authentification requise.');
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';
require_once '../data/dolbar_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    http_response_code(401);
    exit('Session expirée.');
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

$invoiceId = (int)($_GET['id'] ?? 0);
$invoiceRef = trim((string)($_GET['ref'] ?? 'facture'));
$docPath = trim((string)($_GET['doc'] ?? ''));

if ($docPath === '') {
    http_response_code(400);
    exit('Document introuvable.');
}

if (!dolbarApiIntegrationEnabled()) {
    http_response_code(503);
    exit('Téléchargement Dolibarr désactivé.');
}

function invoiceDownloadBuildAuthHeader(array $userContext): array
{
    $sessionToken = dolbarApiResolveSessionToken($_SESSION);
    if ($sessionToken !== '') {
        return ['DOLAPIKEY: ' . $sessionToken];
    }

    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $userContext);
    if ($apiKey !== null && trim($apiKey) !== '') {
        return ['DOLAPIKEY: ' . trim($apiKey)];
    }

    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $userContext);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $userContext);

    if ($login !== null && $password !== null) {
        $baseApiUrl = dolbarApiNormalizeBaseUrl((string)dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $userContext));
        $token = dolbarApiLoginToken($baseApiUrl, $login, $password, 8);
        return ['DOLAPIKEY: ' . $token];
    }

    throw new RuntimeException('Configuration Dolibarr incomplète pour le téléchargement.', 0);
}

function invoiceDownloadApiRoot(string $baseApiUrl): string
{
    $normalized = rtrim($baseApiUrl, '/');
    $normalized = preg_replace('#/api/index\.php$#i', '', $normalized);
    $normalized = preg_replace('#/api$#i', '', $normalized);
    return (string)$normalized;
}

/**
 * @return array{modulepart:string,file:string}|null
 */
function invoiceDownloadExtractDocumentDescriptor(string $docPath): ?array
{
    $value = trim($docPath);
    if ($value === '') {
        return null;
    }

    $modulePart = 'facture';
    $filePath = '';

    if (preg_match('#^https?://#i', $value)) {
        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }

        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);

        $moduleCandidate = trim((string)($query['modulepart'] ?? ''));
        $fileCandidate = trim((string)($query['file'] ?? $query['original_file'] ?? ''));

        if ($fileCandidate === '') {
            return null;
        }

        if ($moduleCandidate !== '') {
            $modulePart = $moduleCandidate;
        }
        $filePath = ltrim($fileCandidate, '/');
    } else {
        $normalized = ltrim($value, '/');
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, '=')) {
            $query = [];
            parse_str($normalized, $query);

            $moduleCandidate = trim((string)($query['modulepart'] ?? ''));
            $fileCandidate = trim((string)($query['file'] ?? $query['original_file'] ?? ''));

            if ($fileCandidate === '') {
                return null;
            }

            if ($moduleCandidate !== '') {
                $modulePart = $moduleCandidate;
            }
            $filePath = ltrim($fileCandidate, '/');
        } else {
            $filePath = $normalized;
        }
    }

    if ($filePath === '') {
        return null;
    }

    $safeModulePart = preg_replace('/[^A-Za-z0-9_\-]/', '', $modulePart);
    if (!is_string($safeModulePart) || $safeModulePart === '') {
        $safeModulePart = 'facture';
    }

    return [
        'modulepart' => $safeModulePart,
        'file' => $filePath,
    ];
}

try {
    $userContext = $_SESSION['user'];
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId > 0 && $pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $fullUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($fullUser)) {
            $userContext = array_merge($fullUser, $userContext);
        }
    }

    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $userContext);
    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolibarr absente (URL).', 0);
    }

    $baseApiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $apiRoot = invoiceDownloadApiRoot($baseApiUrl);
    $authHeaders = invoiceDownloadBuildAuthHeader($userContext);

    $descriptor = invoiceDownloadExtractDocumentDescriptor($docPath);
    if ($descriptor === null) {
        throw new RuntimeException('Chemin de document invalide.', 400);
    }

    $modulePart = $descriptor['modulepart'];
    $filePath = $descriptor['file'];

    $candidateUrls = [];
    if (preg_match('#^https?://#i', $docPath)) {
        $candidateUrls[] = $docPath;
    }

    $candidateUrls[] = $apiRoot . '/api/index.php/documents/download?modulepart=' . rawurlencode($modulePart) . '&file=' . rawurlencode($filePath);
    $candidateUrls[] = $apiRoot . '/api/index.php/documents/download?modulepart=' . rawurlencode($modulePart) . '&original_file=' . rawurlencode($filePath);
    $candidateUrls[] = $apiRoot . '/document.php?modulepart=' . rawurlencode($modulePart) . '&file=' . rawurlencode($filePath) . '&entity=1';

    if ($modulePart === 'facture') {
        if ($invoiceRef !== '') {
            $safeRef = preg_replace('/[^A-Za-z0-9_\-.]/', '', $invoiceRef);
            if ($safeRef !== '') {
                $candidateUrls[] = $apiRoot . '/api/index.php/documents/download?modulepart=facture&file=' . rawurlencode($safeRef . '/' . $safeRef . '.pdf');
                $candidateUrls[] = $apiRoot . '/api/index.php/documents/download?modulepart=facture&original_file=' . rawurlencode($safeRef . '/' . $safeRef . '.pdf');
                $candidateUrls[] = $apiRoot . '/document.php?modulepart=facture&file=' . rawurlencode($safeRef . '/' . $safeRef . '.pdf') . '&entity=1';
            }
        }
    }

    $responseBody = null;
    $responseContentType = 'application/pdf';

    foreach ($candidateUrls as $candidateUrl) {
        $ch = curl_init($candidateUrl);
        if ($ch === false) {
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/pdf,application/octet-stream,*/*'], $authHeaders),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FAILONERROR => false,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $body === '' || $httpCode >= 400) {
            continue;
        }

        $responseBody = $body;
        if ($contentType !== '') {
            $responseContentType = $contentType;
        }
        break;
    }

    if ($responseBody === null) {
        throw new RuntimeException('PDF introuvable pour cette facture.', 404);
    }

    $fallbackName = $invoiceRef !== '' ? $invoiceRef : ('facture-' . $invoiceId);
    $filename = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $fallbackName) . '.pdf';

    header('Content-Type: ' . $responseContentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($responseBody));
    echo $responseBody;
    exit();
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }

    http_response_code($code);
    echo 'Téléchargement impossible: ' . $e->getMessage();
}
