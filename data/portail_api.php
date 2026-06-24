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
 *   TICKETS (support / assistance)
 *     ticket.list           GET                       → { ok, count, tickets:[...] }
 *     ticket.detail         GET   ?id=                → { ok, ticket:{..., messages:[...]} }
 *     ticket.create         POST  CSRF                → { ok, message, ticket? }
 *     ticket.reply          POST  CSRF                → { ok, message }
 *     ticket.close          POST  CSRF  reopen=0|1    → { ok, message }
 *   SUPPORT (console équipe GNL — require_support)
 *     support.ticket.list   GET   ?status=            → { ok, count, tickets:[...] }
 *     support.ticket.detail GET   ?id=                → { ok, ticket:{..., messages:[...]} }
 *     support.ticket.reply  POST  CSRF                → { ok, message }   (author_type=support)
 *     support.ticket.update POST  CSRF  status|priority → { ok, message }
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

// ── Contrôle d'accès « support » (console gestion-ticket.php) ──────────────────
// Si CE déploiement est entièrement dédié au support (tous les comptes connectés
// sont des agents), laissez TICKET_SUPPORT_SITE = true : tout utilisateur authentifié
// est alors considéré comme support, sans heuristique.
//
// ⚠️  Si ce MÊME proxy sert aussi un portail CLIENT, repassez-le à false : le contrôle
//     par rôle / domaine e-mail / SIRET ci-dessous s'appliquera alors (fail-closed).
const TICKET_SUPPORT_SITE          = true;
const TICKET_SUPPORT_EMAIL_DOMAINS = ['gnl-solution.fr']; // utilisé seulement si TICKET_SUPPORT_SITE = false
const TICKET_SUPPORT_SIRETS        = [];                   // idem

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
        $n = (int)$value;
        if ($n > 100000000000) { // millisecondes → secondes
            $n = (int)($n / 1000);
        }
        return $n > 0 ? $n : null;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    // Retire un éventuel suffixe de zone entre crochets que ni DateTime ni
    // strtotime ne savent lire : "...+02:00[Europe/Paris]", "...226[UTC]".
    $s = (string)preg_replace('/\[[^\]]*\]\s*$/', '', $s);
    try {
        // DateTimeImmutable gère millisecondes (.226), offset (+02:00) et « Z ».
        return (new DateTimeImmutable($s))->getTimestamp();
    } catch (Throwable $e) {
        $ts = strtotime($s);
        return $ts === false ? null : $ts;
    }
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
//  Normalisation — TICKETS (support / assistance)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Sélecteur tolérant : insensible à la casse ET aux séparateurs.
 * « created_at », « createdAt », « CreatedAt », « created-at » matchent tous.
 * On tente d'abord la correspondance exacte (rapide), puis la forme normalisée.
 */
function ticket_pick(array $row, array $keys, $default = null)
{
    $hit = pick($row, $keys, $default);
    if ($hit !== $default) {
        return $hit;
    }
    $norm   = static fn($k): string => (string)preg_replace('/[^a-z0-9]/', '', strtolower((string)$k));
    $wanted = array_map($norm, $keys);
    foreach ($row as $k => $v) {
        if ($v === null || trim((string)$v) === '') {
            continue;
        }
        if (in_array($norm($k), $wanted, true)) {
            return $v;
        }
    }
    return $default;
}

/** Clé canonique de statut (utilisée par les onglets/filtres de la page). */
function ticket_status_key($status): string
{
    $n = s_lower(trim((string)$status));
    if (in_array($n, ['closed', 'ferme', 'fermé', 'close', '4'], true)) {
        return 'ferme';
    }
    if (in_array($n, ['resolved', 'resolu', 'résolu', 'done', '3'], true)) {
        return 'resolu';
    }
    if (in_array($n, ['pending', 'en_attente', 'attente', 'waiting', 'on_hold', '2'], true)) {
        return 'en_attente';
    }
    if (in_array($n, ['in_progress', 'en_cours', 'processing', 'progress', '1'], true)) {
        return 'en_cours';
    }
    return 'ouvert';
}

function ticket_status_label($status): string
{
    return [
        'ouvert'     => 'Ouvert',
        'en_cours'   => 'En cours',
        'en_attente' => 'En attente',
        'resolu'     => 'Résolu',
        'ferme'      => 'Fermé',
    ][ticket_status_key($status)] ?? 'Ouvert';
}

function ticket_status_class($status): string
{
    switch (ticket_status_key($status)) {
        case 'resolu':
            return 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
        case 'ferme':
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        case 'en_cours':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300';
        case 'en_attente':
            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
        default: // ouvert
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
    }
}

/** Clé canonique de priorité. */
function ticket_priority_key($priority): string
{
    $n = s_lower(trim((string)$priority));
    if (in_array($n, ['urgent', 'urgente', 'critical', 'critique', '4'], true)) {
        return 'urgente';
    }
    if (in_array($n, ['high', 'haute', 'elevee', 'élevée', '3'], true)) {
        return 'haute';
    }
    if (in_array($n, ['low', 'basse', 'faible', '1'], true)) {
        return 'basse';
    }
    return 'normale';
}

function ticket_priority_label($priority): string
{
    return [
        'basse'   => 'Basse',
        'normale' => 'Normale',
        'haute'   => 'Haute',
        'urgente' => 'Urgente',
    ][ticket_priority_key($priority)] ?? 'Normale';
}

function ticket_priority_class($priority): string
{
    switch (ticket_priority_key($priority)) {
        case 'urgente':
            return 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
        case 'haute':
            return 'bg-orange-100 text-orange-700 dark:bg-orange-900/20 dark:text-orange-300';
        case 'basse':
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        default: // normale
            return 'bg-sky-100 text-sky-700 dark:bg-sky-900/20 dark:text-sky-300';
    }
}

function ticket_category_label($category): string
{
    $n = s_lower(trim((string)$category));
    return [
        'technique'   => 'Technique',
        'facturation' => 'Facturation',
        'commercial'  => 'Commercial',
        'compte'      => 'Compte',
        'autre'       => 'Autre',
    ][$n] ?? ($n !== '' ? ucfirst($n) : 'Autre');
}

/** Formate un instant (timestamp) en fuseau métier (Europe/Paris), date ± heure. */
function ticket_datetime(?int $ts, bool $withTime = true): string
{
    if ($ts === null || $ts <= 0) {
        return '—';
    }
    $tz = defined('PORTAIL_STATS_TZ') ? PORTAIL_STATS_TZ : 'Europe/Paris';
    try {
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($tz));
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable('@' . $ts);
    }
    return $dt->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
}

/** Normalise un code de civilité en libellé pointé : "m"/"mr"/"monsieur" → "M.". */
function civility_label($code): string
{
    $n = s_lower(trim((string)$code));
    if ($n === '') {
        return '';
    }
    if (in_array($n, ['m', 'mr', 'm.', 'mister', 'monsieur'], true)) {
        return 'M.';
    }
    if (in_array($n, ['mme', 'mrs', 'ms', 'madame'], true)) {
        return 'Mme';
    }
    if (in_array($n, ['mlle', 'miss', 'mademoiselle'], true)) {
        return 'Mlle';
    }
    if (in_array($n, ['dr', 'dr.', 'docteur', 'doctor'], true)) {
        return 'Dr';
    }
    if (in_array($n, ['me', 'maitre', 'maître'], true)) {
        return 'Me';
    }
    return ucfirst($n);
}

/** Nom complet de l'utilisateur connecté, civilité comprise (ex. « M. Gabin Grobost »). */
function user_display_name(array $user): string
{
    $civ    = civility_label(pick($user, ['civilite', 'civility', 'civility_code'], ''));
    $prenom = trim((string)(pick($user, ['prenom', 'firstname', 'first_name']) ?? ''));
    $nom    = trim((string)(pick($user, ['nom', 'lastname', 'last_name']) ?? ''));

    $full = trim(preg_replace('/\s+/', ' ', trim($civ . ' ' . $prenom . ' ' . $nom)));
    if ($full !== '' && ($prenom !== '' || $nom !== '')) {
        return $full;
    }
    $u = trim((string)(pick($user, ['username', 'login', 'email']) ?? ''));
    return $u !== '' ? $u : 'Utilisateur';
}

/** Retire une civilité éventuelle en tête de nom : "M Gabin Grobost" → "Gabin Grobost". */
function strip_civility(string $name): string
{
    return trim((string)preg_replace(
        '/^(M|Mr|Mme|Mlle|Dr|Me|Monsieur|Madame|Mademoiselle|Docteur)\.?\s+/iu',
        '',
        trim($name)
    ));
}

/** Met la civilité de tête au propre : "M Gabin Grobost" → "M. Gabin Grobost". */
function dot_civility_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return $name;
    }
    return (string)preg_replace_callback(
        '/^(M|Mr|Mme|Mlle|Dr|Me|Monsieur|Madame|Mademoiselle|Docteur)\.?\s+/iu',
        static fn($mm) => civility_label($mm[1]) . ' ',
        $name
    );
}

/**
 * Vrai si l'utilisateur connecté appartient à l'équipe support GNL.
 * Critères (au moins un) : rôle/flag de session, domaine e-mail, ou SIRET interne.
 * Fail-closed : tout ce qui n'est pas explicitement support est refusé.
 */
function user_is_support(array $user): bool
{
    if (TICKET_SUPPORT_SITE) {
        return true; // déploiement entièrement dédié au support
    }
    $role = s_lower(trim((string)(pick($user, ['role', 'type', 'profil', 'profile']) ?? '')));
    if (in_array($role, ['support', 'staff', 'agent', 'admin', 'gnl', 'interne'], true)) {
        return true;
    }
    foreach (['is_support', 'is_staff', 'is_admin', 'support', 'staff', 'admin'] as $flag) {
        if (array_key_exists($flag, $user) && truthy($user[$flag])) {
            return true;
        }
    }
    $email = s_lower(trim((string)($user['email'] ?? '')));
    $at    = strrchr($email, '@');
    if ($at !== false) {
        $dom = ltrim($at, '@');
        foreach (TICKET_SUPPORT_EMAIL_DOMAINS as $d) {
            if ($dom !== '' && $dom === s_lower(trim((string)$d))) {
                return true;
            }
        }
    }
    $siret = preg_replace('/\s+/', '', (string)($user['siret'] ?? ''));
    if ($siret !== '') {
        foreach (TICKET_SUPPORT_SIRETS as $s) {
            if ($siret === preg_replace('/\s+/', '', (string)$s)) {
                return true;
            }
        }
    }
    return false;
}

/** Refuse l'accès (403) si l'utilisateur n'est pas support. */
function require_support(array $user): void
{
    if (!user_is_support($user)) {
        send_json(403, ['ok' => false, 'error' => 'Accès réservé à l’équipe support.']);
    }
}

/**
 * Sépare la réponse n8n d'un détail de ticket en [ligne ticket | null, messages normalisés].
 * Gère les trois formes : ligne ticket seule, ticket + "messages", ou tableau de messages.
 */
function extract_ticket_and_messages($json): array
{
    $rows = extract_rows($json, ['tickets', 'messages'], ['id', 'ref', 'reference']);

    $isMessageRow = static function ($r): bool {
        return is_array($r) && (
            array_key_exists('body', $r) || array_key_exists('author_type', $r) ||
            array_key_exists('authorType', $r) || array_key_exists('ticket_id', $r) ||
            array_key_exists('ticketId', $r)
        );
    };
    $isTicketRow = static function ($r): bool {
        return is_array($r) && (
            array_key_exists('subject', $r) || array_key_exists('sujet', $r) ||
            array_key_exists('objet', $r) || array_key_exists('status', $r) ||
            array_key_exists('statut', $r)
        );
    };

    $messageRows = [];
    if (is_array($json) && isset($json['messages']) && is_array($json['messages'])) {
        foreach ($json['messages'] as $m) {
            if (is_array($m)) {
                $messageRows[] = $m;
            }
        }
    }

    $ticketRow = null;
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (isset($r['messages']) && is_array($r['messages'])) {
            foreach ($r['messages'] as $m) {
                if (is_array($m)) {
                    $messageRows[] = $m;
                }
            }
        }
        if ($isMessageRow($r) && !$isTicketRow($r)) {
            $messageRows[] = $r;
        } elseif ($isTicketRow($r) && $ticketRow === null) {
            $ticketRow = $r;
        } elseif (!$isMessageRow($r) && $ticketRow === null && empty($messageRows)) {
            $ticketRow = $r;
        }
    }

    $messages = array_map('normalize_ticket_message', array_values(array_filter($messageRows, 'is_array')));
    usort($messages, static function (array $a, array $b): int {
        return ($a['created_ts'] ?? 0) <=> ($b['created_ts'] ?? 0);
    });

    return [$ticketRow, $messages];
}

/**
 * Étiquette de date relative pour un message :
 *   aujourd'hui → "10:51" · hier → "Hier 10:51" · sinon → "3J" / "2M" / "1A".
 */
function ticket_msg_when(?int $ts): string
{
    if ($ts === null || $ts <= 0) {
        return '—';
    }
    $tz = new DateTimeZone(defined('PORTAIL_STATS_TZ') ? PORTAIL_STATS_TZ : 'Europe/Paris');
    try {
        $d   = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $now = new DateTimeImmutable('now', $tz);
    } catch (Throwable $e) {
        return ticket_datetime($ts, true);
    }

    $msgDay = $d->setTime(0, 0, 0);
    $today  = $now->setTime(0, 0, 0);

    if ($msgDay > $today) {            // futur (horloge décalée) → on montre l'heure
        return $d->format('H:i');
    }
    $ageDays = (int)$msgDay->diff($today)->days;
    if ($ageDays === 0) {
        return $d->format('H:i');      // aujourd'hui
    }
    if ($ageDays === 1) {
        return 'Hier ' . $d->format('H:i');
    }
    if ($ageDays < 31) {
        return $ageDays . 'J';
    }
    $iv = $msgDay->diff($today);
    if ($iv->y >= 1) {
        return $iv->y . 'A';
    }
    $months = $iv->y * 12 + $iv->m;
    return ($months >= 1 ? $months : 1) . 'M';
}

/** Mappe une ligne n8n (table « ticket_portail ») vers la structure attendue par la page. */
function normalize_ticket(array $row): array
{
    $id = pick($row, ['id', 'rowid', 'ticket_id', 'ticketId'], 0);
    $id = is_numeric($id) ? (int)$id : 0;

    $ref = (string)pick($row, ['ref', 'reference', 'number'], $id > 0 ? sprintf('TIC-%06d', $id) : 'TIC');

    $createdTs = to_timestamp(pick($row, ['created_at', 'createdAt', 'date_creation', 'datec', 'created', 'date']));
    $updatedTs = to_timestamp(pick($row, ['updated_at', 'updatedAt', 'date_modification', 'tms', 'updated', 'last_reply_at', 'lastReplyAt']));

    $subject   = trim((string)pick($row, ['subject', 'sujet', 'objet', 'title', 'titre'], ''));
    $category  = (string)pick($row, ['category', 'categorie', 'type'], '');
    $message   = trim((string)pick($row, ['message', 'description', 'body', 'content'], ''));
    $statusRaw = (string)pick($row, ['status', 'statut', 'state'], 'ouvert');
    $prioRaw   = (string)pick($row, ['priority', 'priorite', 'prio'], 'normale');
    $replies   = pick($row, ['replies_count', 'messages_count', 'nb_messages'], null);

    $subcategory = trim((string)pick($row, ['subcategory', 'sous_categorie', 'souscategorie', 'subcategorie'], ''));
    $deployments = pick($row, ['deployments', 'deploiements', 'deployment', 'deploiement'], '');
    if (is_array($deployments)) {
        $deployments = implode(', ', array_map('strval', $deployments));
    }
    $deployments = trim((string)$deployments);
    $domains = pick($row, ['domains', 'domaines', 'domain', 'domaine'], '');
    if (is_array($domains)) {
        $domains = implode(', ', array_map('strval', $domains));
    }
    $domains = trim((string)$domains);

    // Créateur du ticket (colonne author_name de ticket_portail).
    $createdBy = dot_civility_name(trim((string)pick($row, ['author_name', 'authorName', 'created_by', 'createdBy', 'author', 'auteur'], '')));

    $rowClientId = (string)pick($row, ['client_id', 'clientId'], '');
    $structure   = trim((string)pick($row, ['structure', 'raison', 'organization', 'organization_name', 'nom_commercial'], ''));

    return [
        'id'             => $id,
        'ref'            => $ref,
        'subject'        => $subject !== '' ? $subject : 'Sans objet',
        'category'       => ticket_category_label($category),
        'category_key'   => s_lower(trim((string)$category)),
        'subcategory'    => $subcategory,
        'deployments'    => $deployments,
        'domains'        => $domains,
        'created_by'     => $createdBy,
        'client_id'      => $rowClientId,
        'structure'      => $structure,
        'message'        => $message,
        'priority'       => ticket_priority_key($prioRaw),
        'priority_label' => ticket_priority_label($prioRaw),
        'priority_class' => ticket_priority_class($prioRaw),
        'status'         => ticket_status_key($statusRaw),
        'status_label'   => ticket_status_label($statusRaw),
        'status_class'   => ticket_status_class($statusRaw),
        'created_at'     => ticket_datetime($createdTs, false),
        'created_full'   => ticket_datetime($createdTs, true),
        'created_ts'     => $createdTs,
        'updated_at'     => ticket_datetime($updatedTs, false),
        'updated_ts'     => $updatedTs,
        'replies_count'  => is_numeric($replies) ? (int)$replies : null,
    ];
}

/** Mappe une ligne de message/réponse (table « ticket_message_portail »). */
function normalize_ticket_message(array $row): array
{
    $createdTs = to_timestamp(pick($row, ['created_at', 'createdAt', 'date', 'datec', 'created', 'tms']));

    $type      = s_lower(trim((string)pick($row, ['author_type', 'authorType', 'type', 'sender', 'from', 'role'], 'client')));
    $isSupport = in_array($type, ['support', 'staff', 'agent', 'admin', 'assistance', 'gnl'], true);

    return [
        'id'            => (int)pick($row, ['id', 'rowid'], 0),
        'body'          => trim((string)pick($row, ['body', 'message', 'content', 'text'], '')),
        'author'        => dot_civility_name(trim((string)pick($row, ['author_name', 'authorName', 'author', 'name', 'user', 'from_name'], $isSupport ? 'Support GNL' : 'Vous'))),
        'author_type'   => $isSupport ? 'support' : 'client',
        'created_at'    => ticket_datetime($createdTs, true), // complet (info-bulle)
        'created_label' => ticket_msg_when($createdTs),       // relatif (affiché)
        'created_ts'    => $createdTs,
    ];
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
        //  TICKETS (support / assistance)
        // ─────────────────────────────────────────────────────────────────────
        case 'ticket.list': {
            $resp = n8n_call([
                'action'    => 'ticket.list',
                'client_id' => $clientId,
                'siret'     => $currentSiret,
            ]);
            ensure_ok($resp);

            $rows    = extract_rows($resp['json'], ['tickets'], ['id', 'ref', 'reference']);
            $tickets = array_map('normalize_ticket', $rows);

            // Plus récents d'abord (dernière mise à jour, sinon création).
            usort($tickets, static function (array $a, array $b): int {
                return ($b['updated_ts'] ?? $b['created_ts'] ?? 0) <=> ($a['updated_ts'] ?? $a['created_ts'] ?? 0);
            });

            send_json(200, [
                'ok'      => true,
                'count'   => count($tickets),
                'tickets' => $tickets,
            ]);
        }

        case 'ticket.detail': {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » requis.']);
            }

            $resp = n8n_call([
                'action'    => 'ticket.detail',
                'client_id' => $clientId,
                'id'        => $id,
            ]);
            ensure_ok($resp);

            // n8n peut renvoyer, selon le workflow :
            //   - une ligne ticket seule,
            //   - une ligne ticket + un tableau "messages",
            //   - OU directement le tableau des messages (table ticket_message_portail).
            // On classe donc chaque ligne : « ressemble à un message » ou « à un ticket ».
            $rows = extract_rows($resp['json'], ['tickets', 'messages'], ['id', 'ref', 'reference']);

            $isMessageRow = static function ($r): bool {
                return is_array($r) && (
                    array_key_exists('body', $r) || array_key_exists('author_type', $r) ||
                    array_key_exists('authorType', $r) || array_key_exists('ticket_id', $r) ||
                    array_key_exists('ticketId', $r)
                );
            };
            $isTicketRow = static function ($r): bool {
                return is_array($r) && (
                    array_key_exists('subject', $r) || array_key_exists('sujet', $r) ||
                    array_key_exists('objet', $r) || array_key_exists('status', $r) ||
                    array_key_exists('statut', $r)
                );
            };

            // Messages explicitement fournis au niveau racine.
            $messageRows = [];
            if (is_array($resp['json']) && isset($resp['json']['messages']) && is_array($resp['json']['messages'])) {
                foreach ($resp['json']['messages'] as $m) {
                    if (is_array($m)) {
                        $messageRows[] = $m;
                    }
                }
            }

            $ticketRow = null;
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                // Messages imbriqués dans une ligne ticket.
                if (isset($r['messages']) && is_array($r['messages'])) {
                    foreach ($r['messages'] as $m) {
                        if (is_array($m)) {
                            $messageRows[] = $m;
                        }
                    }
                }
                if ($isMessageRow($r) && !$isTicketRow($r)) {
                    $messageRows[] = $r;
                } elseif ($isTicketRow($r) && $ticketRow === null) {
                    $ticketRow = $r;
                } elseif (!$isMessageRow($r) && $ticketRow === null && empty($messageRows)) {
                    // Ligne ambiguë et rien d'autre : on tente le ticket.
                    $ticketRow = $r;
                }
            }

            $messages = array_map('normalize_ticket_message', array_values(array_filter($messageRows, 'is_array')));
            usort($messages, static function (array $a, array $b): int {
                return ($a['created_ts'] ?? 0) <=> ($b['created_ts'] ?? 0);
            });

            // Civilité de l'initiateur : depuis la session si disponible, sinon détectée
            // dans l'un de ses messages (ex. « M Gabin Grobost »). On l'applique ensuite
            // à TOUS ses messages → « M. Gabin Grobost » de façon homogène.
            $meCiv = civility_label(pick($user, ['civilite', 'civility', 'civility_code'], ''));
            if ($meCiv === '') {
                foreach ($messages as $m) {
                    if (($m['author_type'] ?? '') === 'client'
                        && preg_match('/^(M|Mr|Mme|Mlle|Dr|Me|Monsieur|Madame|Mademoiselle|Docteur)\.?\s+/iu', (string)$m['author'], $mm)) {
                        $meCiv = civility_label($mm[1]);
                        break;
                    }
                }
            }
            if ($meCiv !== '') {
                foreach ($messages as &$m) {
                    if (($m['author_type'] ?? '') === 'client') {
                        $bare = strip_civility((string)$m['author']);
                        $m['author'] = $bare !== '' ? $meCiv . ' ' . $bare : $meCiv;
                    }
                }
                unset($m);
            }

            if ($ticketRow !== null) {
                $ticket = normalize_ticket($ticketRow);
                $ticket['messages'] = $messages;
            } else {
                // Pas de ligne ticket : la page complète les métadonnées depuis ticket.list.
                $ticket = [
                    'id'       => is_numeric($id) ? (int) $id : $id,
                    'messages' => $messages,
                ];
            }

            send_json(200, ['ok' => true, 'ticket' => $ticket]);
        }

        case 'ticket.create': {
            require_post();
            csrf_check();

            $subject  = trim((string)($_POST['subject'] ?? ''));
            $message  = trim((string)($_POST['message'] ?? ''));
            $category = s_lower(trim((string)($_POST['category'] ?? 'technique')));
            $priority = s_lower(trim((string)($_POST['priority'] ?? 'normale')));

            if (s_sub($subject, 0) === '' || mb_strlen($subject) < 3 || mb_strlen($subject) > 150) {
                send_json(400, ['ok' => false, 'error' => 'L’objet doit contenir entre 3 et 150 caractères.']);
            }
            if (mb_strlen($message) < 5 || mb_strlen($message) > 5000) {
                send_json(400, ['ok' => false, 'error' => 'Le message doit contenir entre 5 et 5000 caractères.']);
            }
            if (!in_array($category, ['technique', 'facturation', 'commercial', 'compte', 'autre'], true)) {
                $category = 'autre';
            }
            if (!in_array($priority, ['basse', 'normale', 'haute', 'urgente'], true)) {
                $priority = 'normale';
            }

            // Sous-catégorie : pertinente uniquement pour la catégorie « technique ».
            $subcategory = s_lower(trim((string)($_POST['subcategory'] ?? '')));
            if ($category !== 'technique') {
                $subcategory = '';
            } elseif (!in_array($subcategory, ['dns', 'deployment'], true)) {
                $subcategory = '';
            }

            // Déploiements concernés (uniquement si sous-catégorie « deployment »).
            $deployments = [];
            if ($subcategory === 'deployment') {
                $raw = $_POST['deployments'] ?? [];
                if (is_string($raw)) {
                    $raw = array_map('trim', explode(',', $raw));
                }
                if (is_array($raw)) {
                    foreach ($raw as $d) {
                        $d = trim((string)$d);
                        if ($d !== '') {
                            $deployments[] = $d;
                        }
                    }
                }
                $deployments = array_values(array_unique($deployments));
                if (empty($deployments)) {
                    send_json(400, ['ok' => false, 'error' => 'Sélectionnez au moins un déploiement concerné.']);
                }
            }

            // Domaines concernés (uniquement si sous-catégorie « dns »).
            $domains = [];
            if ($subcategory === 'dns') {
                $raw = $_POST['domains'] ?? [];
                if (is_string($raw)) {
                    $raw = array_map('trim', explode(',', $raw));
                }
                if (is_array($raw)) {
                    foreach ($raw as $d) {
                        $d = rtrim(strtolower(trim((string)$d)), '.');
                        if ($d !== '') {
                            $domains[] = $d;
                        }
                    }
                }
                $domains = array_values(array_unique($domains));
                if (empty($domains)) {
                    send_json(400, ['ok' => false, 'error' => 'Sélectionnez au moins un domaine concerné.']);
                }
            }

            $authorName = user_display_name($user);

            $resp = n8n_call([
                'action'       => 'ticket.create',
                'client_id'    => $clientId,
                'siret'        => $currentSiret,
                'structure'    => $sessionStructure,
                'subject'      => $subject,
                'message'      => $message,
                'category'     => $category,
                'subcategory'  => $subcategory,
                'deployments'  => implode(', ', $deployments),
                'domains'      => implode(', ', $domains),
                'priority'     => $priority,
                'status'       => 'ouvert',
                // Contexte auteur injecté SERVEUR (non falsifiable).
                'author_name'  => $authorName,
                'author_email' => (string)($user['email'] ?? ''),
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La création du ticket a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La création du ticket a échoué.')]);
            }

            $row = null;
            if (is_array($resp['json'])) {
                if (isset($resp['json']['row']) && is_array($resp['json']['row'])) {
                    $row = $resp['json']['row'];
                } elseif (isset($resp['json']['ticket']) && is_array($resp['json']['ticket'])) {
                    $row = $resp['json']['ticket'];
                } else {
                    $rows = extract_rows($resp['json'], ['tickets'], ['id', 'ref']);
                    $row  = $rows[0] ?? null;
                }
            }

            send_json(200, [
                'ok'      => true,
                'message' => 'Votre ticket a été créé.',
                'ticket'  => is_array($row) ? normalize_ticket($row) : null,
            ]);
        }

        case 'ticket.reply': {
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            $body     = trim((string)($_POST['body'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }
            if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
                send_json(400, ['ok' => false, 'error' => 'Le message doit contenir entre 1 et 5000 caractères.']);
            }

            $authorName = user_display_name($user);

            $resp = n8n_call([
                'action'      => 'ticket.reply',
                'client_id'   => $clientId,
                'ticket_id'   => $ticketId,
                'body'        => $body,
                'author_type' => 'client',
                'author_name' => $authorName,
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'L’envoi de la réponse a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'L’envoi de la réponse a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Réponse envoyée.']);
        }

        case 'ticket.close': {
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }
            $reopen = truthy($_POST['reopen'] ?? '0');

            $resp = n8n_call([
                'action'    => $reopen ? 'ticket.reopen' : 'ticket.close',
                'client_id' => $clientId,
                'ticket_id' => $ticketId,
                'status'    => $reopen ? 'ouvert' : 'ferme',
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'L’opération a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'L’opération a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => $reopen ? 'Ticket rouvert.' : 'Ticket clôturé.']);
        }

        // ─────────────────────────────────────────────────────────────────────
        //  CONSOLE SUPPORT (équipe GNL) — accès réservé via require_support()
        // ─────────────────────────────────────────────────────────────────────
        case 'support.ticket.list': {
            require_support($user);

            $resp = n8n_call([
                'action'   => 'support.ticket.list',
                'support'  => 1,
                'agent_id' => $clientId,
                'status'   => s_lower(trim((string)($_GET['status'] ?? ''))),
            ]);
            ensure_ok($resp);

            $rows    = extract_rows($resp['json'], ['tickets'], ['id', 'ref', 'reference']);
            $tickets = array_map('normalize_ticket', $rows);
            usort($tickets, static function (array $a, array $b): int {
                return ($b['updated_ts'] ?? $b['created_ts'] ?? 0) <=> ($a['updated_ts'] ?? $a['created_ts'] ?? 0);
            });

            send_json(200, ['ok' => true, 'count' => count($tickets), 'tickets' => $tickets]);
        }

        case 'support.ticket.detail': {
            require_support($user);

            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                send_json(400, ['ok' => false, 'error' => 'Paramètre « id » requis.']);
            }

            $resp = n8n_call([
                'action'  => 'support.ticket.detail',
                'support' => 1,
                'id'      => $id,
            ]);
            ensure_ok($resp);

            [$ticketRow, $messages] = extract_ticket_and_messages($resp['json']);

            if ($ticketRow !== null) {
                $ticket = normalize_ticket($ticketRow);
                $ticket['messages'] = $messages;
            } else {
                $ticket = ['id' => is_numeric($id) ? (int) $id : $id, 'messages' => $messages];
            }

            send_json(200, ['ok' => true, 'ticket' => $ticket]);
        }

        case 'support.ticket.reply': {
            require_support($user);
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            $body     = trim((string)($_POST['body'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }
            if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
                send_json(400, ['ok' => false, 'error' => 'Le message doit contenir entre 1 et 5000 caractères.']);
            }

            $resp = n8n_call([
                'action'      => 'support.ticket.reply',
                'support'     => 1,
                'agent_id'    => $clientId,
                'ticket_id'   => $ticketId,
                'body'        => $body,
                'author_type' => 'support',
                'author_name' => user_display_name($user),
            ]);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'L’envoi de la réponse a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'L’envoi de la réponse a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Réponse envoyée.']);
        }

        case 'support.ticket.update': {
            require_support($user);
            require_post();
            csrf_check();

            $ticketId = trim((string)($_POST['ticket_id'] ?? ''));
            if ($ticketId === '') {
                send_json(400, ['ok' => false, 'error' => 'Ticket invalide.']);
            }

            $payload   = ['action' => 'support.ticket.update', 'support' => 1, 'agent_id' => $clientId, 'ticket_id' => $ticketId];
            $hasChange = false;
            if (isset($_POST['status']) && trim((string)$_POST['status']) !== '') {
                $payload['status'] = ticket_status_key($_POST['status']);
                $hasChange = true;
            }
            if (isset($_POST['priority']) && trim((string)$_POST['priority']) !== '') {
                $payload['priority'] = ticket_priority_key($_POST['priority']);
                $hasChange = true;
            }
            if (!$hasChange) {
                send_json(400, ['ok' => false, 'error' => 'Aucune modification fournie.']);
            }

            $resp = n8n_call($payload);

            if ($resp['status'] !== 0 && ($resp['status'] < 200 || $resp['status'] >= 300)) {
                send_json($resp['status'], ['ok' => false, 'error' => 'La mise à jour a échoué (n8n HTTP ' . $resp['status'] . ').']);
            }
            if (is_array($resp['json']) && array_key_exists('ok', $resp['json']) && $resp['json']['ok'] === false) {
                send_json(502, ['ok' => false, 'error' => (string)($resp['json']['error'] ?? 'La mise à jour a échoué.')]);
            }

            send_json(200, ['ok' => true, 'message' => 'Ticket mis à jour.']);
        }

        // ─────────────────────────────────────────────────────────────────────
        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue : ' . $action]);
    }
} catch (Throwable $e) {
    send_json(502, ['ok' => false, 'error' => $e->getMessage()]);
}