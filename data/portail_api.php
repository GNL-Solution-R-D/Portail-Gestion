<?php

/**
 * data/portail_api.php
 *
 * Proxy serveur UNIQUE entre le portail (front) et n8n.
 * Fusionne les anciens endpoints :
 *   domains_api.php · documentation_api.php · abonnements_api.php ·
 *   factures_api.php · equipes_api.php · deployments_api.php · notifications_api.php
 *
 * Principes (identiques aux anciens fichiers) :
 *   - le navigateur appelle CE endpoint, jamais n8n directement ;
 *   - le client_id est injecté ICI depuis la session (non falsifiable) ;
 *   - protection CSRF (header X-CSRF-Token) sur toutes les écritures ;
 *   - réponse toujours normalisée en { ok: true/false, ... }.
 *
 * ── Différences avec les anciens fichiers ─────────────────────────────────────
 *   1) Un SEUL webhook n8n est utilisé pour tout (lecture ET écriture) :
 *          https://api.gnl-solution.fr/webhook/data-portail   (méthode POST)
 *   2) Le champ "action" est PRÉFIXÉ par le module. n8n aiguille via un nœud
 *      Switch sur {{ $json.action }}. Exemples :
 *          "domain.list"          au lieu de "list"
 *          "documentation.search" au lieu de "search"
 *          "invoice.detail"       au lieu de "detail"
 *          "team.update"          au lieu de "update"
 *
 * ── Contrat navigateur → CE endpoint ──────────────────────────────────────────
 *   Lectures  → GET  ?action=<module>.<sous-action>   (pas de CSRF)
 *   Écritures → POST ?action=<module>.<sous-action>   (header X-CSRF-Token)
 *
 * ── Carte des actions ─────────────────────────────────────────────────────────
 *   DOMAINES
 *     domain.list           GET   → { ok, domains:[...] }
 *     domain.records        GET   ?domain=            → { ok, records:[...] }
 *     domain.add_record     POST  CSRF                → { ok, action }
 *     domain.delete_record  POST  CSRF                → { ok, action }
 *     domain.upsert         POST  CSRF                → { ok, action, row? }
 *     domain.verify         POST  CSRF                → { ok, action, row?, verified }
 *     domain.deploy         POST  CSRF                → { ok, action, row? }
 *     domain.delete         POST  CSRF                → { ok, action }
 *   DOCUMENTATION
 *     documentation.list    GET                       → { ok, articles:[...], count }
 *     documentation.search  GET   ?q=                 → { ok, articles:[...], count, query }
 *   ABONNEMENTS
 *     subscription.list     GET                       → { ok, count, subscriptions:[...] }
 *     subscription.detail   GET   ?id= | ?ref=        → { ok, count, subscriptions:[...] }
 *   FACTURES
 *     invoice.list          GET                       → { ok, count, invoices:[...] }
 *     invoice.detail        GET   ?id= | ?ref=        → { ok, count, invoices:[...] }
 *   COMMANDES
 *     order.list            GET                       → { ok, count, orders:[...] }
 *     order.detail          GET   ?id= | ?ref=        → { ok, count, orders:[...] }
 *   ÉQUIPES
 *     team.list             GET                       → { ok, count, members:[...], structure, can_edit }
 *     team.ensure           POST  CSRF                → { ok, message, row? }   (provisionne la ligne « team » du client courant)
 *     team.update           POST  CSRF + droits       → { ok, message }
 *   DÉPLOIEMENTS (renommage « Mes services »)
 *     deployment.list       GET                       → { ok, deployments:[...] }
 *     deployment.rename     POST  CSRF                → { ok, row }
 *   NOTIFICATIONS (cloche)
 *     notification.list     GET   ?limit=             → { ok, notifications:[...], unread:N }
 *     notification.read     POST  CSRF  id=… | all=1  → { ok }
 *   STATISTIQUES (cartes + graphique du dashboard)
 *     stats.dashboard       GET                       → { ok, current_month_hits, previous_month_hits, by_month:{...}, by_deployment:{...} }
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('html_errors', '0');
@ini_set('display_startup_errors', '0');

ob_start();

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!$lastError || !in_array($lastError['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        http_response_code(500);
    }
    echo json_encode([
        'ok'     => false,
        'error'  => 'Erreur serveur PHP',
        'detail' => (string)($lastError['message'] ?? 'Erreur fatale'),
    ], JSON_UNESCAPED_SLASHES);
});

// Cookie de session valable sur /pages/* ET /data/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';
require_once __DIR__ . '/../include/portail_api_client.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ══════════════════════════════════════════════════════════════════════════════
//  Configuration : UN SEUL webhook n8n, toujours en POST.
// ══════════════════════════════════════════════════════════════════════════════
const N8N_PORTAIL_URL = 'https://api.gnl-solution.fr/webhook/data-portail';

// ══════════════════════════════════════════════════════════════════════════════
//  Helpers communs
// ══════════════════════════════════════════════════════════════════════════════

function send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Variable d'environnement uniquement si définie ET non vide après trim. */
function getenv_non_empty(string $name): ?string
{
    $v = getenv($name);
    if ($v === false) {
        return null;
    }
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/** Vérifie le jeton CSRF (header X-CSRF-Token vs session). */
function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($sess) || $sess === '' || !is_string($sent) || !hash_equals($sess, $sent)) {
        send_json(403, ['ok' => false, 'error' => 'Jeton CSRF invalide.']);
    }
}

/** Exige la méthode POST pour les écritures. */
function require_post(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Méthode non autorisée (POST requis).']);
    }
}

/**
 * Relaie un payload au webhook n8n UNIQUE, toujours en POST JSON,
 * et renvoie la réponse décodée.
 *
 * @return array{status:int, json:mixed, raw:string}
 */
function n8n_call(array $payload): array
{
    // Transport centralisé dans include/portail_api_client.php afin d'être
    // réutilisable hors de ce proxy (ex. keycloak_callback.php → team.ensure).
    // Forme de retour identique : { status, json, raw }.
    return portailApiCall($payload);
}

/** Échec si n8n renvoie un code HTTP hors plage 2xx. */
function ensure_ok(array $resp): void
{
    if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
        $detail = is_array($resp['json']) ? (string)($resp['json']['error'] ?? '') : '';
        send_json($resp['status'] ?: 502, [
            'ok'    => false,
            'error' => 'n8n a renvoyé HTTP ' . $resp['status'] . ($detail !== '' ? ' — ' . $detail : ''),
        ]);
    }
}

/**
 * Extrait une liste de lignes depuis une réponse n8n tolérante au format.
 *
 * @param array  $containerKeys clés de conteneur spécifiques au module
 * @param array  $idKeys        clés qui identifient un objet « ligne » unique
 */
function extract_rows($json, array $containerKeys = [], array $idKeys = ['id']): array
{
    // Déballe le format d'item n8n { "json": {...} } → {...}
    $unwrap = static function ($v) {
        return (is_array($v) && isset($v['json']) && is_array($v['json'])) ? $v['json'] : $v;
    };

    $containerKeys = array_merge($containerKeys, ['data', 'results', 'rows', 'items']);

    if (is_array($json)) {
        foreach ($containerKeys as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $json = $json[$key];
                break;
            }
        }
        // Tableau brut de lignes (clé numérique 0 présente, ou tableau vide).
        if ($json === [] || array_key_exists(0, $json)) {
            return array_map($unwrap, array_values($json));
        }
        // Item n8n unique { "json": {...} }.
        if (isset($json['json']) && is_array($json['json'])) {
            return [$json['json']];
        }
        // Objet unique ressemblant à une ligne.
        foreach ($idKeys as $k) {
            if (isset($json[$k])) {
                return [$json];
            }
        }
    }
    return [];
}

/** true/false depuis une valeur n8n hétérogène (bool, 0/1, "true"). */
function truthy($v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'oui', 'on'], true);
}

/** Première valeur non vide parmi plusieurs clés candidates. */
function pick(array $row, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && trim((string)$row[$k]) !== '') {
            return $row[$k];
        }
    }
    return $default;
}

/** Date hétérogène (timestamp unix ou chaîne ISO) → timestamp. */
function to_timestamp($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $ts = strtotime((string)$value);
    return $ts === false ? null : $ts;
}

function date_display(?int $ts): string
{
    return ($ts === null || $ts <= 0) ? '—' : date('d/m/Y', $ts);
}

function amount_display($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ' €';
}

// Chaînes multioctets (repli si mbstring absent) ──────────────────────────────
function s_lower($v): string
{
    $v = (string)$v;
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}
function s_upper($v): string
{
    $v = (string)$v;
    return function_exists('mb_strtoupper') ? mb_strtoupper($v, 'UTF-8') : strtoupper($v);
}
function s_sub($v, int $start, ?int $len = null): string
{
    $v = (string)$v;
    if (function_exists('mb_substr')) {
        return $len === null ? mb_substr($v, $start, null, 'UTF-8') : mb_substr($v, $start, $len, 'UTF-8');
    }
    return $len === null ? substr($v, $start) : substr($v, $start, $len);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Validation — DOMAINES
// ══════════════════════════════════════════════════════════════════════════════

/** Label DNS simple (un segment) : déploiement, etc. */
function is_dns_label(string $v): bool
{
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $v);
}

/** Nom de domaine complet (FQDN) : labels + TLD ≥ 2. */
function is_domain_name(string $v): bool
{
    $v = rtrim(strtolower(trim($v)), '.');
    if ($v === '' || strlen($v) > 253) {
        return false;
    }
    return (bool)preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $v);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — DOCUMENTATION
// ══════════════════════════════════════════════════════════════════════════════

function documentationFirstValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = $row[$key];
        if (is_string($value) || is_numeric($value)) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return '';
}

function documentationPlainText(string $value): string
{
    $decoded    = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stripped   = strip_tags($decoded);
    $normalized = preg_replace('/\s+/u', ' ', $stripped);
    return trim((string) $normalized);
}

function documentationHtmlToDisplay(string $value): string
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $allowed = '<p><br><ul><ol><li><strong><b><em><i><u><a><code><pre><blockquote>';
    $safe    = strip_tags($decoded, $allowed);
    return trim($safe);
}

/** Transforme une date n8n (timestamp s/ms, ISO 8601…) en « d/m/Y ». */
function documentationDate(array $row): string
{
    $keys = [
        'date_modification', 'date_update', 'updatedAt', 'updated_at', 'tms',
        'date_creation', 'createdAt', 'created_at', 'datec', 'date',
    ];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = $row[$key];
        if ($value === null || $value === '' || $value === false) {
            continue;
        }
        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts > 100000000000) { // millisecondes → secondes
                $ts = (int) ($ts / 1000);
            }
            if ($ts > 0) {
                return date('d/m/Y', $ts);
            }
            continue;
        }
        $ts = strtotime((string) $value);
        if ($ts !== false) {
            return date('d/m/Y', $ts);
        }
    }
    return '—';
}

/** Mappe une ligne n8n vers la structure d'article attendue par la page. */
function documentationNormalize(array $row): array
{
    $id       = (int) documentationFirstValue($row, ['id', 'rowid']);
    $title    = documentationFirstValue($row, ['title', 'question', 'label', 'name', 'ref', 'subject']);
    $category = documentationFirstValue($row, ['category', 'category_label', 'type_label', 'type', 'tag', 'section']);

    $summaryRaw = documentationFirstValue($row, ['summary', 'question', 'description', 'excerpt', 'note_public', 'note']);
    $contentRaw = documentationFirstValue($row, ['content', 'answer', 'description', 'body', 'html', 'note_public', 'note', 'text']);

    $summary     = documentationPlainText($summaryRaw);
    $content     = documentationPlainText($contentRaw);
    $contentHtml = documentationHtmlToDisplay($contentRaw);

    if ($summary === '' && $content !== '') {
        $summary = mb_substr($content, 0, 180);
        if (mb_strlen($content) > 180) {
            $summary .= '…';
        }
    }

    return [
        'id'           => $id,
        'title'        => $title !== '' ? $title : 'Article sans titre',
        'category'     => $category !== '' ? $category : 'Général',
        'summary'      => $summary,
        'content'      => $content,
        'content_html' => $contentHtml,
        'updated_at'   => documentationDate($row),
    ];
}

/** strpos insensible à la casse et compatible UTF-8. */
function documentationContains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — ABONNEMENTS
// ══════════════════════════════════════════════════════════════════════════════

function subscription_frequency_display(?int $start, ?int $end): string
{
    if ($start === null || $end === null || $end <= $start) {
        return '—';
    }
    $diff = (new DateTimeImmutable('@' . $start))->diff(new DateTimeImmutable('@' . $end));
    $parts = [];
    if ($diff->y > 0) {
        $parts[] = $diff->y . ' an' . ($diff->y > 1 ? 's' : '');
    }
    if ($diff->m > 0) {
        $parts[] = $diff->m . ' mois';
    }
    if ($diff->d > 0) {
        $parts[] = $diff->d . ' jour' . ($diff->d > 1 ? 's' : '');
    }
    return empty($parts) ? 'Moins d’un jour' : implode(' ', $parts);
}

function subscription_status_label($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '0' => 'Brouillon', '4' => 'En cours', '5' => 'Fermé',
        'draft' => 'Brouillon', 'pending' => 'En attente',
        'open' => 'En cours', 'running' => 'En cours', 'active' => 'En cours',
        'closed' => 'Fermé', 'cancelled' => 'Résilié', 'canceled' => 'Résilié',
        'expired' => 'Expiré', 'suspended' => 'Suspendu',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function subscription_status_class($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['4', 'open', 'running', 'active'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['0', 'draft', 'pending', 'suspended'], true)) {
        return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
    }
    if (in_array($n, ['5', 'closed', 'cancelled', 'canceled', 'expired'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}

function normalize_subscription(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'contract_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference'], 'ABO-' . $id);

    $label = (string)pick(
        $row,
        ['label', 'product_label', 'name', 'description', 'product'],
        '—'
    );

    $startTs = to_timestamp(pick($row, ['date_start', 'date_contrat', 'date_ouverture', 'start', 'date_valid']));
    $endTs   = to_timestamp(pick($row, ['date_end', 'date_fin_validite', 'fin_validite', 'next_payment', 'date_cloture', 'end']));

    $amountRaw = pick($row, ['amount', 'price', 'subprice', 'total_ht', 'total_ttc']);
    $statusRaw = (string)pick($row, ['status', 'statut', 'state'], '');

    $freqExplicit = pick($row, ['frequency', 'periodicity', 'frequence']);
    $frequency = ($freqExplicit !== null && $freqExplicit !== '')
        ? (string)$freqExplicit
        : subscription_frequency_display($startTs, $endTs);

    return [
        'id'           => $id,
        'ref'          => $ref,
        'label'        => $label,
        'start'        => date_display($startTs),
        'start_ts'     => $startTs,
        'end'          => date_display($endTs),
        'end_ts'       => $endTs,
        'frequency'    => $frequency,
        'amount'       => amount_display($amountRaw),
        'amount_raw'   => is_numeric($amountRaw) ? (float)$amountRaw : null,
        'status'       => $statusRaw,
        'status_label' => subscription_status_label($statusRaw),
        'status_class' => subscription_status_class($statusRaw),
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — FACTURES
// ══════════════════════════════════════════════════════════════════════════════

function invoice_status_label($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '0' => 'Brouillon', '1' => 'Validée', '2' => 'Payée', '3' => 'Abandonnée', '4' => 'Classée',
        'draft' => 'Brouillon', 'validated' => 'Validée', 'paid' => 'Payée',
        'abandoned' => 'Abandonnée', 'closed' => 'Classée',
        'cancelled' => 'Annulée', 'canceled' => 'Annulée', 'unpaid' => 'Impayée',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function invoice_status_class($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['2', 'paid', '4', 'closed'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['3', 'abandoned', 'cancelled', 'canceled'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    if (in_array($n, ['1', 'validated'], true)) {
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
}

function normalize_invoice(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'invoice_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference', 'number', 'invoice_number'], 'FAC-' . $id);

    $dateTs = to_timestamp(pick($row, ['date', 'datef', 'date_valid', 'date_creation', 'invoice_date', 'issued_at']));
    $dueTs  = to_timestamp(pick($row, ['due', 'date_lim_reglement', 'date_echeance', 'date_due', 'due_date']));

    $totalHt   = pick($row, ['total_ht', 'amount_ht', 'ht']);
    $totalTtc  = pick($row, ['total_ttc', 'amount_ttc', 'ttc', 'amount', 'total']);
    $remaining = pick($row, ['remaining', 'remaintopay', 'resteapayer', 'remaining_to_pay', 'reste']);

    $statusRaw = (string)pick($row, ['status', 'statut', 'fk_statut', 'state', 'paye'], '');

    // PDF : présence d'un chemin/URL ⇒ téléchargement disponible (via proxy authentifié).
    $hasPdf = pick($row, ['pdf', 'pdf_url', 'download_url', 'last_main_doc', 'main_doc', 'doc', 'url']) !== null;
    $downloadUrl = $hasPdf
        ? '/data/n8n_invoice_download.php?id=' . rawurlencode((string)$id) . '&ref=' . rawurlencode($ref)
        : null;

    return [
        'id'             => $id,
        'ref'            => $ref,
        'date'           => date_display($dateTs),
        'date_ts'        => $dateTs,
        'due'            => date_display($dueTs),
        'due_ts'         => $dueTs,
        'status'         => $statusRaw,
        'status_label'   => invoice_status_label($statusRaw),
        'status_class'   => invoice_status_class($statusRaw),
        'total_ht'       => amount_display($totalHt),
        'total_ht_raw'   => is_numeric($totalHt) ? (float)$totalHt : null,
        'total_ttc'      => amount_display($totalTtc),
        'total_ttc_raw'  => is_numeric($totalTtc) ? (float)$totalTtc : null,
        'remaining'      => amount_display($remaining),
        'remaining_raw'  => is_numeric($remaining) ? (float)$remaining : null,
        'has_pdf'        => $hasPdf,
        'download_url'   => $downloadUrl,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — COMMANDES
// ══════════════════════════════════════════════════════════════════════════════

function order_status_label($status): string
{
    $n = strtolower(trim((string)$status));
    $map = [
        '-1' => 'Annulée', '0' => 'Brouillon', '1' => 'Validée',
        '2' => 'En cours', '3' => 'Livrée',
        'draft' => 'Brouillon', 'validated' => 'Validée',
        'processing' => 'En cours', 'shipped' => 'Expédiée',
        'delivered' => 'Livrée', 'closed' => 'Classée',
        'cancelled' => 'Annulée', 'canceled' => 'Annulée',
    ];
    return $map[$n] ?? ($n !== '' ? ucfirst($n) : 'Inconnu');
}

function order_status_class($status): string
{
    $n = strtolower(trim((string)$status));
    if (in_array($n, ['3', 'delivered', 'closed', 'shipped'], true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, ['-1', 'cancelled', 'canceled'], true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    if (in_array($n, ['1', 'validated'], true)) {
        return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
    }
    return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
}

function normalize_order(array $row): array
{
    $id  = pick($row, ['id', 'rowid', 'order_id'], 0);
    $id  = is_numeric($id) ? (int)$id : 0;
    $ref = (string)pick($row, ['ref', 'reference', 'number', 'order_number'], 'CMD-' . $id);

    $dateTs = to_timestamp(pick($row, ['date', 'datef', 'date_commande', 'date_creation', 'order_date', 'created_at']));

    $totalHt  = pick($row, ['total_ht', 'amount_ht', 'ht']);
    $totalTtc = pick($row, ['total_ttc', 'amount_ttc', 'ttc', 'amount', 'total']);
    $statusRaw = (string)pick($row, ['status', 'statut', 'fk_statut', 'state'], '');

    return [
        'id'            => $id,
        'ref'           => $ref,
        'date'          => date_display($dateTs),
        'date_ts'       => $dateTs,
        'status'        => $statusRaw,
        'status_label'  => order_status_label($statusRaw),
        'status_class'  => order_status_class($statusRaw),
        'total_ht'      => amount_display($totalHt),
        'total_ht_raw'  => is_numeric($totalHt) ? (float)$totalHt : null,
        'total_ttc'     => amount_display($totalTtc),
        'total_ttc_raw' => is_numeric($totalTtc) ? (float)$totalTtc : null,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — ÉQUIPES
// ══════════════════════════════════════════════════════════════════════════════

function permission_label($permId): string
{
    $id = max(0, min(255, (int)$permId));
    $labels = [
        0 => 'Accès complet',
        1 => 'Signataire/Représentant',
        2 => 'Accès financier',
        3 => 'Accès trésorerie',
        4 => 'Accès technique',
        5 => 'Lecture seule',
        6 => 'Invité',
    ];
    return $labels[$id] ?? 'Profil non défini';
}

/** [label, active] : Actif/Inactif/texte libre + drapeau actif 0/1. */
function member_status(array $m): array
{
    $raw = $m['statut'] ?? $m['status'] ?? null;
    if ($raw === null || trim((string)$raw) === '') {
        if (isset($m['active'])) {
            $active = ((int)(is_bool($m['active']) ? ($m['active'] ? 1 : 0) : $m['active']) === 1);
            return [$active ? 'Actif' : 'Inactif', $active ? 1 : 0];
        }
        return ['Actif', 1];
    }
    if (is_numeric($raw)) {
        $active = ((int)$raw === 1);
        return [$active ? 'Actif' : 'Inactif', $active ? 1 : 0];
    }
    $label  = (string)$raw;
    $active = in_array(s_lower(trim($label)), ['actif', 'active', 'on', 'enabled', 'ok', 'en poste', 'disponible'], true) ? 1 : 0;
    return [$label, $active];
}

function team_status_class(string $status): string
{
    $n = s_lower(trim($status));
    $positive = ['actif', 'active', 'online', 'enabled', 'ok', 'en poste', 'disponible'];
    $negative = ['inactif', 'inactive', 'offline', 'disabled', 'bloqué', 'suspendu'];
    if (in_array($n, $positive, true)) {
        return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    }
    if (in_array($n, $negative, true)) {
        return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    }
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
}

function member_name(array $m, int $id): string
{
    $parts = [];
    foreach (['civilite' => ['civilite', 'civility', 'civility_code'], 'prenom' => ['prenom', 'firstname', 'first_name'], 'nom' => ['nom', 'lastname', 'last_name']] as $cands) {
        $v = pick($m, $cands);
        if ($v !== null) {
            $parts[] = trim((string)$v);
        }
    }
    if (!empty($parts)) {
        return implode(' ', $parts);
    }
    $u = pick($m, ['username', 'login', 'email']);
    if ($u !== null) {
        return trim((string)$u);
    }
    return 'Utilisateur #' . $id;
}

function member_secondary(array $m): string
{
    $v = pick($m, ['email', 'username', 'login']);
    return $v !== null ? trim((string)$v) : 'Compte interne';
}

function member_initials(array $m): string
{
    $fn = trim((string)(pick($m, ['prenom', 'firstname', 'first_name']) ?? ''));
    $ln = trim((string)(pick($m, ['nom', 'lastname', 'last_name']) ?? ''));
    $i  = s_upper(($fn !== '' ? s_sub($fn, 0, 1) : '') . ($ln !== '' ? s_sub($ln, 0, 1) : ''));
    if ($i !== '') {
        return $i;
    }
    $u = trim((string)(pick($m, ['username', 'login', 'email']) ?? ''));
    if ($u !== '') {
        return s_upper(s_sub($u, 0, 2));
    }
    return '#';
}

function normalize_member(array $row): array
{
    $id = (int)(pick($row, ['id', 'rowid', 'contact_id']) ?? 0);
    [$statusLabel, $active] = member_status($row);
    $permId   = (int)(pick($row, ['perm_id', 'permission', 'role_id']) ?? 6);
    $function = trim((string)(pick($row, ['fonction', 'poste', 'job', 'function']) ?? ''));
    $email    = trim((string)(pick($row, ['email']) ?? ''));

    return [
        'id'           => $id,
        'name'         => member_name($row, $id),
        'secondary'    => member_secondary($row),
        'initials'     => member_initials($row),
        'function'     => $function !== '' ? $function : 'Aucune fonction définie',
        'fonction'     => $function,            // brut, pour le formulaire d'édition
        'email'        => $email,               // brut, pour le formulaire d'édition
        'status_label' => $statusLabel,
        'status_class' => team_status_class($statusLabel),
        'active'       => $active,
        'perm_id'      => $permId,
        'permission'   => permission_label($permId),
        'structure'    => trim((string)(pick($row, ['structure', 'company', 'socname', 'raison']) ?? '')),
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — DÉPLOIEMENTS
// ══════════════════════════════════════════════════════════════════════════════

function normalize_deployment(array $r): ?array
{
    $name = trim((string)($r['deployment_name'] ?? $r['name'] ?? ''));
    $disp = trim((string)($r['display_name'] ?? $r['label'] ?? ''));
    if ($name === '') {
        return null;
    }
    return ['deployment_name' => $name, 'display_name' => $disp];
}

// ══════════════════════════════════════════════════════════════════════════════
//  Normalisation — NOTIFICATIONS
// ══════════════════════════════════════════════════════════════════════════════

/** Une notification est-elle non lue ? (tolérant aux schémas n8n) */
function notif_is_unread(array $r): bool
{
    // Champs « horodatage de lecture » : présence (non vide) ⇒ lue.
    foreach (['read_at', 'date_lecture', 'seen_at'] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null && trim((string)$r[$k]) !== '') {
            return false;
        }
    }
    // Drapeaux booléens : truthy ⇒ lue.
    foreach (['read', 'is_read', 'lu', 'seen', 'vue'] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null && $r[$k] !== '') {
            return !truthy($r[$k]);
        }
    }
    // Drapeaux « non lu » explicites : truthy ⇒ non lue.
    foreach (['unread', 'non_lu'] as $k) {
        if (array_key_exists($k, $r)) {
            return truthy($r[$k]);
        }
    }
    return true; // par défaut : considérée non lue
}

// ══════════════════════════════════════════════════════════════════════════════
//  Authentification (commune à tous les modules)
// ══════════════════════════════════════════════════════════════════════════════
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    send_json(401, ['ok' => false, 'error' => 'Non authentifié (cookie de session absent ?).']);
}

$user     = $_SESSION['user'];
$clientId = (int)($user['id'] ?? 0);
if ($clientId <= 0) {
    send_json(401, ['ok' => false, 'error' => 'Identifiant client introuvable dans la session.']);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, $clientId)) {
    accountSessionsDestroyPhpSession();
    send_json(401, ['ok' => false, 'error' => 'Cette session a été déconnectée depuis vos paramètres.']);
}
accountSessionsTouchCurrent($pdo, $clientId);

// Contexte « équipes » (droits calculés serveur, non falsifiables)
$currentSiret  = trim((string)($user['siret'] ?? ''));
$currentPermId = (int)($user['perm_id'] ?? 255);
$canEdit       = ($currentSiret !== '' && in_array($currentPermId, [0, 1, 2, 3, 4], true));
$sessionStructure = trim((string)(
    $user['raison']
    ?? $user['organization_name']
    ?? $user['organization']
    ?? $user['nom_commercial']
    ?? ''
));

// ══════════════════════════════════════════════════════════════════════════════
//  Routage : action = "<module>.<sous-action>"
// ══════════════════════════════════════════════════════════════════════════════
$action = (string)($_REQUEST['action'] ?? '');

try {
    switch ($action) {

        // ─────────────────────────────────────────────────────────────────────
        //  DOMAINES
        // ─────────────────────────────────────────────────────────────────────
        case 'domain.list': {
            $resp = n8n_call(['action' => 'domain.list', 'client_id' => $clientId]);
            ensure_ok($resp);
            send_json(200, [
                'ok'      => true,
                'domains' => extract_rows($resp['json'], ['domains', 'records'], ['id', 'domain_buy_name']),
            ]);
        }

        case 'domain.records': {
            $domain = rtrim(strtolower(trim((string)($_GET['domain'] ?? ''))), '.');
            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }
            $resp = n8n_call(['action' => 'domain.records', 'client_id' => $clientId, 'domain' => $domain]);
            ensure_ok($resp);
            send_json(200, [
                'ok'      => true,
                'records' => extract_rows($resp['json'], ['records', 'domains'], ['id', 'domain_buy_name']),
            ]);
        }

        case 'domain.add_record':
        case 'domain.delete_record': {
            require_post();
            csrf_check();

            $domain = rtrim(strtolower(trim((string)($_POST['domain'] ?? ''))), '.');
            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }

            $payload = ['action' => $action, 'client_id' => $clientId, 'domain' => $domain];

            if ($action === 'domain.add_record') {
                $type    = strtoupper(trim((string)($_POST['type'] ?? '')));
                $name    = trim((string)($_POST['name'] ?? ''));
                $content = trim((string)($_POST['content'] ?? ''));
                $ttl     = (int)($_POST['ttl'] ?? 3600);
                $allowedTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

                if (!in_array($type, $allowedTypes, true)) {
                    send_json(400, ['ok' => false, 'error' => 'Type d\'enregistrement non supporté.']);
                }
                if ($content === '') {
                    send_json(400, ['ok' => false, 'error' => 'La valeur de l\'enregistrement est requise.']);
                }
                if ($ttl < 60) {
                    $ttl = 60;
                }
                $payload['type']    = $type;
                $payload['name']    = ($name === '') ? '@' : $name;
                $payload['content'] = $content;
                $payload['ttl']     = $ttl;
            } else { // domain.delete_record
                $recordId = trim((string)($_POST['id'] ?? ''));
                if ($recordId === '') {
                    send_json(400, ['ok' => false, 'error' => 'Identifiant d\'enregistrement manquant.']);
                }
                $payload['id'] = $recordId;
            }

            $resp = n8n_call($payload);
            ensure_ok($resp);
            send_json(200, ['ok' => true, 'action' => $action]);
        }

        case 'domain.upsert':
        case 'domain.verify':
        case 'domain.deploy': {
            require_post();
            csrf_check();

            $domain   = rtrim(strtolower(trim((string)($_POST['domain_buy_name'] ?? ''))), '.');
            $gnl      = truthy($_POST['gnl_domain'] ?? '0');
            $nsGnl    = truthy($_POST['ns_gnl'] ?? '0');
            $linkedTo = trim((string)($_POST['linked_to'] ?? ''));

            if (!is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de domaine invalide.']);
            }
            if ($linkedTo !== '' && !is_dns_label($linkedTo)) {
                send_json(400, ['ok' => false, 'error' => 'Déploiement cible invalide.']);
            }
            if ($action === 'domain.deploy' && $linkedTo === '') {
                send_json(400, ['ok' => false, 'error' => 'Un déploiement cible est requis.']);
            }

            $resp = n8n_call([
                'action'          => $action,
                'client_id'       => $clientId,
                'domain_buy_name' => $domain,
                'linked_to'       => $linkedTo,
                'gnl_domain'      => $gnl,
                'ns_gnl'          => $nsGnl,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['domains', 'records'], ['id', 'domain_buy_name']);
            $row  = $rows[0] ?? null;

            $out = ['ok' => true, 'action' => $action];
            if ($row !== null) {
                $out['row'] = $row;
            }
            if ($action === 'domain.verify') {
                $verified = false;
                if (is_array($row) && array_key_exists('verified', $row)) {
                    $verified = truthy($row['verified']);
                } elseif (is_array($resp['json']) && array_key_exists('verified', $resp['json'])) {
                    $verified = truthy($resp['json']['verified']);
                }
                $out['verified'] = $verified;
            }
            send_json(200, $out);
        }

        case 'domain.delete': {
            require_post();
            csrf_check();

            $domain = rtrim(strtolower(trim((string)($_POST['domain_buy_name'] ?? ''))), '.');
            $id     = trim((string)($_POST['id'] ?? ''));

            if ($id === '' && !is_domain_name($domain)) {
                send_json(400, ['ok' => false, 'error' => 'Domaine ou identifiant requis pour la suppression.']);
            }

            $resp = n8n_call([
                'action'          => 'domain.delete',
                'client_id'       => $clientId,
                'domain_buy_name' => $domain,
                'id'              => $id !== '' ? $id : null,
            ]);
            ensure_ok($resp);
            send_json(200, ['ok' => true, 'action' => 'domain.delete']);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  DOCUMENTATION
        // ─────────────────────────────────────────────────────────────────────
        case 'documentation.list':
        case 'documentation.search': {
            $query = ($action === 'documentation.search') ? trim((string)($_GET['q'] ?? '')) : '';

            // On demande toujours « documentation.list » à n8n ; le filtrage de
            // garantie se fait ICI. Le champ q est transmis au cas où.
            $payload = ['action' => 'documentation.list', 'client_id' => $clientId];
            if ($query !== '') {
                $payload['q'] = $query;
            }

            $resp = n8n_call($payload);
            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], [
                    'ok'    => false,
                    'error' => 'n8n a renvoyé HTTP ' . $resp['status'],
                    'code'  => 'N8N',
                ]);
            }

            $rows = extract_rows($resp['json'], ['articles', 'documents', 'records', 'knowledgebase'], ['id', 'title', 'question']);

            $articles = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $articles[] = documentationNormalize($row);
                }
            }

            usort(
                $articles,
                static fn(array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title'])
            );

            if ($query !== '') {
                $articles = array_values(array_filter(
                    $articles,
                    static function (array $a) use ($query): bool {
                        $haystack = $a['title'] . ' ' . $a['category'] . ' ' . $a['summary'] . ' ' . $a['content'];
                        return documentationContains((string) $haystack, $query);
                    }
                ));
            }

            $out = ['ok' => true, 'articles' => $articles, 'count' => count($articles)];
            if ($action === 'documentation.search') {
                $out['query'] = $query;
            }
            send_json(200, $out);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  ABONNEMENTS
        // ─────────────────────────────────────────────────────────────────────
        case 'subscription.list': {
            $resp = n8n_call(['action' => 'subscription.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['subscriptions', 'abonnements', 'contracts'], ['id', 'ref', 'reference']);
            $subscriptions = array_map('normalize_subscription', $rows);

            send_json(200, [
                'ok'            => true,
                'count'         => count($subscriptions),
                'subscriptions' => $subscriptions,
            ]);
        }

        case 'subscription.detail': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $resp = n8n_call([
                'action'    => 'subscription.detail',
                'client_id' => $clientId,
                'id'        => $id,
                'ref'       => $ref,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['subscriptions', 'abonnements', 'contracts'], ['id', 'ref', 'reference']);

            if (($id !== '' || $ref !== '') && count($rows) > 1) {
                $rows = array_values(array_filter($rows, static function ($r) use ($id, $ref): bool {
                    if (!is_array($r)) {
                        return false;
                    }
                    $rId  = (string)($r['id'] ?? $r['rowid'] ?? '');
                    $rRef = (string)($r['ref'] ?? $r['reference'] ?? '');
                    return ($id !== '' && $rId === $id) || ($ref !== '' && $rRef === $ref);
                }));
            }

            if (empty($rows)) {
                send_json(404, ['ok' => false, 'error' => 'Abonnement introuvable.']);
            }

            $subscriptions = array_map('normalize_subscription', $rows);
            send_json(200, [
                'ok'            => true,
                'count'         => count($subscriptions),
                'subscriptions' => $subscriptions,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  FACTURES
        // ─────────────────────────────────────────────────────────────────────
        case 'invoice.list': {
            $resp = n8n_call(['action' => 'invoice.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['invoices', 'factures'], ['id', 'ref', 'reference']);
            $invoices = array_map('normalize_invoice', $rows);

            send_json(200, [
                'ok'       => true,
                'count'    => count($invoices),
                'invoices' => $invoices,
            ]);
        }

        case 'invoice.detail': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $resp = n8n_call([
                'action'    => 'invoice.detail',
                'client_id' => $clientId,
                'id'        => $id,
                'ref'       => $ref,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['invoices', 'factures'], ['id', 'ref', 'reference']);

            if (($id !== '' || $ref !== '') && count($rows) > 1) {
                $rows = array_values(array_filter($rows, static function ($r) use ($id, $ref): bool {
                    if (!is_array($r)) {
                        return false;
                    }
                    $rId  = (string)($r['id'] ?? $r['rowid'] ?? '');
                    $rRef = (string)($r['ref'] ?? $r['reference'] ?? '');
                    return ($id !== '' && $rId === $id) || ($ref !== '' && $rRef === $ref);
                }));
            }

            if (empty($rows)) {
                send_json(404, ['ok' => false, 'error' => 'Facture introuvable.']);
            }

            $invoices = array_map('normalize_invoice', $rows);
            send_json(200, [
                'ok'       => true,
                'count'    => count($invoices),
                'invoices' => $invoices,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  COMMANDES
        // ─────────────────────────────────────────────────────────────────────
        case 'order.list': {
            $resp = n8n_call(['action' => 'order.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows   = extract_rows($resp['json'], ['orders', 'commandes'], ['id', 'ref', 'reference']);
            $orders = array_map('normalize_order', $rows);

            send_json(200, [
                'ok'     => true,
                'count'  => count($orders),
                'orders' => $orders,
            ]);
        }

        case 'order.detail': {
            $id  = trim((string)($_GET['id'] ?? ''));
            $ref = trim((string)($_GET['ref'] ?? ''));
            if ($id === '' && $ref === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » ou « ref » requis.']);
            }

            $resp = n8n_call([
                'action'    => 'order.detail',
                'client_id' => $clientId,
                'id'        => $id,
                'ref'       => $ref,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['orders', 'commandes'], ['id', 'ref', 'reference']);

            if (($id !== '' || $ref !== '') && count($rows) > 1) {
                $rows = array_values(array_filter($rows, static function ($r) use ($id, $ref): bool {
                    if (!is_array($r)) {
                        return false;
                    }
                    $rId  = (string)($r['id'] ?? $r['rowid'] ?? '');
                    $rRef = (string)($r['ref'] ?? $r['reference'] ?? '');
                    return ($id !== '' && $rId === $id) || ($ref !== '' && $rRef === $ref);
                }));
            }

            if (empty($rows)) {
                send_json(404, ['ok' => false, 'error' => 'Commande introuvable.']);
            }

            $orders = array_map('normalize_order', $rows);
            send_json(200, [
                'ok'     => true,
                'count'  => count($orders),
                'orders' => $orders,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  ÉQUIPES
        // ─────────────────────────────────────────────────────────────────────
        case 'team.list': {
            $resp = n8n_call([
                'action'    => 'team.list',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
            ]);
            ensure_ok($resp);

            $rows    = extract_rows($resp['json'], ['members', 'membres', 'contacts', 'users'], ['id', 'email', 'nom', 'lastname']);
            $members = array_map('normalize_member', $rows);

            $structure = $sessionStructure;
            if (is_array($resp['json']) && !empty($resp['json']['structure'])) {
                $structure = trim((string)$resp['json']['structure']);
            } elseif (!empty($members[0]['structure'])) {
                $structure = $members[0]['structure'];
            }

            send_json(200, [
                'ok'        => true,
                'count'     => count($members),
                'members'   => $members,
                'structure' => $structure,
                'can_edit'  => $canEdit,
            ]);
        }

        case 'team.ensure': {
            // Alimente (idempotent) la table « team » pour l'utilisateur COURANT.
            // client_id injecté depuis la session (non falsifiable) ; un membre
            // peut provisionner sa PROPRE appartenance (aucun droit d'édition requis).
            require_post();
            csrf_check();

            $payload = portailBuildTeamEnsurePayload($user, 'portail_api');
            // Valeurs serveur non falsifiables prioritaires.
            $payload['client_id'] = $clientId;
            if ($currentSiret !== '') {
                $payload['siret'] = $currentSiret;
            }
            if ($sessionStructure !== '') {
                $payload['structure'] = $sessionStructure;
            }

            $resp = n8n_call($payload);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => "L'initialisation de l'équipe a échoué (n8n HTTP " . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? "L'initialisation de l'équipe a échoué.")]);
            }

            $row = (is_array($resp['json']) && isset($resp['json']['row']) && is_array($resp['json']['row']))
                ? $resp['json']['row']
                : null;

            send_json(200, ['ok' => true, 'message' => 'Équipe initialisée.', 'row' => $row]);
        }

        case 'team.update': {
            require_post();
            csrf_check();

            if (!$canEdit) {
                send_json(403, ['ok' => false, 'error' => "Vous n'avez pas les droits pour modifier les membres de cette structure."]);
            }

            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) {
                send_json(400, ['ok' => false, 'error' => 'Membre invalide.']);
            }

            $email    = trim((string)($_POST['email'] ?? ''));
            $fonction = trim((string)($_POST['fonction'] ?? ''));
            $statutIn = s_lower(trim((string)($_POST['statut'] ?? '')));
            $active   = in_array($statutIn, ['1', 'actif', 'active', 'on', 'enabled', 'true'], true) ? 1 : 0;

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                send_json(400, ['ok' => false, 'error' => 'Adresse e-mail invalide.']);
            }

            $resp = n8n_call([
                'action'    => 'team.update',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
                'member_id' => $memberId,
                'email'     => $email,
                'fonction'  => $fonction,
                'statut'    => $active,
                'active'    => $active,
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La mise à jour a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La mise à jour a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Le membre a été mis à jour.']);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  DÉPLOIEMENTS (renommage « Mes services »)
        // ─────────────────────────────────────────────────────────────────────
        case 'deployment.list': {
            $resp = n8n_call(['action' => 'deployment.list', 'client_id' => $clientId]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['deployments'], ['deployment_name', 'name']);
            $deployments = [];
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $norm = normalize_deployment($r);
                if ($norm !== null) {
                    $deployments[] = $norm;
                }
            }

            send_json(200, ['ok' => true, 'deployments' => $deployments]);
        }

        case 'deployment.rename': {
            require_post();
            csrf_check();

            $deploymentName = trim((string)($_POST['deployment_name'] ?? ''));
            $displayName    = trim((string)($_POST['display_name'] ?? ''));
            if ($deploymentName === '') {
                send_json(400, ['ok' => false, 'error' => 'deployment_name manquant.']);
            }

            $resp = n8n_call([
                'action'          => 'deployment.rename',
                'client_id'       => $clientId,
                'deployment_name' => $deploymentName,
                'display_name'    => $displayName, // '' ⇒ réinitialise au nom technique
            ]);
            ensure_ok($resp);

            $row = (is_array($resp['json']) && isset($resp['json']['row']) && is_array($resp['json']['row']))
                ? $resp['json']['row']
                : ['deployment_name' => $deploymentName, 'display_name' => $displayName];

            send_json(200, ['ok' => true, 'row' => $row]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  NOTIFICATIONS (cloche)
        // ─────────────────────────────────────────────────────────────────────
        case 'notification.list': {
            $limit = (int)($_GET['limit'] ?? 20);
            if ($limit < 1)   { $limit = 1; }
            if ($limit > 100) { $limit = 100; }

            $resp = n8n_call([
                'action'    => 'notification.list',
                'client_id' => $clientId,
                'limit'     => $limit,
            ]);
            ensure_ok($resp);

            $rows = extract_rows($resp['json'], ['notifications'], ['id']);

            // unread : top-level n8n prioritaire, sinon calcul tolérant.
            $unread = null;
            if (is_array($resp['json']) && array_key_exists('unread', $resp['json']) && is_numeric($resp['json']['unread'])) {
                $unread = (int)$resp['json']['unread'];
            } else {
                $unread = 0;
                foreach ($rows as $r) {
                    if (is_array($r) && notif_is_unread($r)) {
                        $unread++;
                    }
                }
            }

            send_json(200, [
                'ok'            => true,
                'notifications' => $rows,
                'unread'        => $unread,
            ]);
        }

        case 'notification.read': {
            require_post();
            csrf_check();

            $all = truthy($_POST['all'] ?? '0');
            $id  = trim((string)($_POST['id'] ?? ''));
            if (!$all && $id === '') {
                send_json(400, ['ok' => false, 'error' => 'Préciser id=… ou all=1.']);
            }

            $resp = n8n_call([
                'action'    => 'notification.read',
                'client_id' => $clientId,
                'all'       => $all ? 1 : 0,
                'id'        => $all ? null : $id,
            ]);
            ensure_ok($resp);

            send_json(200, ['ok' => true]);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  STATISTIQUES (cartes + graphique du dashboard)
        // ─────────────────────────────────────────────────────────────────────
        case 'stats.dashboard': {
            // Lecture : pas de CSRF. client_id + contexte k8s injectés SERVEUR
            // (non falsifiables) via $user. Récupère les stats par deployment
            // au travers du webhook n8n unique (action "stats.dashboard").
            $stats        = portailFetchDashboardStats($user);
            $byDeployment = is_array($stats['by_deployment'] ?? null) ? $stats['by_deployment'] : [];

            // Agrégat tous deployments (alimente la carte « Requêtes ce mois-ci »
            // et la tendance vs mois précédent).
            $current  = 0;
            $previous = 0;
            $byMonth  = [];
            foreach ($byDeployment as $dep) {
                if (!is_array($dep)) {
                    continue;
                }
                $current  += (int)($dep['current_month_hits']  ?? 0);
                $previous += (int)($dep['previous_month_hits'] ?? 0);
                foreach (($dep['by_month'] ?? []) as $m => $c) {
                    $byMonth[(string)$m] = ($byMonth[(string)$m] ?? 0) + (int)$c;
                }
            }
            ksort($byMonth);

            send_json(200, [
                'ok'                  => true,
                'current_month_hits'  => $current,
                'previous_month_hits' => $previous,
                'by_month'            => $byMonth,
                'by_deployment'       => $byDeployment,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────
        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}