<?php

/**
 * include/services_catalog.php
 *
 * Source de vérité PARTAGÉE des services achetés par le client connecté.
 *
 * Utilisé par :
 *   - data/services_menu_api.php   → dépliants « Mes services » de la barre latérale
 *   - pages/deployment.php         → résolution d'un ?product_uid= + contrôle d'accès
 *   - data/ptero_api.php           → contrôle d'accès avant tout appel Pterodactyl
 *
 * Chaîne d'appels n8n (webhook unique « data-portail », via portailApiCall) :
 *
 *   1) order.list      → commandes du client → références (« ref »)
 *   2) order.product   → lignes de chaque commande, filtrées sur
 *                        status ∈ {active, suspended, deployment} ; on y lit uid, slug,
 *                        status et provider_service_slug
 *   3) product.list    → catalogue : slug → name, esp_cli_menu_name, provider_type
 *   4) deployment.list → renommages client (label_portail V2) : product_uid → display_name
 *
 * ── CONTRÔLE D'ACCÈS ─────────────────────────────────────────────────────────
 * portailApiCall() injecte SERVEUR le client_id (UID Keycloak de la session) :
 * la liste obtenue ne contient donc QUE les produits du client connecté. Un uid
 * absent de cette liste n'appartient pas au client — c'est exactement ce que
 * servicesCatalogFindByUid() exploite pour autoriser (ou non) l'accès à une
 * page de service. Aucune confiance n'est accordée au paramètre d'URL.
 *
 * ── CACHE ────────────────────────────────────────────────────────────────────
 * Résultat mémorisé en session pendant SERVICES_CATALOG_TTL secondes : le menu
 * est rendu sur toutes les pages et la page deployment interroge le même jeu de
 * données à chaque action. servicesCatalogInvalidate() est appelé après un
 * renommage (data/portail_api.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/portail_api_client.php';

/** Durée de vie du cache session (secondes). */
if (!defined('SERVICES_CATALOG_TTL')) {
    define('SERVICES_CATALOG_TTL', 120);
}

/** Nombre maximum de commandes interrogées (garde-fou anti-avalanche n8n). */
if (!defined('SERVICES_CATALOG_MAX_ORDERS')) {
    define('SERVICES_CATALOG_MAX_ORDERS', 50);
}

/** Clé du cache en session. */
if (!defined('SERVICES_CATALOG_CACHE_KEY')) {
    define('SERVICES_CATALOG_CACHE_KEY', 'services_catalog_cache');
}

/**
 * Base des liens « page du service ».
 * Le lien produit est <base>?product_uid=<order_product.uid> : c'est la page
 * deployment qui vérifie ensuite les droits et choisit le bon fournisseur.
 *
 * Valeur RELATIVE par défaut : le lien suit l'origine sur laquelle le client
 * navigue réellement (espace-client.gnl-solution.fr, b2b-portal.eu.gnl-solution.com,
 * préprod, local…). Une base absolue en dur renverrait tous les clients sur un
 * seul domaine, quelle que soit la façade utilisée.
 *
 * Surchargeable par la variable d'environnement PORTAIL_DEPLOYMENT_URL pour les
 * rares cas où un lien absolu est nécessaire (e-mail, appel externe).
 */
if (!defined('SERVICES_CATALOG_DEPLOYMENT_URL')) {
    define('SERVICES_CATALOG_DEPLOYMENT_URL', '/deployment');
}

/** Colonne product.esp_cli_menu_name → clé de dépliant. */
function servicesCatalogMenus(): array
{
    return ['web', 'cloud', 'other', 'vm', 'bm'];
}

/**
 * Valeurs de order_product.status qui rendent un service « visible ».
 *   active     → service en service
 *   suspended  → service suspendu (visible, mais signalé)
 *   deployment → service en cours de déploiement chez le fournisseur
 */
function servicesCatalogStatuses(): array
{
    return ['active', 'suspended', 'deployment'];
}

/**
 * Valeurs de order_product.status qui rendent un service ACCESSIBLE : page de
 * gestion ET endpoints data/. Sous-ensemble strict de
 * servicesCatalogStatuses().
 *
 * « suspended » en est volontairement absent. Un service suspendu reste
 * VISIBLE dans la barre latérale — le client doit voir ce qu'il a commandé —
 * mais il n'est plus ni cliquable ni pilotable. Les deux listes répondent donc
 * à deux questions différentes : « le montre-t-on ? » et « peut-on y toucher ? ».
 */
function servicesCatalogUsableStatuses(): array
{
    return ['active', 'deployment'];
}

/**
 * Ce service est-il pilotable, ou seulement visible ?
 *
 * C'est LE point de vérité partagé par pages/deployment.php, data/ptero_api.php
 * et data/k8s_api.php : un seul endroit à changer pour rouvrir ou fermer.
 */
function servicesCatalogEntryIsUsable(array $entry): bool
{
    $status = strtolower(trim((string)($entry['status'] ?? '')));

    return in_array($status, servicesCatalogUsableStatuses(), true);
}

/**
 * provider_type disposant d'une page de service dans le portail.
 *   kube  → API Kubernetes  (pages/deployment.php)
 *   ptero → API Pterodactyl (pages/deployment_ptero.php)
 */
function servicesCatalogLinkableProviders(): array
{
    return ['kube', 'ptero'];
}

/**
 * Extrait une liste de lignes d'une réponse n8n, quel que soit son emballage
 * (tableau brut, { data: [...] }, { json: {...} }, objet unique…).
 */
function servicesCatalogRows($json, array $containerKeys, array $idKeys): array
{
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
        if ($json === [] || array_key_exists(0, $json)) {
            return array_map($unwrap, array_values($json));
        }
        if (isset($json['json']) && is_array($json['json'])) {
            return [$json['json']];
        }
        foreach ($idKeys as $k) {
            if (isset($json[$k])) {
                return [$json];
            }
        }
    }

    return [];
}

/** Première valeur non vide parmi plusieurs clés candidates. */
function servicesCatalogValue(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = $row[$k];
        if (is_string($v) || is_numeric($v)) {
            $v = trim((string)$v);
            if ($v !== '') {
                return $v;
            }
        }
    }

    return $default;
}

/**
 * Un appel n8n en « échec doux » : la barre latérale doit s'afficher même si
 * l'une des requêtes tombe, plutôt que de faire échouer tout le menu.
 */
function servicesCatalogCall(array $payload, string $label, array $containerKeys, array $idKeys, array &$warnings): array
{
    try {
        $resp = portailApiCall($payload);
    } catch (Throwable $e) {
        $warnings[] = $label . ' : ' . $e->getMessage();
        return [];
    }

    $status = (int)($resp['status'] ?? 0);
    if ($status !== 0 && ($status < 200 || $status >= 300)) {
        $warnings[] = $label . ' : HTTP ' . $status;
        return [];
    }

    $rows = servicesCatalogRows($resp['json'] ?? null, $containerKeys, $idKeys);

    if (!$rows) {
        $body = $resp['json'] ?? null;
        $legitEmpty = ($body === null) || (is_array($body) && $body === []);
        if (!$legitEmpty) {
            $dump = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($dump)) {
                $dump = (string)($resp['raw'] ?? '');
            }
            $warnings[] = $label . ' : réponse inattendue de n8n — ' . mb_substr($dump, 0, 200);
        }
    }

    return $rows;
}

/**
 * Base d'URL de la page de service (env PORTAIL_DEPLOYMENT_URL sinon constante).
 *
 * Par défaut la base est relative (« /deployment ») : le navigateur la résout
 * contre l'origine de la page courante, donc le client reste sur le domaine par
 * lequel il est entré. Une valeur vide ou « / » retombe sur la constante.
 */
function servicesCatalogDeploymentUrl(): string
{
    $url = trim((string)getenv('PORTAIL_DEPLOYMENT_URL'));
    if ($url === '' || $url === '/') {
        $url = SERVICES_CATALOG_DEPLOYMENT_URL;
    }

    return rtrim($url, '?&');
}

/**
 * Construit la liste complète des services du client (sans cache).
 *
 * @return array{entries:array, orders:int, unmapped:array, warnings:array}
 */
function servicesCatalogBuild(int $clientId): array
{
    $warnings = [];

    // ── 1) order.list → références de commande ───────────────────────────────
    $orderRows = servicesCatalogCall(
        ['action' => 'order.list', 'client_id' => $clientId],
        'order.list',
        ['orders', 'commandes'],
        ['id', 'ref', 'reference'],
        $warnings
    );

    $refs = [];
    foreach ($orderRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ref = servicesCatalogValue($row, ['ref', 'reference', 'number', 'order_number']);
        if ($ref !== '' && !in_array($ref, $refs, true)) {
            $refs[] = $ref;
        }
    }
    if (count($refs) > SERVICES_CATALOG_MAX_ORDERS) {
        $warnings[] = 'order.list : ' . count($refs) . ' commandes — limité aux '
            . SERVICES_CATALOG_MAX_ORDERS . ' premières.';
        $refs = array_slice($refs, 0, SERVICES_CATALOG_MAX_ORDERS);
    }

    // ── 2) order.product → lignes actives / suspendues / en déploiement ──────
    $lines = [];
    foreach ($refs as $ref) {
        $productRows = servicesCatalogCall(
            ['action' => 'order.product', 'client_id' => $clientId, 'id' => '', 'ref' => $ref],
            'order.product (' . $ref . ')',
            ['order_product', 'products', 'produits', 'lignes', 'lines'],
            ['uid', 'slug'],
            $warnings
        );

        foreach ($productRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // n8n peut renvoyer les lignes de TOUTES les commandes : on ne garde
            // que celles de la référence demandée quand le champ est présent.
            $rowRef = servicesCatalogValue($row, ['ref', 'reference', 'order_ref']);
            if ($rowRef !== '' && strcasecmp($rowRef, $ref) !== 0) {
                continue;
            }

            $status = strtolower(servicesCatalogValue($row, ['status', 'statut', 'state']));
            if (!in_array($status, servicesCatalogStatuses(), true)) {
                continue;
            }

            $slug = servicesCatalogValue($row, ['slug', 'produit', 'product', 'code']);
            if ($slug === '') {
                continue;
            }

            $lines[] = [
                'slug'   => $slug,
                'uid'    => servicesCatalogValue($row, ['uid', 'product_uid', 'item_uid']),
                'ref'    => $rowRef !== '' ? $rowRef : $ref,
                'status' => $status,
                // Identifiant du service chez le fournisseur : nom du Deployment
                // pour kube, UUID (ou identifiant court) du serveur pour ptero.
                'provider_service_slug' => servicesCatalogValue($row, [
                    'provider_service_slug', 'service_slug', 'provider_slug',
                ]),
            ];
        }
    }

    // ── 3) product.list → catalogue (slug → nom, menu, provider_type) ────────
    $catalog = [];
    if ($lines !== []) {
        $catalogRows = servicesCatalogCall(
            ['action' => 'product.list', 'client_id' => $clientId],
            'product.list',
            ['products', 'produits', 'product', 'catalogue', 'catalog'],
            ['slug', 'id'],
            $warnings
        );

        foreach ($catalogRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = servicesCatalogValue($row, ['slug', 'code', 'product_slug']);
            if ($slug === '') {
                continue;
            }
            $catalog[$slug] = [
                'name'          => servicesCatalogValue($row, ['name', 'nom', 'label', 'libelle', 'titre'], $slug),
                'menu'          => strtolower(servicesCatalogValue($row, ['esp_cli_menu_name', 'menu', 'menu_name'])),
                'type'          => servicesCatalogValue($row, ['type']),
                'provider_type' => strtolower(servicesCatalogValue($row, ['provider_type'])),
            ];
        }

        if ($catalog === []) {
            $warnings[] = 'product.list : catalogue vide — aucun produit ne peut être rattaché à un menu.';
        }
    }

    // ── 4) deployment.list → renommages (label_portail V2) ───────────────────
    $renames = [];
    if ($lines !== []) {
        $renameRows = servicesCatalogCall(
            ['action' => 'deployment.list', 'client_id' => $clientId],
            'deployment.list',
            ['deployments', 'labels', 'label_portail'],
            ['product_uid', 'uid', 'deployment_name', 'name'],
            $warnings
        );

        foreach ($renameRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid  = servicesCatalogValue($row, ['product_uid', 'uid', 'deployment_name', 'name']);
            $disp = servicesCatalogValue($row, ['display_name', 'label']);
            if ($uid !== '' && $disp !== '') {
                $renames[$uid] = $disp;
            }
        }
    }

    // ── 5) Assemblage ────────────────────────────────────────────────────────
    $deploymentUrl = servicesCatalogDeploymentUrl();
    $menuKeys      = servicesCatalogMenus();
    $linkable      = servicesCatalogLinkableProviders();
    $usable        = servicesCatalogUsableStatuses();

    $entries  = [];
    $unmapped = [];
    $seenUids = [];

    foreach ($lines as $line) {
        $slug = $line['slug'];
        $meta = $catalog[$slug] ?? null;
        $menu = $meta['menu'] ?? '';

        if (!in_array($menu, $menuKeys, true)) {
            if (!in_array($slug, $unmapped, true)) {
                $unmapped[] = $slug;
            }
            continue;
        }

        // Une entrée = une ligne de commande. Dédoublonnage si n8n renvoie deux
        // fois le même uid.
        $uid = $line['uid'];
        if ($uid !== '') {
            if (isset($seenUids[$uid])) {
                continue;
            }
            $seenUids[$uid] = true;
        }

        $productName = ($meta['name'] ?? '') !== '' ? $meta['name'] : $slug;
        $displayName = ($uid !== '' && isset($renames[$uid])) ? $renames[$uid] : '';

        // Cliquable si le fournisseur a une page dans le portail, que la ligne
        // de commande porte l'identifiant du service chez ce fournisseur, ET
        // que le statut autorise l'accès — un service suspendu reste affiché,
        // mais sans lien : le clic ne mènerait qu'à un refus.
        $providerType = (string)($meta['provider_type'] ?? '');
        $serviceSlug  = $line['provider_service_slug'];
        $href         = '';
        if ($uid !== '' && $serviceSlug !== '' && in_array($providerType, $linkable, true)
            && in_array(strtolower(trim((string)$line['status'])), $usable, true)) {
            // C'est la page deployment qui vérifie les droits : on ne lui passe
            // que l'uid de la ligne de commande, jamais l'identifiant technique.
            $href = $deploymentUrl . '?product_uid=' . rawurlencode($uid);
        }

        $entries[] = [
            'menu'         => $menu,
            'uid'          => $uid,
            'slug'         => $slug,
            // « name » = ce qu'il faut afficher ; « product_name » = le libellé
            // catalogue d'origine, conservé pour l'infobulle et le modal.
            'name'         => $displayName !== '' ? $displayName : $productName,
            'product_name' => $productName,
            'display_name' => $displayName,
            'type'         => $meta['type'] ?? '',
            'status'       => $line['status'],
            'ref'          => $line['ref'],
            'provider_type'         => $providerType,
            'provider_service_slug' => $serviceSlug,
            'href'                  => $href,   // '' ⇒ entrée non cliquable
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        $byName = strcasecmp((string)$a['name'], (string)$b['name']);
        return $byName !== 0 ? $byName : strcmp((string)$a['uid'], (string)$b['uid']);
    });

    return [
        'entries'  => $entries,
        'orders'   => count($refs),
        'unmapped' => $unmapped,
        'warnings' => array_values($warnings),
    ];
}

/**
 * Liste des services du client, mise en cache en session.
 *
 * @return array{entries:array, orders:int, unmapped:array, warnings:array, cached:bool}
 */
function servicesCatalogFetch(int $clientId, bool $force = false): array
{
    $cache = $_SESSION[SERVICES_CATALOG_CACHE_KEY] ?? null;

    if (
        !$force
        && is_array($cache)
        && (int)($cache['client_id'] ?? 0) === $clientId
        && (time() - (int)($cache['at'] ?? 0)) < SERVICES_CATALOG_TTL
        && is_array($cache['payload'] ?? null)
    ) {
        return array_merge($cache['payload'], ['cached' => true]);
    }

    $payload = servicesCatalogBuild($clientId);

    $_SESSION[SERVICES_CATALOG_CACHE_KEY] = [
        'client_id' => $clientId,
        'at'        => time(),
        'payload'   => $payload,
    ];

    return array_merge($payload, ['cached' => false]);
}

/**
 * Retrouve UN service par son order_product.uid.
 *
 * Renvoie null si l'uid n'appartient pas au client connecté : c'est LE contrôle
 * d'accès des pages de service. En cas d'absence, un second essai est fait sans
 * cache — une commande toute récente ne doit pas renvoyer un 403 pendant 2 min.
 */
function servicesCatalogFindByUid(int $clientId, string $uid): ?array
{
    $uid = trim($uid);
    if ($uid === '') {
        return null;
    }

    foreach ([false, true] as $force) {
        $data = servicesCatalogFetch($clientId, $force);
        foreach ($data['entries'] as $entry) {
            if (hash_equals((string)$entry['uid'], $uid)) {
                return $entry;
            }
        }
        if ($force) {
            break;
        }
        // Rien trouvé dans le cache : on ne retente à froid que s'il servait.
        if (empty($data['cached'])) {
            break;
        }
    }

    return null;
}

/**
 * Retrouve UN service par l'identifiant qu'il porte chez son fournisseur
 * (provider_service_slug), pour un provider_type donné.
 *
 * Pendant de servicesCatalogFindByUid() pour les points d'entrée qui ne
 * connaissent que le nom technique : data/k8s_api.php, qui travaille sur un nom
 * de Deployment, et l'accès direct à /deployment?deployment=nom. Même repli à
 * froid : une commande toute récente ne doit pas être invisible 2 minutes.
 */
function servicesCatalogFindByProviderSlug(int $clientId, string $providerType, string $slug): ?array
{
    $slug         = strtolower(trim($slug));
    $providerType = strtolower(trim($providerType));
    if ($slug === '' || $providerType === '') {
        return null;
    }

    foreach ([false, true] as $force) {
        $data = servicesCatalogFetch($clientId, $force);
        foreach ($data['entries'] as $entry) {
            if (strtolower(trim((string)($entry['provider_type'] ?? ''))) !== $providerType) {
                continue;
            }
            if (strtolower(trim((string)($entry['provider_service_slug'] ?? ''))) === $slug) {
                return $entry;
            }
        }
        if ($force || empty($data['cached'])) {
            break;
        }
    }

    return null;
}

/** Vide le cache (appelé après un renommage). */
function servicesCatalogInvalidate(): void
{
    unset($_SESSION[SERVICES_CATALOG_CACHE_KEY], $_SESSION[SERVICES_CATALOG_ALL_CACHE_KEY]);
}

/* ═══════════════════════════════════════════════════════════════════════════
 *  PORTAIL GESTION — SERVICES DE TOUS LES CLIENTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Côté portail client, n8n limite chaque appel à l'organisation de la session
 * (clé « organization_uid » injectée par portailApiCall). Le portail gestion
 * n'a pas d'organisation à lui : on parcourt donc TOUTES les organisations du
 * realm espace client (kcEspOrganizations, même source que /entreprises) et on
 * rejoue la même chaîne n8n pour chacune, avec son organization_uid :
 *
 *   product.list    → 1 appel (catalogue commun)
 *   order.list      → 1 appel par organisation            ┐ en parallèle
 *   deployment.list → 1 appel par organisation (renommages)│ (curl_multi,
 *   order.product   → 1 appel par commande                 ┘  lots de N)
 *
 * Chaque entrée porte en plus « client_name » et « organization_uid » : le menu
 * regroupe les services par entreprise.
 *
 * « $onlyOrg » (UUID) restreint à une organisation : raccourci ?org= de
 * /entreprises.
 */

/** Durée de vie du cache « tous clients » (secondes) : beaucoup d'appels n8n. */
if (!defined('SERVICES_CATALOG_ALL_TTL')) {
    define('SERVICES_CATALOG_ALL_TTL', 300);
}

/** Appels n8n simultanés au maximum. */
if (!defined('SERVICES_CATALOG_ALL_CONCURRENCY')) {
    define('SERVICES_CATALOG_ALL_CONCURRENCY', 8);
}

/** Garde-fou : commandes interrogées au total, toutes organisations confondues. */
if (!defined('SERVICES_CATALOG_ALL_MAX_ORDERS')) {
    define('SERVICES_CATALOG_ALL_MAX_ORDERS', 400);
}

if (!defined('SERVICES_CATALOG_ALL_CACHE_KEY')) {
    define('SERVICES_CATALOG_ALL_CACHE_KEY', 'services_catalog_all_cache');
}

/**
 * Lignes exploitables d'une réponse n8n déjà reçue (même contrôle que
 * servicesCatalogCall(), sans refaire l'appel).
 */
function servicesCatalogParse(array $resp, string $label, array $containerKeys, array $idKeys, array &$warnings): array
{
    if (($resp['error'] ?? '') !== '') {
        $warnings[] = $label . ' : ' . $resp['error'];
        return [];
    }

    $status = (int)($resp['status'] ?? 0);
    if ($status !== 0 && ($status < 200 || $status >= 300)) {
        $warnings[] = $label . ' : HTTP ' . $status;
        return [];
    }

    $rows = servicesCatalogRows($resp['json'] ?? null, $containerKeys, $idKeys);

    if (!$rows) {
        $body = $resp['json'] ?? null;
        $legitEmpty = ($body === null) || (is_array($body) && $body === [])
            || (is_array($body) && ($body['ok'] ?? null) === true);
        // Conteneur attendu présent mais vide ({"orders": []}) : liste vide normale.
        foreach ($containerKeys as $ck) {
            if (is_array($body) && isset($body[$ck]) && $body[$ck] === []) {
                $legitEmpty = true;
            }
        }
        if (!$legitEmpty) {
            $dump = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($dump)) {
                $dump = (string)($resp['raw'] ?? '');
            }
            $warnings[] = $label . ' : réponse inattendue de n8n — ' . mb_substr($dump, 0, 200);
        }
    }

    return $rows;
}

/**
 * Plusieurs appels au webhook n8n en parallèle (curl_multi), par lots.
 * Même forme de réponse que portailApiCall() + clé « error » ('' si OK).
 * Sans curl : repli séquentiel sur portailApiCall().
 *
 * @param array<string,array> $payloads clé libre → payload
 * @return array<string,array>          même clé → réponse
 */
function servicesCatalogMultiCall(array $payloads, int $timeout = 15): array
{
    $out = [];
    if ($payloads === []) {
        return $out;
    }

    if (!function_exists('curl_multi_init')) {
        foreach ($payloads as $key => $payload) {
            try {
                $out[$key] = portailApiCall($payload, $timeout) + ['error' => ''];
            } catch (Throwable $e) {
                $out[$key] = ['status' => 0, 'json' => null, 'raw' => '', 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    $url     = portailApiUrl();
    $token   = portailApiEnvNonEmpty('N8N_WEBHOOK_TOKEN');
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-GNL-Token: ' . $token;
    }

    foreach (array_chunk($payloads, max(1, SERVICES_CATALOG_ALL_CONCURRENCY), true) as $batch) {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batch as $key => $payload) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $key => $ch) {
            $raw   = (string)curl_multi_getcontent($ch);
            $errno = curl_errno($ch);
            $out[$key] = [
                'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'json'   => json_decode($raw, true),
                'raw'    => $raw,
                'error'  => $errno !== 0 ? 'Connexion n8n impossible : ' . curl_error($ch) : '',
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    return $out;
}

/**
 * Construit la liste des services de TOUS les clients (sans cache).
 *
 * @return array{entries:array, orders:int, clients:int, unmapped:array, warnings:array}
 */
function servicesCatalogBuildAll(string $onlyOrg = ''): array
{
    require_once __DIR__ . '/keycloak_esp_client.php';

    $warnings = [];

    // ── 0) Organisations (clients) ───────────────────────────────────────────
    $fetched = kcEspOrganizations();
    if (!$fetched['ok']) {
        throw new RuntimeException('Organisations Keycloak indisponibles : '
            . ($fetched['error'] !== '' ? $fetched['error'] : 'erreur inconnue'));
    }
    if ($fetched['truncated']) {
        $warnings[] = 'Keycloak : liste des organisations tronquée (plafond atteint).';
    }

    $orgs = [];
    foreach ($fetched['orgs'] as $org) {
        $id = (string)($org['id'] ?? '');
        if ($id === '' || ($onlyOrg !== '' && strcasecmp($id, $onlyOrg) !== 0)) {
            continue;
        }
        $label = (string)($org['label'] ?? '');
        $orgs[$id] = $label !== '' ? $label : ((string)($org['name'] ?? '') ?: $id);
    }
    if ($orgs === []) {
        return ['entries' => [], 'orders' => 0, 'clients' => 0, 'unmapped' => [], 'warnings' => $warnings];
    }

    // ── 1) order.list par organisation + product.list + deployment.list ──────
    $calls = [];
    foreach ($orgs as $orgId => $_) {
        $calls['orders|' . $orgId] = ['action' => 'order.list', 'organization_uid' => $orgId];
        $calls['labels|' . $orgId] = ['action' => 'deployment.list', 'organization_uid' => $orgId];
    }
    // Catalogue commun : demandé dans le contexte de la 1re organisation
    // (un workflow qui exigerait un organization_uid ne refusera pas l'appel).
    $calls['catalog'] = ['action' => 'product.list', 'organization_uid' => array_key_first($orgs)];

    $resp = servicesCatalogMultiCall($calls);

    $refsByOrg = [];
    $nbOrders  = 0;
    foreach ($orgs as $orgId => $orgLabel) {
        $rows = servicesCatalogParse(
            $resp['orders|' . $orgId] ?? [],
            'order.list (' . $orgLabel . ')',
            ['orders', 'commandes'],
            ['id', 'ref', 'reference'],
            $warnings
        );
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = servicesCatalogValue($row, ['ref', 'reference', 'number', 'order_number']);
            if ($ref === '' || in_array($ref, $refsByOrg[$orgId] ?? [], true)) {
                continue;
            }
            if ($nbOrders >= SERVICES_CATALOG_ALL_MAX_ORDERS) {
                $warnings[] = 'order.list : plus de ' . SERVICES_CATALOG_ALL_MAX_ORDERS
                    . ' commandes au total — liste limitée.';
                break 2;
            }
            $refsByOrg[$orgId][] = $ref;
            $nbOrders++;
        }
    }

    // Catalogue : slug → nom, menu, provider_type.
    $catalog = [];
    foreach (servicesCatalogParse($resp['catalog'] ?? [], 'product.list',
        ['products', 'produits', 'product', 'catalogue', 'catalog'], ['slug', 'id'], $warnings) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = servicesCatalogValue($row, ['slug', 'code', 'product_slug']);
        if ($slug === '') {
            continue;
        }
        $catalog[$slug] = [
            'name'          => servicesCatalogValue($row, ['name', 'nom', 'label', 'libelle', 'titre'], $slug),
            'menu'          => strtolower(servicesCatalogValue($row, ['esp_cli_menu_name', 'menu', 'menu_name'])),
            'type'          => servicesCatalogValue($row, ['type']),
            'provider_type' => strtolower(servicesCatalogValue($row, ['provider_type'])),
        ];
    }
    if ($catalog === [] && $nbOrders > 0) {
        $warnings[] = 'product.list : catalogue vide — aucun produit ne peut être rattaché à un menu.';
    }

    // Renommages (label_portail) : product_uid → display_name, par organisation.
    $renames = [];
    foreach ($orgs as $orgId => $orgLabel) {
        $rows = servicesCatalogParse(
            $resp['labels|' . $orgId] ?? [],
            'deployment.list (' . $orgLabel . ')',
            ['deployments', 'labels', 'label_portail'],
            ['product_uid', 'uid', 'deployment_name', 'name'],
            $warnings
        );
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid  = servicesCatalogValue($row, ['product_uid', 'uid', 'deployment_name', 'name']);
            $disp = servicesCatalogValue($row, ['display_name', 'label']);
            if ($uid !== '' && $disp !== '') {
                $renames[$orgId][$uid] = $disp;
            }
        }
    }

    // ── 2) order.product par commande ────────────────────────────────────────
    $calls = [];
    foreach ($refsByOrg as $orgId => $refs) {
        foreach ($refs as $ref) {
            $calls['lines|' . $orgId . '|' . $ref] = [
                'action' => 'order.product', 'organization_uid' => $orgId, 'id' => '', 'ref' => $ref,
            ];
        }
    }
    $resp = servicesCatalogMultiCall($calls);

    $menuKeys = servicesCatalogMenus();
    $entries  = [];
    $unmapped = [];
    $seenUids = [];

    foreach ($refsByOrg as $orgId => $refs) {
        foreach ($refs as $ref) {
            $rows = servicesCatalogParse(
                $resp['lines|' . $orgId . '|' . $ref] ?? [],
                'order.product (' . $orgs[$orgId] . ' / ' . $ref . ')',
                ['order_product', 'products', 'produits', 'lignes', 'lines'],
                ['uid', 'slug'],
                $warnings
            );

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowRef = servicesCatalogValue($row, ['ref', 'reference', 'order_ref']);
                if ($rowRef !== '' && strcasecmp($rowRef, $ref) !== 0) {
                    continue;
                }
                $status = strtolower(servicesCatalogValue($row, ['status', 'statut', 'state']));
                if (!in_array($status, servicesCatalogStatuses(), true)) {
                    continue;
                }
                $slug = servicesCatalogValue($row, ['slug', 'produit', 'product', 'code']);
                if ($slug === '') {
                    continue;
                }

                $meta = $catalog[$slug] ?? null;
                $menu = $meta['menu'] ?? '';
                if (!in_array($menu, $menuKeys, true)) {
                    if (!in_array($slug, $unmapped, true)) {
                        $unmapped[] = $slug;
                    }
                    continue;
                }

                $uid = servicesCatalogValue($row, ['uid', 'product_uid', 'item_uid']);
                if ($uid !== '') {
                    if (isset($seenUids[$uid])) {
                        continue;
                    }
                    $seenUids[$uid] = true;
                }

                $productName = ($meta['name'] ?? '') !== '' ? $meta['name'] : $slug;
                $displayName = ($uid !== '' && isset($renames[$orgId][$uid])) ? $renames[$orgId][$uid] : '';

                $entries[] = [
                    'menu'                  => $menu,
                    'uid'                   => $uid,
                    'slug'                  => $slug,
                    'name'                  => $displayName !== '' ? $displayName : $productName,
                    'product_name'          => $productName,
                    'display_name'          => $displayName,
                    'type'                  => $meta['type'] ?? '',
                    'status'                => $status,
                    'ref'                   => $rowRef !== '' ? $rowRef : $ref,
                    'provider_type'         => (string)($meta['provider_type'] ?? ''),
                    'provider_service_slug' => servicesCatalogValue($row, [
                        'provider_service_slug', 'service_slug', 'provider_slug',
                    ]),
                    // pages/deployment.php (gestion) ne résout pas ?product_uid= :
                    // entrée non cliquable.
                    'href'                  => '',
                    'organization_uid'      => $orgId,
                    'client_name'           => $orgs[$orgId],
                ];
            }
        }
    }

    // Tri : entreprise, puis nom du service.
    usort($entries, static function (array $a, array $b): int {
        return strcasecmp((string)$a['client_name'], (string)$b['client_name'])
            ?: strcasecmp((string)$a['name'], (string)$b['name'])
            ?: strcmp((string)$a['uid'], (string)$b['uid']);
    });

    return [
        'entries'  => $entries,
        'orders'   => $nbOrders,
        'clients'  => count($orgs),
        'unmapped' => $unmapped,
        'warnings' => array_values($warnings),
    ];
}

/** Services de tous les clients, mis en cache en session (SERVICES_CATALOG_ALL_TTL). */
function servicesCatalogFetchAll(bool $force = false, string $onlyOrg = ''): array
{
    $cache = $_SESSION[SERVICES_CATALOG_ALL_CACHE_KEY] ?? null;
    $scope = $onlyOrg !== '' ? strtolower($onlyOrg) : '*';

    if (
        !$force
        && is_array($cache)
        && ($cache['scope'] ?? null) === $scope
        && (time() - (int)($cache['at'] ?? 0)) < SERVICES_CATALOG_ALL_TTL
        && is_array($cache['payload'] ?? null)
    ) {
        return array_merge($cache['payload'], ['cached' => true]);
    }

    $payload = servicesCatalogBuildAll($onlyOrg);

    $_SESSION[SERVICES_CATALOG_ALL_CACHE_KEY] = [
        'scope'   => $scope,
        'at'      => time(),
        'payload' => $payload,
    ];

    return array_merge($payload, ['cached' => false]);
}
