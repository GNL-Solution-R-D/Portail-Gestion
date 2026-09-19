<?php
/* =====================================================================
   GNL Solution — ORGANISATIONS de l'ESPACE CLIENT, vues depuis le
   PORTAIL GESTION  (include/keycloak_esp_client.php)
   ---------------------------------------------------------------------
   Alimente la page /entreprises : liste les ORGANISATIONS (fonctionnalité
   « Organizations » de Keycloak >= 26) du realm de l'espace client, lues
   via l'Admin REST API.

        GET /admin/realms/{realm}/organizations?first=…&max=…
        GET /admin/realms/{realm}/organizations/{id}
        GET /admin/realms/{realm}/organizations/{id}/members

   ⚠️ CE FICHIER N'UTILISE PAS LE CLIENT KEYCLOAK DU PORTAIL GESTION.
   Le portail gestion s'authentifie sur SON realm (KEYCLOAK_ISSUER,
   KEYCLOAK_CLIENT_ID) ; les entreprises, elles, vivent dans le realm de
   l'ESPACE CLIENT. On parle donc à un second realm, avec son propre jeu
   de variables :

       KEYCLOAK_ESP-CLI_ISSUER                     realm interrogé
       KEYCLOAK_ESP-CLI_CLIENT_ID                  client confidentiel
       KEYCLOAK_ESP-CLI_CLIENT_SECRET              son secret
       KEYCLOAK_ESP-CLI_DEBUG_CLAIMS=1             journalise les appels
       KEYCLOAK_ESP-CLI_REDIRECT_URI               (flow « code », non
       KEYCLOAK_ESP-CLI_POST_LOGOUT_REDIRECT_URI    utilisé ici — exposé
                                                    pour complétude)

   Les tirets des noms de variables sont volontaires : ce sont les clés du
   Secret Kubernetes, lues telles quelles par config() → getenv().

   ⚠️ Les deux DERNIÈRES variables ne servent à RIEN sur cette page : la
   lecture se fait en grant « client_credentials », sans redirection ni
   navigateur. Elles sont déclarées ici uniquement pour que le jour où
   quelqu'un ajoute une connexion OIDC vers ce second realm, il n'invente
   pas de nouveaux noms. Ne les branchez pas dans un flux de lecture.

   -------------------------- Pré-requis Keycloak ----------------------
   Sur le client KEYCLOAK_ESP-CLI_CLIENT_ID, dans le realm de l'espace
   client :
       - « Client authentication »  = ON
       - « Service accounts roles » = ON (active le client_credentials)
       - rôles realm-management affectés au compte de service :
             · view-organizations  (lister les organisations et leurs membres)
             · view-users          (lire les comptes membres)
   Sans ces rôles, l'API répond 403 et la page affiche le message
   correspondant au lieu d'une liste vide.

   Réglages optionnels :
       KEYCLOAK_ESP-CLI_ORGS_MAX     nombre maximum d'organisations
                                     remontées. Défaut : 500 (dur : 2000).
       KEYCLOAK_ESP-CLI_MEMBERS_MAX  membres par organisation.
                                     Défaut : 200 (dur : 2000).

   LECTURE SEULE : aucune écriture vers Keycloak depuis ce fichier.
   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../config_loader.php';   // config()
require_once __DIR__ . '/keycloak_auth.php';      // keycloakHttpRequest()

/** Garde-fou contre un realm énorme. */
if (!defined('KC_ESP_HARD_LIMIT')) {
    define('KC_ESP_HARD_LIMIT', 2000);
}
/** Taille de page de l'Admin REST. */
if (!defined('KC_ESP_PAGE_SIZE')) {
    define('KC_ESP_PAGE_SIZE', 100);
}

/* ======================== Configuration ESP-CLI ======================== */

/**
 * Lit une variable ESP-CLI en tolérant PLUSIEURS orthographes du préfixe.
 *
 * ⚠️ Pourquoi : les clés du Secret Kubernetes s'écrivent « KEYCLOAK_ESP-CLI_… »,
 *    avec un tiret. Or un nom de variable d'environnement doit être un
 *    identifiant C — [A-Za-z_][A-Za-z0-9_]* — et le tiret n'en fait pas
 *    partie. Conséquence : avec « envFrom: secretRef », le kubelet IGNORE
 *    silencieusement ces clés (événement « InvalidVariableNames » sur le pod)
 *    et avec « env[].name » l'API server refuse carrément le manifeste. La
 *    variable n'atteint donc JAMAIS PHP, alors que le Secret paraît correct
 *    dans l'interface.
 *
 * On accepte donc, dans l'ordre : le nom avec tiret (cas où il arrive quand
 * même — fichier .env local, Secret monté en volume), puis la variante en
 * underscore, puis la variante sans séparateur. Ajoutez la clé en underscore
 * dans le Secret et tout fonctionne sans retoucher ce fichier.
 *
 * @return array{0:string,1:string} [valeur, nom réellement trouvé]
 */
if (!function_exists('kcEspEnv')) {
    function kcEspEnv(string $suffix, string $default = ''): array
    {
        foreach (kcEspEnvNames($suffix) as $name) {
            $v = config($name, null);
            if ($v !== null && trim((string) $v) !== '') {
                return [trim((string) $v), $name];
            }
        }
        return [$default, ''];
    }
}

/** Les noms acceptés pour un suffixe donné, dans l'ordre d'essai. */
if (!function_exists('kcEspEnvNames')) {
    function kcEspEnvNames(string $suffix): array
    {
        return [
            'KEYCLOAK_ESP-CLI_' . $suffix,   // clé du Secret (tiret)
            'KEYCLOAK_ESP_CLI_' . $suffix,   // variante injectable en env
            'KEYCLOAK_ESPCLI_'  . $suffix,   // variante sans séparateur
        ];
    }
}

if (!function_exists('kcEspIssuer')) {
    function kcEspIssuer(): string
    {
        [$v] = kcEspEnv('ISSUER', 'https://auth.gnl-solution.fr/auth/realms/client-auth');
        return rtrim($v, '/');
    }
}
if (!function_exists('kcEspClientId')) {
    function kcEspClientId(): string
    {
        [$v] = kcEspEnv('CLIENT_ID');
        return $v;
    }
}
if (!function_exists('kcEspClientSecret')) {
    function kcEspClientSecret(): string
    {
        [$v] = kcEspEnv('CLIENT_SECRET');
        return $v;
    }
}
if (!function_exists('kcEspRedirectUri')) {
    /** Non utilisée par la lecture (client_credentials) : exposée pour un futur flow « code ». */
    function kcEspRedirectUri(): string
    {
        [$v] = kcEspEnv('REDIRECT_URI');
        return $v;
    }
}
if (!function_exists('kcEspPostLogoutRedirectUri')) {
    /** Idem : réservée à un futur flow « code ». */
    function kcEspPostLogoutRedirectUri(): string
    {
        [$v] = kcEspEnv('POST_LOGOUT_REDIRECT_URI');
        return $v;
    }
}
if (!function_exists('kcEspDebug')) {
    function kcEspDebug(): bool
    {
        [$v] = kcEspEnv('DEBUG_CLAIMS', '0');
        return $v === '1';
    }
}
if (!function_exists('kcEspOrgsMax')) {
    function kcEspOrgsMax(): int
    {
        [$v] = kcEspEnv('ORGS_MAX', '500');
        $max = (int) $v;
        if ($max <= 0) $max = 500;
        return min($max, KC_ESP_HARD_LIMIT);
    }
}
if (!function_exists('kcEspMembersMax')) {
    function kcEspMembersMax(): int
    {
        [$v] = kcEspEnv('MEMBERS_MAX', '200');
        $max = (int) $v;
        if ($max <= 0) $max = 200;
        return min($max, KC_ESP_HARD_LIMIT);
    }
}

/**
 * Diagnostic de configuration : '' si tout est lisible, sinon un message qui
 * NOMME les variables cherchées. Sans lui, une variable absente et un secret
 * erroné produisent exactement la même erreur, et on cherche du mauvais côté.
 */
if (!function_exists('kcEspConfigProblem')) {
    function kcEspConfigProblem(): string
    {
        $missing = [];
        foreach (['CLIENT_ID', 'CLIENT_SECRET'] as $suffix) {
            [$value] = kcEspEnv($suffix);
            if ($value === '') {
                $missing[] = implode(' ou ', kcEspEnvNames($suffix));
            }
        }
        if ($missing === []) {
            return '';
        }
        return "Configuration absente de l'environnement du portail : "
            . implode(' ; ', $missing)
            . ". Rappel : un nom de variable d'environnement ne peut pas contenir de tiret — "
            . "Kubernetes ignore les clés « KEYCLOAK_ESP-CLI_… » lors d'un « envFrom: secretRef ». "
            . "Ajoutez les mêmes valeurs sous « KEYCLOAK_ESP_CLI_CLIENT_ID » et "
            . "« KEYCLOAK_ESP_CLI_CLIENT_SECRET » dans le Secret, puis redémarrez le pod.";
    }
}

/* ==================== Compte de service / Admin REST ==================== */

if (!function_exists('kcEspAdminBase')) {
    /** Déduit la base Admin REST de l'issuer (https://host/auth/admin/realms/<realm>). */
    function kcEspAdminBase(): string
    {
        $iss = kcEspIssuer();
        $pos = strpos($iss, '/realms/');
        if ($pos === false) return $iss . '/admin';
        $server = substr($iss, 0, $pos);                              // https://host/auth
        $realm  = trim(substr($iss, $pos + strlen('/realms/')), '/');  // client-auth
        return $server . '/admin/realms/' . rawurlencode($realm);
    }
}

if (!function_exists('kcEspAdminToken')) {
    /**
     * Jeton client_credentials du client ESP-CLI, mis en cache le temps de la
     * requête PHP. Renvoie null si le grant échoue — l'appelant produit alors
     * un message exploitable ; le détail part dans les logs.
     */
    function kcEspAdminToken(): ?string
    {
        static $tok = null, $exp = 0;
        if ($tok !== null && time() < $exp - 15) return $tok;

        $problem = kcEspConfigProblem();
        if ($problem !== '') {
            error_log('[GNL KC-ESP] ' . $problem);
            return null;
        }

        $clientId     = kcEspClientId();
        $clientSecret = kcEspClientSecret();

        try {
            $resp = keycloakHttpRequest(
                kcEspIssuer() . '/protocol/openid-connect/token',
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
            error_log('[GNL KC-ESP] token client_credentials — erreur réseau : ' . $e->getMessage());
            return null;
        }

        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (empty($body['access_token'])) {
            error_log('[GNL KC-ESP] token client_credentials refusé : HTTP ' . (int) ($resp['status'] ?? 0)
                . ' ' . (string) ($body['error'] ?? '') . ' ' . (string) ($body['error_description'] ?? '')
                . ' — vérifiez que « Service accounts roles » est activé sur le client ' . $clientId
                . ' du realm ' . kcEspIssuer() . '.');
            return null;
        }
        $tok = (string) $body['access_token'];
        $exp = time() + (isset($body['expires_in']) ? (int) $body['expires_in'] : 60);
        return $tok;
    }
}

/**
 * GET sur l'Admin REST API du realm ESP-CLI. Ne lève jamais : renvoie
 * toujours { status:int, body:array, error:string } — error non vide =
 * échec exploitable.
 */
if (!function_exists('kcEspAdminGet')) {
    function kcEspAdminGet(string $path, array $query = []): array
    {
        // Configuration absente : on le dit AVANT de parler d'un refus Keycloak.
        $problem = kcEspConfigProblem();
        if ($problem !== '') {
            return ['status' => 0, 'body' => [], 'error' => $problem];
        }

        $bearer = kcEspAdminToken();
        if ($bearer === null) {
            return [
                'status' => 0,
                'body'   => [],
                'error'  => "Keycloak a refusé le jeton de service du client « " . kcEspClientId() . " » sur "
                    . kcEspIssuer() . " : secret erroné, « Service accounts roles » désactivé sur ce client, "
                    . "ou serveur injoignable (le détail HTTP est dans les logs du portail).",
            ];
        }

        $url = kcEspAdminBase() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        try {
            $resp = keycloakHttpRequest($url, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearer],
            ]);
        } catch (Throwable $e) {
            error_log('[GNL KC-ESP] GET ' . $path . ' : ' . $e->getMessage());
            return ['status' => 0, 'body' => [], 'error' => 'Keycloak est injoignable.'];
        }

        $status = (int) ($resp['status'] ?? 0);
        $body   = (isset($resp['body']) && is_array($resp['body'])) ? $resp['body'] : [];

        if ($status >= 200 && $status < 300) {
            if (kcEspDebug()) {
                error_log('[GNL KC-ESP] GET ' . $path . ' → HTTP ' . $status . ' (' . count($body) . ' élément(s))');
            }
            return ['status' => $status, 'body' => $body, 'error' => ''];
        }

        // Le chemin appelé fait partie du diagnostic : un 404 sur Organizations
        // vient presque toujours d'une URL, pas d'une fonctionnalité absente.
        $error = 'Keycloak a renvoyé HTTP ' . $status . ' sur ' . $path;
        if ($status === 403) {
            $error .= " — le compte de service n'a pas les rôles « view-organizations » et « view-users » (realm-management).";
        } elseif ($status === 404) {
            $error .= " — ressource introuvable (fonctionnalité Organizations désactivée sur le realm, endpoint absent de cette version de Keycloak, ou KEYCLOAK_ESP-CLI_ISSUER erroné).";
        } elseif (!empty($body['errorMessage'])) {
            $error .= ' — ' . (string) $body['errorMessage'];
        }
        error_log('[GNL KC-ESP] GET ' . $path . ' → ' . $error);

        return ['status' => $status, 'body' => $body, 'error' => $error];
    }
}

/* ============================ Normalisation ============================ */

/** Aplati les attributs Keycloak ({cle:[val]} ou {cle:val}) en {cle: "val"}. */
if (!function_exists('kcEspFlattenAttributes')) {
    function kcEspFlattenAttributes($attrs): array
    {
        $out = [];
        if (!is_array($attrs)) return $out;
        foreach ($attrs as $k => $v) {
            if (is_array($v)) {
                $out[(string) $k] = isset($v[0]) && is_scalar($v[0]) ? trim((string) $v[0]) : '';
            } elseif (is_scalar($v)) {
                $out[(string) $k] = trim((string) $v);
            }
        }
        return $out;
    }
}

/**
 * Forme exploitée par /entreprises.
 *
 * « label » est le nom affiché. Règle voulue, dans cet ordre STRICT :
 *   1. l'attribut d'organisation « nom_commercial » ;
 *   2. à défaut, le nom de l'organisation (`name`) ;
 *   3. `alias` en dernier garde-fou (Keycloak impose `name`, donc en
 *      pratique on n'y arrive jamais).
 */
if (!function_exists('kcEspOrgNormalize')) {
    function kcEspOrgNormalize(array $org): array
    {
        $attrs = kcEspFlattenAttributes($org['attributes'] ?? []);

        $label = trim((string) ($attrs['nom_commercial'] ?? ''));
        if ($label === '') $label = trim((string) ($org['name'] ?? ''));
        if ($label === '') $label = trim((string) ($org['alias'] ?? ''));

        $domains = [];
        if (isset($org['domains']) && is_array($org['domains'])) {
            foreach ($org['domains'] as $d) {
                if (is_array($d) && isset($d['name'])) $domains[] = strtolower(trim((string) $d['name']));
                elseif (is_scalar($d))                 $domains[] = strtolower(trim((string) $d));
            }
        }

        $A = static function (array $keys) use ($attrs): string {
            foreach ($keys as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') return $attrs[$k];
            }
            return '';
        };

        $cp      = $A(['cp']);
        $commune = $A(['commune', 'comune']);

        return [
            'id'            => trim((string) ($org['id'] ?? '')),
            'name'          => trim((string) ($org['name'] ?? '')),
            'alias'         => trim((string) ($org['alias'] ?? '')),
            'display_name'  => trim((string) ($org['displayName'] ?? '')),
            'label'         => $label,
            'enabled'       => !array_key_exists('enabled', $org) || (bool) $org['enabled'],
            'raison'        => $A(['raison', 'raison_social']),
            'siret'         => $A(['siret']),
            'siren'         => $A(['siren']),
            'entite_legal'  => $A(['entite_legal']),
            'tva'           => $A(['tva', 'num_tva']),
            'namespace'     => $A(['namespace', 'k8s_namespace']),
            'ent_email'     => $A(['ent_email']),
            'telephone'     => $A(['telephone']),
            'cp'            => $cp,
            'commune'       => $commune,
            'localite'      => trim($cp . ' ' . $commune),
            'pays'          => $A(['pays']),
            'attributes'    => $attrs,
            'domains'       => array_values(array_filter($domains)),
        ];
    }
}

/**
 * Normalise un membre d'organisation, dans la même forme qu'une ligne de
 * membre de /equipes.
 */
if (!function_exists('kcEspMemberNormalize')) {
    function kcEspMemberNormalize(array $m): array
    {
        $attrs = kcEspFlattenAttributes($m['attributes'] ?? []);

        $firstName = trim((string) ($m['firstName'] ?? ''));
        $lastName  = trim((string) ($m['lastName'] ?? ''));
        $email     = trim((string) ($m['email'] ?? ''));
        $username  = trim((string) ($m['username'] ?? ''));

        $name = trim($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = $username !== '' ? $username : ($email !== '' ? $email : 'Utilisateur');
        }

        $initials = mb_strtoupper(
            ($firstName !== '' ? mb_substr($firstName, 0, 1) : '')
            . ($lastName !== '' ? mb_substr($lastName, 0, 1) : ''),
            'UTF-8'
        );
        if ($initials === '') {
            $base = $username !== '' ? $username : $email;
            $initials = $base !== '' ? mb_strtoupper(mb_substr($base, 0, 2), 'UTF-8') : '#';
        }

        $enabled = !array_key_exists('enabled', $m) || (bool) $m['enabled'];

        $function = '';
        foreach (['fonction', 'poste', 'job', 'job_title', 'jobTitle', 'function', 'title'] as $k) {
            if (isset($attrs[$k]) && $attrs[$k] !== '') { $function = $attrs[$k]; break; }
        }

        return [
            'id'           => trim((string) ($m['id'] ?? '')),
            'name'         => $name,
            'secondary'    => $email !== '' ? $email : ($username !== '' ? $username : 'Compte Keycloak'),
            'initials'     => $initials,
            'function'     => $function,
            'email'        => $email,
            'username'     => $username,
            'enabled'      => $enabled,
            'status_label' => $enabled ? 'Actif' : 'Inactif',
            'membership'   => (mb_strtoupper(trim((string) ($m['membershipType'] ?? '')), 'UTF-8') === 'UNMANAGED')
                ? 'Invité externe'
                : "Membre de l'organisation",
        ];
    }
}

/* ========================= Listing des organisations =================== */

/**
 * Toutes les organisations du realm ESP-CLI, paginées jusqu'au plafond.
 *
 * @return array{ok:bool, orgs:array<int,array>, truncated:bool, error:string}
 */
if (!function_exists('kcEspOrganizations')) {
    function kcEspOrganizations(string $search = ''): array
    {
        $max       = kcEspOrgsMax();
        $out       = [];
        $first     = 0;
        $truncated = false;
        $search    = trim($search);

        while (count($out) < $max) {
            $want  = min(KC_ESP_PAGE_SIZE, $max - count($out));
            $query = ['first' => $first, 'max' => $want, 'briefRepresentation' => 'false'];
            if ($search !== '') {
                $query['search'] = $search;
            }

            $r = kcEspAdminGet('/organizations', $query);
            if ($r['error'] !== '') {
                // Une PREMIÈRE page en échec = échec exploitable ; une page
                // suivante en échec = on garde ce qu'on a, marqué tronqué.
                if ($out === []) {
                    return ['ok' => false, 'orgs' => [], 'truncated' => false, 'error' => $r['error']];
                }
                $truncated = true;
                break;
            }

            $page = is_array($r['body']) ? array_values(array_filter($r['body'], 'is_array')) : [];
            foreach ($page as $row) {
                $out[] = kcEspOrgNormalize($row);
            }

            if (count($page) < $want) {
                break; // dernière page
            }
            $first += $want;
        }

        if (count($out) >= $max) {
            $truncated = true;
        }

        // Tri alphabétique stable : l'Admin REST ne garantit pas d'ordre.
        usort($out, static function (array $a, array $b): int {
            return strcmp(mb_strtolower($a['label'], 'UTF-8'), mb_strtolower($b['label'], 'UTF-8'))
                ?: strcmp((string) $a['id'], (string) $b['id']);
        });

        return ['ok' => true, 'orgs' => $out, 'truncated' => $truncated, 'error' => ''];
    }
}

/** Une organisation par son id. { ok, org|null, error }. */
if (!function_exists('kcEspOrganizationById')) {
    function kcEspOrganizationById(string $orgId): array
    {
        $orgId = trim($orgId);
        if ($orgId === '') {
            return ['ok' => false, 'org' => null, 'error' => 'Organisation non identifiée.'];
        }
        $r = kcEspAdminGet('/organizations/' . rawurlencode($orgId));
        if ($r['status'] !== 200 || empty($r['body']['id'])) {
            return ['ok' => false, 'org' => null, 'error' => $r['error'] !== '' ? $r['error'] : 'Organisation Keycloak introuvable.'];
        }
        return ['ok' => true, 'org' => kcEspOrgNormalize($r['body']), 'error' => ''];
    }
}

/**
 * Membres d'une organisation (chargement à la demande depuis /entreprises).
 *
 * @return array{ok:bool, members:array<int,array>, truncated:bool, error:string}
 */
if (!function_exists('kcEspOrganizationMembers')) {
    function kcEspOrganizationMembers(string $orgId): array
    {
        $orgId = trim($orgId);
        if ($orgId === '') {
            return ['ok' => false, 'members' => [], 'truncated' => false, 'error' => 'Organisation non identifiée.'];
        }

        $max       = kcEspMembersMax();
        $out       = [];
        $first     = 0;
        $truncated = false;
        $path      = '/organizations/' . rawurlencode($orgId) . '/members';

        while (count($out) < $max) {
            $want = min(KC_ESP_PAGE_SIZE, $max - count($out));

            $r = kcEspAdminGet($path, ['first' => $first, 'max' => $want]);
            if ($r['error'] !== '') {
                if ($out === []) {
                    return ['ok' => false, 'members' => [], 'truncated' => false, 'error' => $r['error']];
                }
                $truncated = true;
                break;
            }

            $page = is_array($r['body']) ? array_values(array_filter($r['body'], 'is_array')) : [];
            foreach ($page as $row) {
                $out[] = kcEspMemberNormalize($row);
            }

            if (count($page) < $want) {
                break;
            }
            $first += $want;
        }

        if (count($out) >= $max) {
            $truncated = true;
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8'))
                ?: strcmp((string) $a['id'], (string) $b['id']);
        });

        return ['ok' => true, 'members' => $out, 'truncated' => $truncated, 'error' => ''];
    }
}
