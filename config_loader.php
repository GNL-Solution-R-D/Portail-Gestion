<?php
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
} else {
    require_once __DIR__ . '/config.example.php';
}

// Les connexions MySQL sont optionnelles depuis que l'authentification passe par Keycloak.
// On expose toujours les variables attendues par les pages historiques pour éviter les notices
// lorsque la configuration locale ne déclare pas de connexion PDO.
$pdo = $pdo ?? null;
$pdo_powerdns = $pdo_powerdns ?? null;
?>
