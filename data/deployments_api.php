<?php
// ══════════════════════════════════════════════════════════════════════════════
//  data/deployments_api.php
//  Proxy entre le front (include/menu.php) et le webhook n8n qui stocke les
//  NOMS D'AFFICHAGE des déploiements (renommage clic droit « Mes services »).
//
//  Même contrat que data/domains_api.php :
//    • réponse TOUJOURS en JSON : { ok: bool, error?: string, deployments?: [], row?: {} }
//    • lectures  → GET  ?action=list
//    • écritures → POST ?action=rename  (champs: deployment_name, display_name)
//                  protégées par le jeton CSRF (en-tête X-CSRF-Token).
//
//  ⚠️ À AJUSTER selon votre infra n8n (URL du webhook + éventuel secret).
//     Si vous avez déjà un webhook unique pour le portail, vous pouvez pointer
//     N8N_WEBHOOK ci-dessous dessus : le champ « action » est transmis tel quel,
//     il suffit d'aiguiller côté n8n (nœud Switch sur {{ $json.action }}).
// ══════════════════════════════════════════════════════════════════════════════

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Configuration ────────────────────────────────────────────────────────────
//  URL du webhook n8n qui lit/écrit la table des renommages de déploiements.
//  Table conseillée (miroir de domain_buy_name) : deployment_rename
//    - deployment_name : nom technique Kubernetes (clé)
//    - display_name    : nom d'affichage saisi par l'utilisateur ('' = réinitialise)
const N8N_WEBHOOK = 'https://api.gnl-solution.fr/webhook/deployment-rename'; // ⚠️ placeholder
//  Secret partagé optionnel envoyé au webhook (laisser '' si non utilisé).
const N8N_SECRET  = ''; // ⚠️ à renseigner si votre webhook attend un en-tête d'auth

// ── Helpers ──────────────────────────────────────────────────────────────────
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): void
{
    respond(['ok' => false, 'error' => $message], $status);
}

/**
 * Relaie une requête au webhook n8n et renvoie le tableau JSON décodé.
 * Lève une Exception en cas d'échec réseau / réponse non-JSON.
 */
function callN8n(array $params): array
{
    $headers = ['Content-Type: application/json'];
    if (N8N_SECRET !== '') {
        $headers[] = 'X-Portail-Secret: ' . N8N_SECRET;
    }

    $ch = curl_init(N8N_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        throw new RuntimeException('Webhook n8n injoignable : ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Webhook n8n HTTP ' . $code);
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        // n8n peut renvoyer un corps vide sur succès : on le tolère.
        if (trim((string)$raw) === '') {
            return [];
        }
        throw new RuntimeException('Réponse n8n non-JSON.');
    }
    return $data;
}

// ── Routage ──────────────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($action === 'list') {
        // Lecture : pas de CSRF (GET idempotent).
        $data = callN8n(['action' => 'list']);

        // n8n peut renvoyer soit un tableau direct, soit { deployments: [...] }.
        $rows = $data;
        if (isset($data['deployments']) && is_array($data['deployments'])) {
            $rows = $data['deployments'];
        }

        $deployments = [];
        foreach ((array)$rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = trim((string)($r['deployment_name'] ?? $r['name'] ?? ''));
            $disp = trim((string)($r['display_name'] ?? $r['label'] ?? ''));
            if ($name === '') {
                continue;
            }
            $deployments[] = [
                'deployment_name' => $name,
                'display_name'    => $disp,
            ];
        }

        respond(['ok' => true, 'deployments' => $deployments]);
    }

    if ($action === 'rename') {
        if ($method !== 'POST') {
            fail('Méthode non autorisée.', 405);
        }

        // Protection CSRF : l'en-tête doit correspondre au jeton de session.
        $sent     = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string)($_SESSION['csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $sent)) {
            fail('Jeton CSRF invalide.', 403);
        }

        $deploymentName = trim((string)($_POST['deployment_name'] ?? ''));
        $displayName    = trim((string)($_POST['display_name'] ?? ''));
        if ($deploymentName === '') {
            fail('deployment_name manquant.');
        }

        $data = callN8n([
            'action'          => 'rename',
            'deployment_name' => $deploymentName,
            'display_name'    => $displayName, // '' ⇒ réinitialise au nom technique
        ]);

        $row = (isset($data['row']) && is_array($data['row']))
            ? $data['row']
            : ['deployment_name' => $deploymentName, 'display_name' => $displayName];

        respond(['ok' => true, 'row' => $row]);
    }

    fail('Action inconnue : ' . $action, 404);
} catch (Throwable $e) {
    fail($e->getMessage(), 502);
}
