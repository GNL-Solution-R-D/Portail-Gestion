<?php

declare(strict_types=1);

/**
 * include/session_user.php
 *
 * Fonctions utilitaires pour lire les données utilisateur depuis $_SESSION['user'].
 *
 * Centralise les blocs répétés dans chaque page :
 *   - la chaîne de ?? pour le namespace k8s (6 fichiers concernés)
 *   - la lecture sécurisée de l'id, du nom, du siret, etc.
 *   - la vérification que la session user est bien un tableau
 *
 * Usage :
 *   require_once '../include/session_user.php';
 *
 *   $ns  = sessionUserNamespace();   // 'slapia'
 *   $id  = sessionUserId();          // 42
 *   $nom = sessionUserField('nom');  // 'Jean Dupont'
 */

if (!function_exists('sessionUserArray')) {
    /**
     * Retourne le tableau $_SESSION['user'] ou [] si absent/invalide.
     */
    function sessionUserArray(): array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user'])
            ? $_SESSION['user']
            : [];
    }
}

if (!function_exists('sessionUserId')) {
    /**
     * Retourne l'id de l'utilisateur connecté (int).
     * Retourne 0 si absent.
     */
    function sessionUserId(): int
    {
        return (int)(sessionUserArray()['id'] ?? 0);
    }
}

if (!function_exists('sessionUserField')) {
    /**
     * Lit un champ arbitraire de $_SESSION['user'] avec une valeur par défaut.
     *
     * @param string $key     Clé à lire
     * @param string $default Valeur par défaut si absent ou vide
     */
    function sessionUserField(string $key, string $default = ''): string
    {
        $v = sessionUserArray()[$key] ?? null;
        return ($v !== null && $v !== '') ? (string) $v : $default;
    }
}

if (!function_exists('sessionUserNamespace')) {
    /**
     * Retourne le namespace Kubernetes de l'utilisateur connecté.
     *
     * Teste les clés dans l'ordre pour couvrir les variations de nommage
     * selon les fournisseurs Keycloak / configurations.
     *
     * Avant : bloc de 5 coalescences ?? copié-collé dans chaque fichier :
     *   $ns = $_SESSION['user']['k8s_namespace']
     *       ?? $_SESSION['user']['k8sNamespace']
     *       ?? $_SESSION['user']['namespace_k8s']
     *       ?? $_SESSION['user']['k8s_ns']
     *       ?? $_SESSION['user']['namespace']
     *       ?? '';
     *
     * Fichiers concernés : dashboard.php, deployment.php, network.php,
     *                       log.php, stockage.php, projects_menu_api.php
     */
    function sessionUserNamespace(): string
    {
        $u = sessionUserArray();
        foreach (['k8s_namespace', 'k8sNamespace', 'namespace_k8s', 'k8s_ns', 'namespace'] as $key) {
            $v = $u[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return '';
    }
}

if (!function_exists('sessionUserHasNamespace')) {
    /**
     * Retourne true si un namespace k8s est configuré pour cet utilisateur.
     */
    function sessionUserHasNamespace(): bool
    {
        return sessionUserNamespace() !== '';
    }
}

if (!function_exists('sessionUserCsrf')) {
    /**
     * Retourne le token CSRF de la session, en le créant s'il n'existe pas.
     * Centralise la logique présente dans deployment.php et network.php.
     */
    function sessionUserCsrf(): string
    {
        if (!isset($_SESSION['csrf'])
            || !is_string($_SESSION['csrf'])
            || $_SESSION['csrf'] === ''
        ) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }
}