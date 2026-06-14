<?php
session_start();
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/include/account_sessions.php';
require_once __DIR__ . '/include/keycloak_auth.php';
require_once __DIR__ . '/include/portail_api_client.php';

$code = trim((string) ($_GET['code'] ?? ''));
$state = trim((string) ($_GET['state'] ?? ''));
$storedState = trim((string) ($_SESSION['keycloak_oauth_state'] ?? ''));

if ($code === '' || $state === '' || $storedState === '' || !hash_equals($storedState, $state)) {
    unset($_SESSION['keycloak_oauth_state'], $_SESSION['keycloak_oauth_nonce']);
    header('Location: /connexion?error=' . urlencode('Retour Keycloak invalide (state/code).'));
    exit();
}

unset($_SESSION['keycloak_oauth_state']);

try {
    $tokenData = keycloakExchangeCodeForTokens($code);

    $accessToken = trim((string) ($tokenData['access_token'] ?? ''));
    $idToken = trim((string) ($tokenData['id_token'] ?? ''));

    if ($accessToken === '') {
        throw new RuntimeException('Access token manquant dans la réponse Keycloak.');
    }

    $accessTokenClaims = keycloakDecodeJwtPayload($accessToken);
    $idTokenClaims = $idToken !== '' ? keycloakDecodeJwtPayload($idToken) : [];
    $userInfoClaims = keycloakFetchUserInfo($accessToken);

    $claims = array_merge($accessTokenClaims, $idTokenClaims, $userInfoClaims);
    if ($claims === []) {
        throw new RuntimeException('Impossible de lire les claims Keycloak (access_token/id_token/userinfo).');
    }

    // ── DEBUG temporaire ─────────────────────────────────────────────────────
    // KEYCLOAK_DEBUG_CLAIMS=1 → journalise la forme EXACTE des claims (PII : à
    // désactiver en production). Utile pour les attributs « select » vides.
    if (getenv('KEYCLOAK_DEBUG_CLAIMS') === '1') {
        error_log('[keycloak_callback] claims=' . json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $sessionUser = keycloakBuildSessionUser($claims);
    if (trim((string) ($sessionUser['k8s_namespace'] ?? '')) === '') {
        throw new RuntimeException('Le mapper Keycloak "namespace" est requis (scope kubernetes).');
    }

    session_regenerate_id(true);
    $_SESSION['user'] = $sessionUser;
    $_SESSION['keycloak_id_token'] = $idToken;

    accountSessionsTouchCurrent($pdo, (int) $sessionUser['id']);
} catch (Throwable $exception) {
    header('Location: /connexion?error=' . urlencode($exception->getMessage()));
    exit();
}

// ── Alimente la table « team » à la connexion (idempotent) ───────────────────
// Via le pipeline portail_api → n8n (action "team.ensure"). client_id issu de la
// session (non falsifiable). Un échec NE bloque JAMAIS la connexion.
try {
    $teamResult = portailEnsureTeamMembership($_SESSION['user']);

    // Diagnostic : PORTAIL_DEBUG_TEAM=1 → log l'URL appelée + le code HTTP n8n
    // + le payload envoyé + le début de la réponse. À désactiver en production.
    if (getenv('PORTAIL_DEBUG_TEAM') === '1') {
        error_log('[keycloak_callback] team.ensure → HTTP ' . ($teamResult['status'] ?? '?')
            . ' url=' . portailApiUrl()
            . ' sent=' . json_encode(portailBuildTeamEnsurePayload($_SESSION['user']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' resp=' . substr((string) ($teamResult['raw'] ?? ''), 0, 800));
    }
} catch (Throwable $teamException) {
    error_log('[keycloak_callback] team.ensure ERREUR: ' . $teamException->getMessage());
}

header('Location: /dashboard');
exit();