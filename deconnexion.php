<?php
session_start();
require_once 'config_loader.php';
require_once 'include/account_sessions.php';
require_once 'include/keycloak_auth.php';

if (isset($_SESSION['user']['id'])) {
    accountSessionsRevokeCurrent($pdo, (int) $_SESSION['user']['id']);
}

$idTokenHint = isset($_SESSION['keycloak_id_token']) ? (string) $_SESSION['keycloak_id_token'] : null;
accountSessionsDestroyPhpSession();
header('Location: ' . keycloakBuildLogoutUrl($idTokenHint));
exit();
