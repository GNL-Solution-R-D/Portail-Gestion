<?php

/**
 * data/n8n_invoice_download.php
 *
 * Proxy AUTHENTIFIÉ de téléchargement du PDF d'une facture (remplaçant n8n de
 * l'ancien data/dolbar_invoice_download.php).
 *
 *   - le client_id provient de la session (non falsifiable) ;
 *   - on demande le PDF au webhook n8n (action=download), jamais exposé au client ;
 *   - tolérant au format de réponse n8n :
 *       • réponse binaire (Content-Type application/pdf / octet-stream, ou body « %PDF ») → diffusée telle quelle ;
 *       • JSON { pdf_url | url } → le fichier est récupéré puis diffusé ;
 *       • JSON { pdf_base64 | content | data } (base64) → décodé puis diffusé.
 *
 * Appel : GET ?id=<id>&ref=<ref>
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';

/** Petite sortie d'erreur lisible (pas de PDF à servir). */
function dl_fail(int $status, string $message): void
{
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($status);
    }
    echo $message;
    exit;
}

function getenv_non_empty(string $name): ?string
{
    $v = getenv($name);
    if ($v === false) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

// ── Authentification ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    dl_fail(401, 'Non authentifié.');
}

$clientId = (int)($_SESSION['user']['id'] ?? 0);
if ($clientId <= 0) {
    dl_fail(401, 'Identifiant client introuvable dans la session.');
}

if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
    accountSessionsDestroyPhpSession();
    dl_fail(401, 'Cette session a été déconnectée depuis vos paramètres.');
}
accountSessionsTouchCurrent($pdo, $clientId);

// ── Paramètres ────────────────────────────────────────────────────────────────
$id  = trim((string)($_GET['id'] ?? ''));
$ref = trim((string)($_GET['ref'] ?? ''));
if ($id === '' && $ref === '') {
    dl_fail(400, 'Paramètre « id » ou « ref » requis.');
}

// Nom de fichier propre : <ref>.pdf
$baseName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $ref !== '' ? $ref : ('facture-' . $id));
$baseName = trim((string)$baseName, '_');
if ($baseName === '') {
    $baseName = 'facture';
}
$fileName = $baseName . '.pdf';

// ── Configuration du webhook n8n ─────────────────────────────────────────────
$N8N_DL_URL = getenv_non_empty('N8N_DATA_INVOICE_DOWNLOAD_URL')
    ?? getenv_non_empty('N8N_DATA_INVOICE_URL')
    ?? 'https://api.gnl-solution.fr/webhook/data-invoice';
$N8N_TOKEN  = getenv_non_empty('N8N_WEBHOOK_TOKEN');

/**
 * Requête GET brute (sans décodage JSON) : récupère corps + type de contenu.
 *
 * @return array{status:int, body:string, content_type:string}
 */
function http_get_raw(string $url, ?string $token, int $timeout = 20): array
{
    $headers = ['Accept: application/pdf, application/octet-stream, application/json;q=0.5, */*'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-GNL-Token: ' . $token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException('Connexion n8n impossible : ' . $err);
        }
        return ['status' => $status, 'body' => (string)$body, 'content_type' => $ctype];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers),
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'follow_location' => 1,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Connexion n8n impossible.');
    }
    $status = 0;
    $ctype  = '';
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $ctype = trim(substr($h, strlen('Content-Type:')));
        }
    }
    return ['status' => (int)$status, 'body' => (string)$body, 'content_type' => $ctype];
}

/** Diffuse des octets PDF au navigateur. */
function stream_pdf(string $bytes, string $fileName): void
{
    if ($bytes === '') {
        dl_fail(502, 'Fichier PDF vide.');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

try {
    $sep = (strpos($N8N_DL_URL, '?') === false) ? '?' : '&';
    $url = $N8N_DL_URL . $sep . http_build_query([
        'action'    => 'download',
        'client_id' => $clientId,
        'id'        => $id,
        'ref'       => $ref,
    ]);

    $resp = http_get_raw($url, $N8N_TOKEN);

    if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
        dl_fail($resp['status'], 'n8n a renvoyé HTTP ' . $resp['status']);
    }

    $body  = $resp['body'];
    $ctype = strtolower($resp['content_type']);

    // 1) Réponse binaire directe (PDF / octet-stream / magic %PDF).
    if (strpos($ctype, 'application/pdf') !== false
        || strpos($ctype, 'octet-stream') !== false
        || strncmp($body, '%PDF', 4) === 0) {
        stream_pdf($body, $fileName);
    }

    // 2) Réponse JSON : URL distante ou base64.
    $json = json_decode($body, true);
    if (is_array($json)) {
        // Déballe l'éventuel item n8n { json: {...} } ou tableau.
        if (isset($json[0]) && is_array($json[0])) {
            $json = $json[0];
        }
        if (isset($json['json']) && is_array($json['json'])) {
            $json = $json['json'];
        }

        $remoteUrl = null;
        foreach (['pdf_url', 'url', 'download_url', 'href'] as $k) {
            if (!empty($json[$k]) && is_string($json[$k])) {
                $remoteUrl = trim($json[$k]);
                break;
            }
        }
        if ($remoteUrl !== null && preg_match('#^https?://#i', $remoteUrl)) {
            $file = http_get_raw($remoteUrl, $N8N_TOKEN, 30);
            if ($file['status'] !== 0 && ($file['status'] < 200 || $file['status'] >= 300)) {
                dl_fail($file['status'], 'Téléchargement du PDF impossible (HTTP ' . $file['status'] . ').');
            }
            stream_pdf($file['body'], $fileName);
        }

        foreach (['pdf_base64', 'base64', 'content', 'data', 'file'] as $k) {
            if (!empty($json[$k]) && is_string($json[$k])) {
                $b64 = $json[$k];
                // Tolère un préfixe data:URI.
                if (preg_match('#^data:[^,]+,(.*)$#s', $b64, $m)) {
                    $b64 = $m[1];
                }
                $decoded = base64_decode($b64, true);
                if ($decoded !== false && $decoded !== '') {
                    stream_pdf($decoded, $fileName);
                }
            }
        }
    }

    dl_fail(404, 'PDF indisponible pour cette facture.');
} catch (Throwable $e) {
    dl_fail(502, $e->getMessage());
}
