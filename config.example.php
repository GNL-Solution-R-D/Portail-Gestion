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
?>
