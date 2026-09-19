<?php
/* =====================================================================
   GNL Solution — Connexion au PORTAIL GESTION  (/connexion)
   ---------------------------------------------------------------------
   Formulaire maison + connexion REST (Direct Access Grant), identique à
   l'espace client, MAIS sans la prise en charge des organisations : un
   grant réussi ouvre directement la session et renvoie vers « return ».
   La session $_SESSION['user'] est construite par keycloakBuildSessionUser()
   (identique au flow « code » de keycloak_callback.php).

   Repli SSO : /keycloak_login.php (page Keycloak hébergée) reste dispo pour
   les cas non couverts par le grant password (MFA/OTP, fédération, actions
   requises). Un lien discret y renvoie.
   ===================================================================== */

require_once '../include/session_bootstrap.php';   // session sécurisée (en 1er)
require_once '../include/keycloak_rest.php';       // password grant + gabarit
// (keycloak_rest.php inclut déjà config_loader, account_sessions, portail_api_client)

/* Cible de retour (chemin interne uniquement), défaut /dashboard. */
$return = gnl_safe_return($_REQUEST['return'] ?? '/dashboard');

/* Déjà connecté ? -> on repart directement vers la cible. */
if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: ' . gnl_site_base() . $return);
    exit;
}

$error  = '';
$notice = '';

/* Messages transmis par les autres pages via ?error / ?loggedout. */
if (isset($_GET['error']) && $_GET['error'] !== '') {
    $error = (string) $_GET['error'];
}
if (isset($_GET['loggedout'])) {
    $notice = "Vous êtes déconnecté.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gnl_login_csrf_check()) {
        $error = "Session expirée. Merci de renvoyer le formulaire.";
    } else {
        $action = $_POST['action'] ?? 'login';

        /* -------- Mot de passe oublié (Admin REST : execute-actions-email) */
        if ($action === 'forgot') {
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Indiquez une adresse e-mail valide.";
            } else {
                kcRestSendPasswordResetEmail($email); // best-effort, silencieux
                $notice = "Si un compte est associé à cette adresse, un e-mail de réinitialisation vient d'être envoyé.";
            }
        }

        /* --------------------------- Connexion ------------------------ */
        else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if ($username === '' || $password === '') {
                $error = "Renseignez votre identifiant et votre mot de passe.";
            } else {
                try {
                    $tok  = keycloakPasswordGrant($username, $password);
                    $body = (isset($tok['status']) && (int) $tok['status'] === 200 && !empty($tok['body']) && is_array($tok['body']))
                        ? $tok['body'] : null;

                    if ($body !== null && !empty($body['access_token'])) {
                        $accessToken = (string) $body['access_token'];
                        $idToken     = trim((string) ($body['id_token'] ?? ''));

                        // Claims fusionnés depuis les trois sources (comme keycloak_callback.php).
                        $accessClaims = keycloakDecodeJwtPayload($accessToken);
                        $idClaims     = $idToken !== '' ? keycloakDecodeJwtPayload($idToken) : [];
                        $userInfo     = keycloakFetchUserInfo($accessToken);
                        $claims       = array_merge($accessClaims, $idClaims, $userInfo);

                        $r = gnl_route_after_login($claims, $idToken, $return);
                        if ($r['state'] === 'done') {
                            header('Location: ' . gnl_site_base() . $r['redirect']);
                            exit;
                        }
                        $error = $r['error'] !== '' ? $r['error'] : "Connexion impossible. Réessayez.";
                    } else {
                        error_log('[GNL REST] login failed for "' . $username . '": ' . gnl_login_detail($tok));
                        $error = gnl_login_error_fr($tok);
                    }
                } catch (Throwable $e) {
                    error_log('[GNL REST] login transport error for "' . $username . '": ' . $e->getMessage());
                    $error = "Service d'authentification injoignable. Réessayez dans un instant.";
                }
            }
        }
    }
}

$csrf      = gnl_login_csrf_token();
$retAttr   = gnl_e($return);
$prefEmail = gnl_e((string) ($_POST['username'] ?? ''));
$ssoUrl    = '/keycloak_login.php?return=' . rawurlencode($return);

gnl_auth_head('Connexion', 'connexion');
?>
    <h1>Connexion</h1>
    <p class="sub">Accédez au portail de gestion GNL Solution.</p>

    <?php if ($error !== ''): ?>
      <div class="gnl-msg err"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <div class="gnl-msg ok"><?php echo gnl_icon('ok'); ?><span><?php echo gnl_e($notice); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/connexion" data-gnl-auth autocomplete="on">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">

      <div class="gnl-field">
        <label for="username">E-mail ou identifiant</label>
        <input class="gnl-in" type="text" id="username" name="username" value="<?php echo $prefEmail; ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               placeholder="vous@exemple.fr" required autofocus>
      </div>

      <div class="gnl-field">
        <label for="password">Mot de passe</label>
        <div class="gnl-pass">
          <input class="gnl-in" type="password" id="password" name="password"
                 autocomplete="current-password" placeholder="••••••••" required>
          <button type="button" class="gnl-eye" aria-label="Afficher le mot de passe" tabindex="-1">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <div class="gnl-row-between">
        <label class="gnl-check"><input type="checkbox" name="remember" value="1"> Se souvenir de moi</label>
        <a class="gnl-link" href="#" id="gnl-forgot-toggle">Mot de passe oublié&nbsp;?</a>
      </div>

      <button class="gnl-btn" type="submit">Se connecter</button>
    </form>

    <!-- Bloc « mot de passe oublié » (affiché à la demande) -->
    <form method="post" action="/connexion" data-gnl-auth id="gnl-forgot-form" style="display:none; margin-top:1.1rem">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="action" value="forgot">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">
      <div class="gnl-sep">Réinitialiser le mot de passe</div>
      <div class="gnl-field">
        <label for="forgot-email">Votre adresse e-mail</label>
        <input class="gnl-in" type="email" id="forgot-email" name="email"
               autocomplete="email" placeholder="vous@exemple.fr" required>
        <p class="gnl-hint">Un lien de réinitialisation vous sera envoyé par e-mail.</p>
      </div>
      <button class="gnl-btn" type="submit" style="background:var(--gnl-teal)">Envoyer le lien</button>
    </form>

    <?php if (kcRestAllowRegistration()): ?>
      <p class="gnl-alt">Pas encore de compte&nbsp;? <a href="/inscription?return=<?php echo rawurlencode($return); ?>">Créer un compte</a></p>
    <?php endif; ?>

    <p class="gnl-alt" style="margin-top:.9rem">
      <a class="gnl-link" href="<?php echo gnl_e($ssoUrl); ?>">Connexion sécurisée (SSO / MFA)</a>
    </p>

    <script>
      (function(){
        var t = document.getElementById('gnl-forgot-toggle');
        var f = document.getElementById('gnl-forgot-form');
        if(t && f){ t.addEventListener('click', function(e){
          e.preventDefault();
          var open = f.style.display !== 'none';
          f.style.display = open ? 'none' : 'block';
          if(!open){ var i=f.querySelector('input[type=email]'); if(i) i.focus(); }
        }); }
      })();
    </script>
<?php
gnl_auth_foot();
