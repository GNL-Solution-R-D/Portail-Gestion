<?php
/**
 * Charge un éventuel fichier .env en local et expose un helper config()
 */

// Ne charge le fichier .env que s'il existe encore (utile en développement local)
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Essayez de charger .env uniquement pour le développement local ; en production sur Kubernetes,
// le fichier n’existe pas et loadEnv() ne fera rien.
loadEnv(__DIR__ . '/../.env');

/**
 * Récupère une valeur de configuration en privilégiant les variables d’environnement.
 *
 * @param string $key Nom de la variable (.env, Secret Kubernetes, etc.)
 * @param mixed $default Valeur par défaut si la variable est absente
 *
 * @return mixed
 */
function config(string $key, $default = null) {
    // getenv() renvoie false si la variable n’existe pas
    $value = getenv($key);
    if ($value === false) {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $value;
}


/**
 * Connexions MySQL optionnelles.
 *
 * L'authentification utilisateur est portée par Keycloak : l'application ne doit
 * donc plus échouer au chargement si la base historique n'est pas configurée ou
 * joignable. Les fonctionnalités qui utilisent encore MySQL doivent tester que
 * $pdo / $pdo_powerdns est bien une instance de PDO avant d'exécuter une requête.
 *
 * Définir DB_REQUIRED=true permet de conserver l'ancien comportement bloquant
 * dans les environnements qui exigent explicitement MySQL.
 */
$pdo = null;
$pdo_powerdns = null;

function configBool(string $key, bool $default = false): bool {
    $value = config($key, $default ? 'true' : 'false');
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function createOptionalPdo(string $databaseName, string $charset = 'utf8'): ?PDO {
    $host = trim((string) config('DB_HOST', ''));
    $port = trim((string) config('DB_PORT', '3306'));
    $username = (string) config('DB_USER', '');
    $password = (string) config('DB_PASSWORD', '');
    $databaseName = trim($databaseName);

    if ($host === '' || $databaseName === '' || $username === '') {
        return null;
    }

    return new PDO("mysql:host=$host;port=$port;dbname=$databaseName;charset=$charset", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

try {
    $pdo = createOptionalPdo((string) config('DB_NAME', ''), 'utf8');
} catch (PDOException $e) {
    error_log('Connexion MySQL principale indisponible (mode optionnel) : ' . $e->getMessage());
    if (configBool('DB_REQUIRED', false)) {
        http_response_code(500);
        echo 'Erreur de connexion à la base de données principale.';
        exit();
    }
    $pdo = null;
}

try {
    $pdo_powerdns = createOptionalPdo((string) config('PAME_POWERDNS_DB', 'oh_ns'), 'latin1');
} catch (PDOException $e) {
    error_log('Connexion PowerDNS indisponible (mode optionnel) : ' . $e->getMessage());
    if (configBool('DB_REQUIRED', false)) {
        http_response_code(500);
        echo 'Erreur de connexion à la base de données PowerDNS.';
        exit();
    }
    $pdo_powerdns = null;
}

/**
 * ── Keycloak : CONNEXION REST (page /connexion) ─────────────────────────────
 *
 * Le portail gestion utilise le même mécanisme que l'espace client : un
 * formulaire maison (identifiant + mot de passe) qui interroge Keycloak en
 * « Direct Access Grant », puis délègue la construction de $_SESSION['user']
 * à keycloakBuildSessionUser(). Voir include/keycloak_rest.php et
 * pages/connexion.php. Le flow « code » (/keycloak_login.php →
 * /keycloak_callback.php) reste disponible en repli SSO / MFA.
 *
 * ⚠️ Contrairement à l'espace client, il n'y a PAS de prise en charge des
 *    organisations Keycloak : pas de page /organisation, pas de scope
 *    « organization », pas d'exigence de namespace Kubernetes. Un grant
 *    réussi ouvre directement la session.
 *
 * À faire une fois côté Keycloak, sur le client KEYCLOAK_CLIENT_ID :
 *      - « Client authentication » = ON (client confidentiel, avec secret) ;
 *      - « Direct access grants »  = ON (sinon le formulaire renvoie
 *        « La connexion directe n'est pas activée côté serveur »).
 *
 * Réglages optionnels :
 *   KEYCLOAK_SCOPES               scopes du grant password.
 *                                 Défaut : « openid profile email ».
 *   KEYCLOAK_ALLOW_REGISTRATION=1 affiche le lien « Créer un compte ».
 *                                 Défaut : 0 (masqué).
 *   KEYCLOAK_LOGO_URL             logo affiché au-dessus de la carte.
 *   KEYCLOAK_ADMIN_CLIENT_ID      client dédié pour l'Admin REST (mot de passe
 *   KEYCLOAK_ADMIN_CLIENT_SECRET  oublié). Par défaut on réutilise le client
 *                                 du portail.
 *
 * Le lien « Mot de passe oublié » déclenche l'action Keycloak
 * UPDATE_PASSWORD via l'Admin REST : le compte de service a besoin des rôles
 * realm-management « manage-users » et « view-users ». Sans eux, la page
 * affiche quand même le message générique et n'envoie rien.
 */

/**
 * ── Keycloak : ANNUAIRE DES COMPTES (page /equipes) ─────────────────────────
 *
 * La carte « Membres de la structure » de /equipes est alimentée par les
 * COMPTES du realm Keycloak, lus via l'Admin REST API — plus par la table
 * « team » de n8n. Voir include/keycloak_directory.php et l'action
 * « team.list » de data/portail_api.php. La page est en LECTURE SEULE :
 * l'action « team.update » a été retirée, toute modification se fait dans la
 * console Keycloak.
 *
 * Aucune variable dédiée : l'Admin REST est appelée avec le client OIDC du
 * portail, déjà configuré pour la connexion —
 *     KEYCLOAK_CLIENT_ID  /  KEYCLOAK_CLIENT_SECRET
 * en grant client_credentials.
 *
 * Réglages optionnels :
 *   KEYCLOAK_USERS_MAX            nombre maximum de comptes remontés.
 *                                 Défaut : 500 (plafond dur : 2000). Au-delà,
 *                                 la page affiche « Liste tronquée ».
 *   KEYCLOAK_DIR_DEBUG=1          journalise chaque appel Admin REST réussi
 *                                 (chemin + nombre d'éléments). Utile pour
 *                                 diagnostiquer un 403 ; à laisser à 0 sinon.
 *
 * ⚠️ À faire une fois côté Keycloak, sur le client KEYCLOAK_CLIENT_ID :
 *        - « Client authentication » = ON ;
 *        - « Service accounts roles » = ON (active le client_credentials) ;
 *        - dans les rôles du client « realm-management », affecter au compte
 *          de service :
 *              · view-users   (lister et lire les comptes du realm)
 *    Sans ce rôle, l'API Keycloak répond 403 et la page affiche le message
 *    correspondant au lieu de la liste.
 */
?>
