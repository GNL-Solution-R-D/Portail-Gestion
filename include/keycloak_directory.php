<?php
/* =====================================================================
   GNL Solution — Annuaire Keycloak du PORTAIL GESTION
   (include/keycloak_directory.php)
   ---------------------------------------------------------------------
   Équivalent, SANS organisations, de include/keycloak_organizations.php
   de l'espace client : alimente la page /equipes en lisant l'Admin REST
   API de Keycloak.

        GET /admin/realms/{realm}/users?first=…&max=…&briefRepresentation=false

   Le portail gestion n'utilise pas la fonctionnalité « Organizations » :
   la page liste donc TOUS les comptes du realm, triés par nom.
   Keycloak est la source de vérité — aucune écriture ici.

   -------------------------- Pré-requis Keycloak ----------------------
   Appel en grant client_credentials avec le client OIDC du portail :
       KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET
   Sur ce client, il faut :
       - « Client authentication »  = ON
       - « Service accounts roles » = ON
       - rôle realm-management « view-users » affecté au compte de service
   Sans ce rôle, l'API répond 403 et la page affiche un message explicite
   plutôt qu'une liste vide.

   Réglages optionnels :
       KEYCLOAK_USERS_MAX      nombre maximum de comptes remontés.
                               Défaut : 500 (plafond dur : 2000).
       KEYCLOAK_DIR_DEBUG=1    journalise chaque appel Admin REST réussi.

   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../config_loader.php';   // config()
require_once __DIR__ . '/keycloak_auth.php';      // keycloakHttpRequest(), keycloakGetIssuer(), …

/** Garde-fou contre un realm énorme. */
if (!defined('KC_DIR_USERS_HARD_LIMIT')) {
    define('KC_DIR_USERS_HARD_LIMIT', 2000);
}
/** Taille de page de l'Admin REST (l'API plafonne de toute façon). */
if (!defined('KC_DIR_PAGE_SIZE')) {
    define('KC_DIR_PAGE_SIZE', 100);
}

/* ==================== Compte de service / Admin REST ==================== */

if (!function_exists('kcDirAdminBase')) {
    /** Déduit la base Admin REST de l'issuer (https://host/auth/admin/realms/<realm>). */
    function kcDirAdminBase(): string
    {
        $iss = rtrim(keycloakGetIssuer(), '/');
        $pos = strpos($iss, '/realms/');
        if ($pos === false) return $iss . '/admin';
        $server = substr($iss, 0, $pos);                              // https://host/auth
        $realm  = trim(substr($iss, $pos + strlen('/realms/')), '/');  // nom du realm
        return $server . '/admin/realms/' . rawurlencode($realm);
    }
}

if (!function_exists('kcDirAdminToken')) {
    /**
     * Jeton client_credentials du client du portail, mis en cache le temps de
     * la requête PHP. Renvoie null si le grant échoue — l'appelant produit
     * alors un message exploitable ; le détail part dans les logs.
     */
    function kcDirAdminToken(): ?string
    {
        static $tok = null, $exp = 0;
        if ($tok !== null && time() < $exp - 15) return $tok;

        $clientId     = keycloakGetClientId();
        $clientSecret = keycloakGetClientSecret();
        if ($clientId === '' || $clientSecret === '') {
            error_log('[GNL KC-DIR] KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET manquant.');
            return null;
        }

        try {
            $resp = keycloakHttpRequest(
                keycloakGetIssuer() . '/protocol/openid-connect/token',
                [
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                    ], '', '&', PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                ]
            );
        } catch (Throwable $e) {
            error_log('[GNL KC-DIR] token client_credentials — erreur réseau : ' . $e->getMessage());
            return null;
        }

        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (empty($body['access_token'])) {
            error_log('[GNL KC-DIR] token client_credentials refusé : HTTP ' . (int) ($resp['status'] ?? 0)
                . ' ' . (string) ($body['error'] ?? '') . ' ' . (string) ($body['error_description'] ?? '')
                . ' — vérifiez que « Service accounts roles » est activé sur le client ' . $clientId . '.');
            return null;
        }
        $tok = (string) $body['access_token'];
        $exp = time() + (isset($body['expires_in']) ? (int) $body['expires_in'] : 60);
        return $tok;
    }
}

/**
 * GET sur l'Admin REST API. Ne lève jamais : renvoie toujours
 * { status:int, body:array, error:string } — error non vide = échec exploitable.
 */
if (!function_exists('kcDirAdminGet')) {
    function kcDirAdminGet(string $path, array $query = []): array
    {
        $bearer = kcDirAdminToken();
        if ($bearer === null) {
            return [
                'status' => 0,
                'body'   => [],
                'error'  => "Keycloak n'a pas délivré de jeton de service : serveur injoignable, KEYCLOAK_CLIENT_ID / KEYCLOAK_CLIENT_SECRET invalides, ou « Service accounts roles » désactivé sur le client (le détail est dans les logs du portail).",
            ];
        }

        $url = kcDirAdminBase() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $resp = keycloakHttpRequest($url, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearer],
            ]);
        } catch (Throwable $e) {
            error_log('[GNL KC-DIR] GET ' . $path . ' : ' . $e->getMessage());
            return ['status' => 0, 'body' => [], 'error' => 'Keycloak est injoignable.'];
        }

        $status = (int) ($resp['status'] ?? 0);
        $body   = (isset($resp['body']) && is_array($resp['body'])) ? $resp['body'] : [];

        if ($status >= 200 && $status < 300) {
            if ((string) config('KEYCLOAK_DIR_DEBUG', '0') === '1') {
                error_log('[GNL KC-DIR] GET ' . $path . ' → HTTP ' . $status . ' (' . count($body) . ' élément(s))');
            }
            return ['status' => $status, 'body' => $body, 'error' => ''];
        }

        $error = 'Keycloak a renvoyé HTTP ' . $status . ' sur ' . $path;
        if ($status === 403) {
            $error .= " — le compte de service n'a pas le rôle « view-users » (realm-management).";
        } elseif ($status === 404) {
            $error .= ' — ressource introuvable (vérifiez KEYCLOAK_ISSUER et le nom du realm).';
        } elseif (!empty($body['errorMessage'])) {
            $error .= ' — ' . (string) $body['errorMessage'];
        }
        error_log('[GNL KC-DIR] GET ' . $path . ' → ' . $error);

        return ['status' => $status, 'body' => $body, 'error' => $error];
    }
}

/* ============================ Listing des comptes ====================== */

if (!function_exists('kcDirUsersMax')) {
    /** Plafond effectif de comptes remontés (borné par KC_DIR_USERS_HARD_LIMIT). */
    function kcDirUsersMax(): int
    {
        $max = (int) config('KEYCLOAK_USERS_MAX', 500);
        if ($max <= 0) $max = 500;
        return min($max, KC_DIR_USERS_HARD_LIMIT);
    }
}

/**
 * Tous les comptes du realm, paginés jusqu'au plafond.
 *
 * @return array{ok:bool, members:array<int,array>, truncated:bool, error:string}
 */
if (!function_exists('kcDirUsers')) {
    function kcDirUsers(): array
    {
        $max       = kcDirUsersMax();
        $out       = [];
        $first     = 0;
        $truncated = false;

        while (count($out) < $max) {
            $want = min(KC_DIR_PAGE_SIZE, $max - count($out));

            $resp = kcDirAdminGet('/users', [
                'first'               => $first,
                'max'                 => $want,
                'briefRepresentation' => 'false', // on veut les attributs (fonction, …)
            ]);
            if ($resp['error'] !== '') {
                // Une PREMIÈRE page en échec = échec exploitable ; une page
                // suivante en échec = on garde ce qu'on a déjà, marqué tronqué.
                if ($out === []) {
                    return ['ok' => false, 'members' => [], 'truncated' => false, 'error' => $resp['error']];
                }
                $truncated = true;
                break;
            }

            $page = is_array($resp['body']) ? array_values($resp['body']) : [];
            foreach ($page as $row) {
                if (is_array($row)) $out[] = $row;
            }

            if (count($page) < $want) {
                break; // dernière page
            }
            $first += $want;
        }

        if (count($out) >= $max) {
            // Le realm contient peut-être davantage de comptes : on le signale.
            $truncated = true;
        }

        return ['ok' => true, 'members' => $out, 'truncated' => $truncated, 'error' => ''];
    }
}
