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
   Carte centrée, police Manrope, vert #6c9400 / teal #009494 / ink #353535. */
if (!function_exists('gnl_auth_head')) {
    function gnl_auth_head(string $title, string $active = 'connexion'): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $logo = gnl_e((string) config('KEYCLOAK_LOGO_URL', 'https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3.png'));
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo gnl_e($title); ?> — GNL Solution</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" href="https://gnl-solution.fr/wp-content/uploads/2025/12/cropped-Sans-titre37-32x32.png" sizes="32x32">
<style>
:root{
  --gnl-green:#6c9400; --gnl-green-d:#5c7f00; --gnl-teal:#009494; --gnl-ink:#353535;
  --gnl-line:#e4e6e2; --gnl-soft:#f4f6f1; --gnl-danger:#c0392b; --gnl-ok:#2e7d32;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:'Manrope',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  color:var(--gnl-ink); line-height:1.45;
  background:
    radial-gradient(1200px 500px at 15% -10%, color-mix(in srgb,var(--gnl-green) 10%, transparent), transparent 60%),
    radial-gradient(1000px 480px at 110% 10%, color-mix(in srgb,var(--gnl-teal) 12%, transparent), transparent 55%),
    #f3f4f1;
  min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:2.2rem 1rem;
}
.gnl-auth{width:100%; max-width:440px}
.gnl-auth-logo{display:block; text-align:center; margin:0 auto 1.1rem}
.gnl-auth-logo img{height:56px; width:auto}
.gnl-card{
  background:#fff; border:1px solid var(--gnl-line); border-radius:16px;
  padding:1.9rem 1.9rem 1.7rem; box-shadow:0 18px 50px rgba(20,30,15,.08);
}
.gnl-card h1{font-size:1.4rem; font-weight:700; margin:.1rem 0 .25rem; letter-spacing:-.2px}
.gnl-card .sub{margin:0 0 1.35rem; font-size:.92rem; color:#6a6f66}
.gnl-field{margin-bottom:.95rem}
label{display:block; font-size:.82rem; font-weight:600; margin:0 0 .35rem; color:#4a4f46}
.gnl-in{
  width:100%; border:1px solid var(--gnl-line); border-radius:10px;
  padding:.72rem .85rem; font:inherit; font-size:.96rem; background:#fff; color:inherit;
  transition:border-color .15s, box-shadow .15s;
}
.gnl-in:focus{outline:none; border-color:var(--gnl-green); box-shadow:0 0 0 3px color-mix(in srgb,var(--gnl-green) 22%, transparent)}
.gnl-in::placeholder{color:#a7aca1}
.gnl-pass{position:relative}
.gnl-pass .gnl-in{padding-right:3.2rem}
.gnl-eye{position:absolute; right:.55rem; top:50%; transform:translateY(-50%); border:none; background:none; cursor:pointer; color:#8a8f85; padding:.3rem; line-height:0; border-radius:7px}
.gnl-eye:hover{color:var(--gnl-ink)}
.gnl-row-between{display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin:-.15rem 0 1.1rem}
.gnl-check{display:flex; align-items:flex-start; gap:.55rem; font-size:.85rem; color:#4a4f46; cursor:pointer; user-select:none}
.gnl-check input{margin:.15rem 0 0; accent-color:var(--gnl-green); flex:none}
.gnl-link{color:var(--gnl-teal); text-decoration:none; font-size:.85rem; font-weight:600}
.gnl-link:hover{text-decoration:underline}
.gnl-btn{
  display:block; width:100%; border:none; cursor:pointer; margin-top:.35rem;
  background:var(--gnl-green); color:#fff; border-radius:11px;
  padding:.85rem 1rem; font:inherit; font-weight:700; font-size:1rem;
  transition:filter .15s, transform .02s;
}
.gnl-btn:hover{filter:brightness(1.05)}
.gnl-btn:active{transform:translateY(1px)}
.gnl-btn[disabled]{opacity:.6; cursor:progress}
.gnl-alt{margin-top:1.25rem; text-align:center; font-size:.9rem; color:#5c6157}
.gnl-alt a{color:var(--gnl-teal); font-weight:700; text-decoration:none}
.gnl-alt a:hover{text-decoration:underline}
.gnl-msg{border-radius:11px; padding:.75rem .9rem; font-size:.88rem; margin:0 0 1.15rem; display:flex; gap:.55rem; align-items:flex-start}
.gnl-msg svg{flex:none; margin-top:1px}
.gnl-msg.err{background:color-mix(in srgb,var(--gnl-danger) 8%, #fff); border:1px solid color-mix(in srgb,var(--gnl-danger) 35%, transparent); color:#8e2a1e}
.gnl-msg.ok{background:color-mix(in srgb,var(--gnl-ok) 8%, #fff); border:1px solid color-mix(in srgb,var(--gnl-ok) 35%, transparent); color:#1f6323}
.gnl-hint{font-size:.78rem; color:#8a8f85; margin:.3rem 0 0}
.gnl-sep{display:flex; align-items:center; gap:.8rem; color:#a7aca1; font-size:.78rem; margin:1.15rem 0 .3rem}
.gnl-sep::before,.gnl-sep::after{content:""; height:1px; background:var(--gnl-line); flex:1}
.gnl-foot{margin-top:1.4rem; text-align:center; font-size:.76rem; color:#9aa093}
.gnl-foot a{color:inherit}
@media(max-width:480px){ .gnl-card{padding:1.5rem 1.25rem} }
</style>
</head>
<body>
<main class="gnl-auth">
  <a class="gnl-auth-logo" href="/" aria-label="GNL Solution"><img src="<?php echo $logo; ?>" alt="GNL Solution"></a>
  <div class="gnl-card">
<?php
    }
}

if (!function_exists('gnl_auth_foot')) {
    function gnl_auth_foot(): void
    {
        ?>
  </div>
  <p class="gnl-foot">Espace sécurisé GNL Solution &middot; <a href="/">Retour au site</a></p>
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
