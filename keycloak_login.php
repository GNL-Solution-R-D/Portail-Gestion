<?php
session_start();
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/include/keycloak_auth.php';

try {
    $authorizationUrl = keycloakBuildAuthorizationUrl();
} catch (Throwable $exception) {
    header('Location: /connexion?error=' . urlencode($exception->getMessage()));
    exit();
}

header('Location: ' . $authorizationUrl);
exit();
