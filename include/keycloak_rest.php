<?php
/* =====================================================================
   GNL Solution — Connexion REST pour le PORTAIL GESTION
   (include/keycloak_rest.php)
   ---------------------------------------------------------------------
   Portage du mécanisme de l'espace client : formulaire « maison »
   (Direct Access Grant / grant password) devant Keycloak, SANS changer
   le contrat de session du portail.

   On NE construit PAS la session nous-mêmes : on récupère les jetons via
   l'API REST, puis on délègue la construction de $_SESSION['user'] à
   keycloakBuildSessionUser() (include/keycloak_auth.php), exactement
   comme keycloak_callback.php. Le reste du portail est inchangé.

   DIFFÉRENCE ASSUMÉE AVEC L'ESPACE CLIENT
   ---------------------------------------
   Le portail gestion n'utilise PAS la fonctionnalité « Organizations »
   de Keycloak : il n'y a donc ni page /organisation, ni état d'attente
   « choix d'organisation », ni scope organization:*, ni exigence de
   namespace Kubernetes. Un grant réussi ouvre directement la session.

   Ce fichier ne définit QUE des fonctions (aucune sortie à l'inclusion).

   -------------------- Pré-requis Keycloak ----------------------------
   Client OIDC du portail (KEYCLOAK_CLIENT_ID) :
     - « Client authentication » = ON (client confidentiel, secret)
     - « Direct access grants »  = ON   (indispensable à la connexion REST)
   Scope demandé : « openid profile email » (config KEYCLOAK_SCOPES).

   Mot de passe oublié (optionnel) : compte de service avec les rôles
   realm-management « manage-users » (+ « view-users »). Réutilise le
   client du portail (Service accounts roles) ou un client dédié via
   config('KEYCLOAK_ADMIN_CLIENT_ID' / '_SECRET'). Sinon, message
   générique affiché sans rien envoyer.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../config_loader.php';    // config(), $pdo
require_once __DIR__ . '/keycloak_auth.php';       // keycloakGet*, keycloakBuildSessionUser, etc.
require_once __DIR__ . '/account_sessions.php';    // accountSessionsTouchCurrent()
require_once __DIR__ . '/portail_api_client.php';  // portailEnsureTeamMembership()

/* --------------------------------------------------------------------
   Config locale (lue via config(), même source que le flow « code »)
   -------------------------------------------------------------------- */
if (!function_exists('kcRestScopes')) {
    function kcRestScopes(): string
    {
        // Doit rester aligné sur keycloakBuildAuthorizationUrl() : le portail
        // gestion ne demande ni « kubernetes » ni « organization ».
        return trim((string) config('KEYCLOAK_SCOPES', 'openid profile email'));
    }
}
if (!function_exists('kcRestAdminClientId')) {
    function kcRestAdminClientId(): string
    {
        $v = trim((string) config('KEYCLOAK_ADMIN_CLIENT_ID', ''));
        return $v !== '' ? $v : keycloakGetClientId(); // repli : client du portail
    }
}
if (!function_exists('kcRestAdminClientSecret')) {
    function kcRestAdminClientSecret(): string
    {
        $v = trim((string) config('KEYCLOAK_ADMIN_CLIENT_SECRET', ''));
        return $v !== '' ? $v : keycloakGetClientSecret();
    }
}
if (!function_exists('kcRestAllowRegistration')) {
    /** Affiche (ou non) le lien « Créer un compte ». Off par défaut ici. */
    function kcRestAllowRegistration(): bool
    {
        return (string) config('KEYCLOAK_ALLOW_REGISTRATION', '0') === '1';
    }
}

/* ============================ Utilitaires =========================== */
if (!function_exists('gnl_e')) {
    function gnl_e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('gnl_site_base')) {
    function gnl_site_base(): string
    {
        static $b = null;
        if ($b !== null) return $b;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $b = ($https ? 'https' : 'http') . '://' . $host;
    }
}
/* N'autorise que des chemins internes ("/xxx"), défaut /dashboard. */
if (!function_exists('gnl_safe_return')) {
    function gnl_safe_return($v): string
    {
        $v = (string) $v;
        if ($v === '' || $v[0] !== '/' || (isset($v[1]) && $v[1] === '/')) return '/dashboard';
        return $v;
    }
}
if (!function_exists('gnl_rand_hex')) {
    function gnl_rand_hex(int $bytes = 24): string
    {
        try { return bin2hex(random_bytes($bytes)); }
        catch (Throwable $e) { return substr(md5(uniqid('', true) . mt_rand()), 0, $bytes * 2); }
    }
}

/* ------------------------------ CSRF -------------------------------
   Clé DISTINCTE de $_SESSION['csrf'] (utilisée par data/portail_api.php). */
if (!function_exists('gnl_login_csrf_token')) {
    function gnl_login_csrf_token(): string
    {
        if (empty($_SESSION['gnl_login_csrf'])) $_SESSION['gnl_login_csrf'] = gnl_rand_hex(24);
        return $_SESSION['gnl_login_csrf'];
    }
}
if (!function_exists('gnl_login_csrf_check')) {
    function gnl_login_csrf_check(): bool
    {
        $t = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        return $t !== '' && !empty($_SESSION['gnl_login_csrf']) && hash_equals((string) $_SESSION['gnl_login_csrf'], $t);
    }
}

/* ================= Grant "password" (connexion REST) ================
   Retourne { status:int, body:array }. Peut lever une RuntimeException sur
   échec réseau (à attraper par l'appelant). */
if (!function_exists('keycloakPasswordGrant')) {
    function keycloakPasswordGrant(string $username, string $password): array
    {
        $fields = [
            'grant_type' => 'password',
            'client_id'  => keycloakGetClientId(),
            'username'   => $username,
            'password'   => $password,
            'scope'      => kcRestScopes(),
        ];
        $secret = keycloakGetClientSecret();
        if ($secret !== '') $fields['client_secret'] = $secret;

        return keycloakHttpRequest(
            keycloakGetIssuer() . '/protocol/openid-connect/token',
            [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            ]
        );
    }
}

/* Traduit une réponse d'erreur Keycloak en message FR compréhensible. */
if (!function_exists('gnl_login_error_fr')) {
    function gnl_login_error_fr(array $resp): string
    {
        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        $err  = isset($body['error']) ? (string) $body['error'] : '';
        $desc = isset($body['error_description']) ? (string) $body['error_description'] : '';
        $d    = strtolower($desc);

        if ($err === 'invalid_grant' && strpos($d, 'not fully set up') !== false)
            return "Votre compte n'est pas encore finalisé (e-mail à vérifier ou action requise). Consultez votre boîte mail.";
        if ($err === 'invalid_grant' && strpos($d, 'disabled') !== false)
            return "Ce compte est désactivé. Contactez l'administrateur.";
        if ($err === 'invalid_grant')
            return "Identifiant ou mot de passe incorrect.";
        if ($err === 'unauthorized_client' || strpos($d, 'direct access') !== false)
            return "La connexion directe n'est pas activée côté serveur (Direct access grants).";
        if ($err === 'invalid_client')
            return "Configuration client invalide (secret manquant ou erroné).";
        if ($err === 'invalid_scope')
            return "Le scope de connexion est refusé par le serveur (vérifiez KEYCLOAK_SCOPES).";
        if ($desc !== '') return $desc;
        return "Connexion impossible. Réessayez.";
    }
}
/* Détail court pour les logs serveur (jamais affiché à l'utilisateur). */
if (!function_exists('gnl_login_detail')) {
    function gnl_login_detail(array $resp): string
    {
        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        $err  = isset($body['error']) ? (string) $body['error'] : '';
        $desc = isset($body['error_description']) ? (string) $body['error_description'] : '';
        $st   = isset($resp['status']) ? (int) $resp['status'] : 0;
        $bits = [];
        if ($st)          $bits[] = 'HTTP ' . $st;
        if ($err !== '')  $bits[] = $err;
        if ($desc !== '') $bits[] = $desc;
        return implode(' — ', $bits);
    }
}

/* ============= Identité normalisée (UID Keycloak conservé) ==========
   keycloakBuildSessionUser() pose déjà 'id' = entier dérivé de sha1(sub),
   clé attendue par user_account_sessions (colonne INT). On se contente
   d'exposer EN PLUS l'UID Keycloak réel, utile aux appels Admin REST, et
   de dériver le siren du siret quand il manque. Idempotent. */
if (!function_exists('gnl_apply_identity')) {
    function gnl_apply_identity(array $u, array $claims): array
    {
        $uid = keycloakReadClaim($claims, ['sub']);
        if ($uid === '') $uid = (string) ($u['keycloak_uid'] ?? $u['sub'] ?? '');
        if ($uid !== '') {
            $u['keycloak_uid'] = $uid;
            $u['sub']          = $uid;
        }

        // 'id' reste l'entier local : les pages du portail gestion le castent
        // en (int) pour user_account_sessions. Ne JAMAIS y écrire l'UUID.
        if (!isset($u['id']) || (int) $u['id'] <= 0) {
            $seed = $uid !== '' ? $uid : 'anonymous';
            $id   = (int) (hexdec(substr(sha1($seed), 0, 8)) % 2147483647);
            $u['id'] = $id > 0 ? $id : 1;
        }
        $u['account_id'] = (int) $u['id'];

        // siren : dérivation depuis le siret si absent.
        $siret = preg_replace('/\D/', '', (string) ($u['siret'] ?? ''));
        if ((string) ($u['siren'] ?? '') === '' && strlen($siret) >= 9) {
            $u['siren'] = substr($siret, 0, 9);
        }
        return $u;
    }
}

/* ============= Construction de session (délégation) ================
   Prend les claims fusionnés + l'id_token, construit $_SESSION['user'] via
   keycloakBuildSessionUser(), ouvre la session et déclenche le suivi +
   le provisioning « team ».
   Retour : ['ok'=>bool, 'error'=>string]. */
if (!function_exists('gnl_finalize_portal_login')) {
    function gnl_finalize_portal_login(array $claims, string $idToken): array
    {
        global $pdo;

        if ($claims === []) {
            return ['ok' => false, 'error' => "Impossible de lire votre profil. Réessayez."];
        }

        $sessionUser = gnl_apply_identity(keycloakBuildSessionUser($claims), $claims);

        session_regenerate_id(true); // anti-fixation, conserve les données de session
        $_SESSION['user'] = $sessionUser;
        $_SESSION['keycloak_id_token'] = $idToken;

        accountSessionsTouchCurrent($pdo, (int) ($sessionUser['id'] ?? 0));

        try {
            portailEnsureTeamMembership($_SESSION['user']);
        } catch (Throwable $e) {
            error_log('[GNL REST] team.ensure: ' . $e->getMessage());
        }

        return ['ok' => true, 'error' => ''];
    }
}

/* Après un grant réussi : finalise et indique où repartir.
   Pas d'organisation ici — l'état « choose » de l'espace client n'existe pas.
   Retour : ['state'=>'done'|'error', 'redirect'=>string, 'error'=>string]. */
if (!function_exists('gnl_route_after_login')) {
    function gnl_route_after_login(array $claims, string $idToken, string $return): array
    {
        $r = gnl_finalize_portal_login($claims, $idToken);
        if ($r['ok']) return ['state' => 'done', 'redirect' => $return, 'error' => ''];
        return ['state' => 'error', 'redirect' => '', 'error' => $r['error']];
    }
}

/* ============= Mot de passe oublié (Admin REST, optionnel) ==========
   Best-effort : n'échoue jamais bruyamment côté utilisateur. */
if (!function_exists('kcRestAdminBase')) {
    /** Déduit la base Admin REST de l'issuer. */
    function kcRestAdminBase(): string
    {
        $iss = rtrim(keycloakGetIssuer(), '/');
        $pos = strpos($iss, '/realms/');
        if ($pos === false) return $iss . '/admin';
        $server = substr($iss, 0, $pos);                              // https://host/auth
        $realm  = trim(substr($iss, $pos + strlen('/realms/')), '/');  // nom du realm
        return $server . '/admin/realms/' . rawurlencode($realm);
    }
}
if (!function_exists('kcRestAdminToken')) {
    function kcRestAdminToken(): ?string
    {
        static $tok = null, $exp = 0;
        if ($tok !== null && time() < $exp - 15) return $tok;

        try {
            $resp = keycloakHttpRequest(
                keycloakGetIssuer() . '/protocol/openid-connect/token',
                [
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'grant_type'    => 'client_credentials',
                        'client_id'     => kcRestAdminClientId(),
                        'client_secret' => kcRestAdminClientSecret(),
                    ], '', '&', PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                ]
            );
        } catch (Throwable $e) {
            error_log('[GNL REST] admin token network error: ' . $e->getMessage());
            return null;
        }

        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (empty($body['access_token'])) {
            error_log('[GNL REST] admin token failed: ' . gnl_login_detail($resp));
            return null;
        }
        $tok = (string) $body['access_token'];
        $exp = time() + (isset($body['expires_in']) ? (int) $body['expires_in'] : 60);
        return $tok;
    }
}
if (!function_exists('kcRestFindUserId')) {
    function kcRestFindUserId(string $email): ?string
    {
        $bearer = kcRestAdminToken();
        if ($bearer === null) return null;
        try {
            $resp = keycloakHttpRequest(
                kcRestAdminBase() . '/users?' . http_build_query(['email' => $email, 'exact' => 'true', 'max' => 1]),
                [CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $bearer]]
            );
        } catch (Throwable $e) {
            error_log('[GNL REST] find user network error: ' . $e->getMessage());
            return null;
        }
        $body = isset($resp['body']) && is_array($resp['body']) ? $resp['body'] : [];
        if (!empty($body[0]) && is_array($body[0]) && !empty($body[0]['id'])) return (string) $body[0]['id'];
        return null;
    }
}
if (!function_exists('kcRestSendPasswordResetEmail')) {
    /** Déclenche l'e-mail Keycloak « UPDATE_PASSWORD ». Best-effort, silencieux. */
    function kcRestSendPasswordResetEmail(string $email): void
    {
        $bearer = kcRestAdminToken();
        if ($bearer === null) return; // non configuré -> message générique affiché quand même
        $userId = kcRestFindUserId($email);
        if ($userId === null) return; // adresse inconnue -> on ne révèle rien

        try {
            keycloakHttpRequest(
                kcRestAdminBase() . '/users/' . rawurlencode($userId) . '/execute-actions-email?'
                    . http_build_query(['client_id' => keycloakGetClientId()]),
                [
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_POSTFIELDS    => json_encode(['UPDATE_PASSWORD']),
                    CURLOPT_HTTPHEADER    => [
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $bearer,
                    ],
                ]
            );
        } catch (Throwable $e) {
            error_log('[GNL REST] execute-actions-email network error: ' . $e->getMessage());
        }
    }
}

/* ==================== Gabarit visuel (charte GNL) ===================
   Panneau marine + formulaire, police Manrope locale, marine #141a33 / vert #5cd98b. */
if (!function_exists('gnl_auth_head')) {
    function gnl_auth_head(string $title, string $active = 'connexion'): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#141a33">
<title><?php echo gnl_e($title); ?> — Portail Gestion GNL Solution</title>
<link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32">
<link rel="apple-touch-icon" href="/assets/gnl/img/favicon-180.png">
<style>
@font-face{font-family:Manrope;font-style:normal;font-weight:200 800;font-display:swap;src:url(/assets/gnl/fonts/Manrope-latin.woff2) format("woff2")}
:root{
  --navy:#141a33; --navy-2:#232b52; --navy-3:#32385e; --green:#5cd98b; --green-d:#1f7a4a;
  --ink:#141a33; --mute:#5e6684; --line:#c5cbd8; --line-2:#e3e6ee; --page:#f4f6fa;
  --danger:#b42318; --ok:#1f7a4a;
  /* alias conservés pour les pages qui les utilisent en ligne */
  --gnl-green:#141a33; --gnl-teal:#1f7a4a; --gnl-line:#c5cbd8;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{font:15px/22px Manrope,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--page);min-height:100vh;display:flex;flex-wrap:wrap;align-items:stretch}
a{color:var(--green-d)}
.gnl-aside{flex:1 1 420px;max-width:100%;padding:40px 48px;background:var(--navy);color:#fff;display:flex;flex-direction:column;gap:40px}
.gnl-aside img{width:210px;height:auto;display:block;margin-left:-12px}
.gnl-aside .k{margin:0;display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;letter-spacing:.2em;color:var(--green)}
.gnl-aside h2{margin:0;font-size:38px;line-height:44px;font-weight:800;max-width:460px}
.gnl-aside ul{margin:8px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:14px;color:#c5cbd8;max-width:460px}
.gnl-aside li{display:flex;gap:12px;align-items:baseline}
.gnl-aside .bottom{margin-top:auto;display:flex;flex-wrap:wrap;gap:8px 20px;font-size:13px;color:#c5cbd8}
.gnl-aside .bottom a{color:#c5cbd8;text-decoration:none}
.gnl-aside .bottom a:hover{color:#fff}
.gnl-aside .badge{align-self:flex-start;padding:4px 10px;border-radius:3px;background:var(--green);color:var(--navy);font-size:11px;font-weight:800;letter-spacing:.14em}
.gnl-aside .notice{margin:auto 0 0;display:flex;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--navy-3);border-radius:3px;background:var(--navy-2);color:#c5cbd8;font-size:13px;line-height:19px}
.gnl-aside .notice svg{flex:none}
.gnl-aside .dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:8px}
.gnl-auth{flex:1 1 520px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px}
.gnl-card{width:100%;max-width:420px}
.gnl-card h1{font-size:30px;line-height:38px;font-weight:800;margin:0 0 6px}
.gnl-card .sub{margin:0 0 28px;color:var(--mute)}
.gnl-field{margin-bottom:18px}
label{display:block;font-size:13px;font-weight:700;margin:0 0 6px;color:var(--ink)}
.gnl-in{width:100%;height:46px;border:1px solid var(--line);border-radius:3px;padding:0 14px;font:inherit;background:#fff;color:inherit;transition:border-color .15s,box-shadow .15s}
.gnl-in:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(92,217,139,.35)}
.gnl-in::placeholder{color:#9aa1b8}
.gnl-pass{position:relative}
.gnl-pass .gnl-in{padding-right:48px}
.gnl-eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;color:var(--mute);padding:6px;line-height:0;border-radius:3px}
.gnl-eye:hover{color:var(--ink)}
.gnl-row-between{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:-4px 0 20px}
.gnl-check{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:500;color:var(--ink);cursor:pointer;user-select:none;margin:0}
.gnl-check input{margin:0;accent-color:var(--green-d);flex:none;width:16px;height:16px}
.gnl-link{color:var(--green-d);text-decoration:none;font-size:14px;font-weight:700}
.gnl-link:hover{text-decoration:underline}
.gnl-btn{display:block;width:100%;height:48px;border:none;cursor:pointer;background:var(--navy);color:#fff;border-radius:3px;font:inherit;font-weight:700;font-size:15px;transition:background .15s}
.gnl-btn:hover{background:var(--navy-2)}
.gnl-btn:focus-visible{outline:3px solid var(--green);outline-offset:2px}
.gnl-btn[disabled]{opacity:.6;cursor:progress}
.gnl-alt{margin-top:20px;text-align:center;font-size:14px;color:var(--mute)}
.gnl-alt a{color:var(--green-d);font-weight:700;text-decoration:none}
.gnl-alt a:hover{text-decoration:underline}
.gnl-msg{border-radius:3px;padding:12px 14px;font-size:14px;margin:0 0 20px;display:flex;gap:10px;align-items:flex-start}
.gnl-msg svg{flex:none;margin-top:2px}
.gnl-msg.err{background:#fef3f2;border:1px solid #fecdca;color:var(--danger)}
.gnl-msg.ok{background:#ecfdf3;border:1px solid #abefc6;color:var(--ok)}
.gnl-hint{font-size:13px;color:var(--mute);margin:6px 0 0}
.gnl-sep{display:flex;align-items:center;gap:12px;color:var(--mute);font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;margin:24px 0 12px}
.gnl-sep::before,.gnl-sep::after{content:"";height:1px;background:var(--line-2);flex:1}
.gnl-foot{margin-top:32px;text-align:center;font-size:13px;color:var(--mute)}
.gnl-foot a{color:var(--mute)}
@media(max-width:900px){
  .gnl-aside .notice{display:none}
  .gnl-aside{flex-basis:100%;padding:24px;gap:20px}
  .gnl-aside img{width:160px}
  .gnl-aside h2{font-size:24px;line-height:30px}
  .gnl-aside ul,.gnl-aside .bottom{display:none}
  .gnl-auth{padding:32px 16px 40px}
}
</style>
</head>
<body>
<aside class="gnl-aside">
  <div style="display:flex;flex-direction:column;gap:12px">
    <a href="/" aria-label="GNL Solution"><img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution"></a>
    <span class="badge">PORTAIL GESTION</span>
  </div>
  <div style="display:flex;flex-direction:column;gap:18px">
    <p class="k"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg>ESPACE DU PERSONNEL</p>
    <h2>Clients, services et support, sur un seul écran.</h2>
    <ul>
      <li><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><span>Suivez les tickets et répondez au nom du support.</span></li>
      <li><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><span>Pilotez les services de toutes les entreprises clientes.</span></li>
      <li><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg><span>Gardez un œil sur la facturation, la boutique et les serveurs DNS.</span></li>
    </ul>
  </div>
  <p class="notice"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5cd98b" stroke-width="2" stroke-linecap="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Accès réservé au personnel de GNL Solution. Chaque connexion est journalisée.</p>
</aside>
<main class="gnl-auth">
  <div class="gnl-card">
<?php
    }
}
if (!function_exists('gnl_auth_foot')) {
    function gnl_auth_foot(): void
    {
        ?>
  </div>
  <p class="gnl-foot">GNL Solution &middot; Portail de gestion &middot; <a href="https://gnl-solution.com/cgu">CGU</a></p>
</main>
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest('.gnl-eye'); if(!b) return;
  var inp = b.parentNode.querySelector('input');
  if(!inp) return;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  b.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
});
document.querySelectorAll('form[data-gnl-auth]').forEach(function(f){
  f.addEventListener('submit', function(){
    var b = f.querySelector('button[type=submit]');
    if(b){ b.disabled = true; b.textContent = 'Veuillez patienter…'; }
  });
});
</script>
</body>
</html>
<?php
    }
}
if (!function_exists('gnl_icon')) {
    function gnl_icon(string $type): string
    {
        if ($type === 'ok') return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    }
}
