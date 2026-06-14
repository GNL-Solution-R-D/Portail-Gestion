<?php

declare(strict_types=1);

/**
 * include/session_bootstrap.php
 *
 * Démarre la session PHP de manière sécurisée.
 * À inclure EN PREMIER dans toutes les pages, avant tout autre require.
 *
 * Remplace les blocs ad-hoc session_start() présents dans chaque page :
 *   - dashboard.php  : session_start() nu, sans aucune option
 *   - commande.php   : session_start() nu
 *   - deployment.php : @session_set_cookie_params + session_start() (chemin '/' seulement)
 *   - network.php    : bloc complet avec secure/httponly/samesite ← référence
 *   - log.php        : bloc complet avec secure/httponly/samesite ← référence
 */

if (session_status() !== PHP_SESSION_NONE) {
    // Session déjà démarrée (ex: inclus deux fois) — ne rien faire.
    return;
}

$_session_bootstrap_secure =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

@session_set_cookie_params([
    'lifetime' => 0,           // cookie de session (expire à la fermeture du navigateur)
    'path'     => '/',         // accessible sur tout le domaine, y compris /data/* et /pages/*
    'domain'   => '',          // domaine courant uniquement
    'secure'   => $_session_bootstrap_secure,
    'httponly' => true,        // inaccessible depuis JS → protège contre XSS
    'samesite' => 'Lax',       // protège contre CSRF sur navigation croisée
]);

session_start();

unset($_session_bootstrap_secure);