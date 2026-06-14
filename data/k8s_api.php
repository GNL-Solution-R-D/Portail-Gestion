<?php

/**
 * Backend-only Kubernetes actions endpoint.
 *
 * Le navigateur appelle CE endpoint.
 * CE endpoint parle à l'API Kubernetes avec le ServiceAccount du Pod.
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('html_errors', '0');
@ini_set('display_startup_errors', '0');

ob_start();

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!$lastError || !in_array($lastError['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        http_response_code(500);
    }

    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur PHP',
        'detail' => (string)($lastError['message'] ?? 'Erreur fatale'),
    ], JSON_UNESCAPED_SLASHES);
});

// Cookie de session valable sur /pages/* ET /data/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function send_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Return env var only if set AND non-empty after trim. */
function getenv_non_empty(string $name): ?string {
    $v = getenv($name);
    if ($v === false) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

/**
 * Parse an image reference into repo+tag+registry info.
 * Examples:
 * - php:8.1-apache -> repo=php tag=8.1-apache registry=docker.io path=library/php
 * - docker.io/library/php:8.1-apache -> repo=docker.io/library/php tag=8.1-apache registry=docker.io path=library/php
 * - registry.example.com/ns/app:1.2.3 -> registry=registry.example.com path=ns/app
 */
function parse_image_ref(string $image): array {
    $noDigest = explode('@', $image, 2)[0];

    $repo = $noDigest;
    $tag  = null;

    $lastColon = strrpos($noDigest, ':');
    $lastSlash = strrpos($noDigest, '/');
    if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
        $repo = substr($noDigest, 0, $lastColon);
        $tag  = substr($noDigest, $lastColon + 1);
    }

    $registry = 'docker.io';
    $path = $repo;

    $first = explode('/', $repo, 2)[0];
    if (strpos($first, '.') !== false || strpos($first, ':') !== false || $first === 'localhost') {
        $registry = $first;
        $path = explode('/', $repo, 2)[1] ?? '';
    }

    if ($registry === 'docker.io') {
        // normalize docker hub paths for official images
        if (strpos($path, '/') === false) {
            $path = 'library/' . $path;
        }
    }

    return [
        'image' => $image,
        'repo' => $repo,
        'tag' => $tag,
        'registry' => $registry,
        'path' => $path,
    ];
}

/** Split a tag into leading version and suffix. */
function split_tag_version(string $tag): array {
    if (preg_match('/^(\d+(?:\.\d+){0,2})(.*)$/', $tag, $m)) {
        return ['version' => $m[1], 'suffix' => $m[2]];
    }
    return ['version' => null, 'suffix' => $tag];
}

/** Turn a version string "8.3.1" into a comparable tuple. */
function version_tuple(string $v): array {
    $parts = explode('.', $v);
    $t = [0, 0, 0];
    for ($i = 0; $i < 3; $i++) {
        if (isset($parts[$i]) && ctype_digit($parts[$i])) $t[$i] = (int)$parts[$i];
    }
    return $t;
}

/**
 * List tags from Docker Hub (public) for a repo path like "library/php" or "myuser/myapp".
 * Caches briefly in session to avoid hammering Docker Hub.
 */
function dockerhub_list_tags(string $repoPath, int $maxPages = 6, int $pageSize = 100): array {
    $repoPath = trim($repoPath, '/');

    // tiny session cache (5 min)
    $cacheKey = 'dh:' . $repoPath;
    if (isset($_SESSION['k8s_tag_cache'][$cacheKey]) && is_array($_SESSION['k8s_tag_cache'][$cacheKey])) {
        $c = $_SESSION['k8s_tag_cache'][$cacheKey];
        if (isset($c['at'], $c['tags']) && is_int($c['at']) && (time() - $c['at'] < 300) && is_array($c['tags'])) {
            return $c['tags'];
        }
    }

    $tags = [];
    $url = 'https://hub.docker.com/v2/repositories/' . implode('/', array_map('rawurlencode', explode('/', trim($repoPath, '/'))));
    $url .= '/tags?page_size=' . $pageSize;

    for ($page = 0; $page < $maxPages && $url; $page++) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 6,
                'header' => "User-Agent: gnl-dashboard\r\nAccept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) break;
        $json = json_decode($raw, true);
        if (!is_array($json)) break;

        $results = $json['results'] ?? [];
        if (!is_array($results)) $results = [];

        foreach ($results as $r) {
            $n = $r['name'] ?? null;
            if (is_string($n) && $n !== '') $tags[] = $n;
        }

        $next = $json['next'] ?? null;
        $url = is_string($next) && $next !== '' ? $next : '';
    }

    $tags = array_values(array_unique($tags));

    $_SESSION['k8s_tag_cache'][$cacheKey] = [
        'at' => time(),
        'tags' => $tags,
    ];

    return $tags;
}

function csrf_check_or_bypass(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        send_json(405, ['ok' => false, 'error' => 'Method not allowed']);
    }
    $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (isset($_SESSION['csrf']) && is_string($_SESSION['csrf']) && $_SESSION['csrf'] !== '' && !hash_equals($_SESSION['csrf'], $csrf)) {
        send_json(403, ['ok' => false, 'error' => 'CSRF invalid']);
    }
}

function is_dns_label(string $s): bool {
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $s);
}

function is_dns_subdomain(string $s): bool {
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $s);
}

function is_host(string $host): bool {
    // accept wildcard like *.example.com
    if (str_starts_with($host, '*.')) {
        return is_dns_subdomain(substr($host, 2));
    }
    return is_dns_subdomain($host);
}

function managed_annotation_key(): string {
    return 'gnl-solution.fr/managed-by';
}

function entry_id_annotation_key(): string {
    return 'gnl-solution.fr/entry-id';
}

function deployment_storage_mounts(array $deploymentData): array
{
    $volumes = $deploymentData['spec']['template']['spec']['volumes'] ?? [];
    if (!is_array($volumes)) {
        $volumes = [];
    }

    $pvcByVolumeName = [];
    foreach ($volumes as $volume) {
        if (!is_array($volume)) {
            continue;
        }

        $volumeName = $volume['name'] ?? null;
        $claimName = $volume['persistentVolumeClaim']['claimName'] ?? null;

        if (!is_string($volumeName) || $volumeName === '' || !is_string($claimName) || $claimName === '') {
            continue;
        }

        $pvcByVolumeName[$volumeName] = [
            'volumeName' => $volumeName,
            'claimName' => $claimName,
            'readOnly' => (bool)($volume['persistentVolumeClaim']['readOnly'] ?? false),
        ];
    }

    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) {
        $containers = [];
    }

    $storageMounts = [];
    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $containerName = $container['name'] ?? null;
        if (!is_string($containerName) || $containerName === '') {
            continue;
        }

        $volumeMounts = $container['volumeMounts'] ?? [];
        if (!is_array($volumeMounts)) {
            $volumeMounts = [];
        }

        foreach ($volumeMounts as $mount) {
            if (!is_array($mount)) {
                continue;
            }

            $volumeName = $mount['name'] ?? null;
            $mountPath = $mount['mountPath'] ?? null;

            if (!is_string($volumeName) || $volumeName === '' || !isset($pvcByVolumeName[$volumeName])) {
                continue;
            }

            if (!is_string($mountPath) || $mountPath === '') {
                continue;
            }

            $meta = $pvcByVolumeName[$volumeName];
            $storageMounts[] = [
                'container' => $containerName,
                'volumeName' => $meta['volumeName'],
                'claimName' => $meta['claimName'],
                'mountPath' => $mountPath,
                'subPath' => is_string($mount['subPath'] ?? null) ? $mount['subPath'] : null,
                'readOnly' => (bool)($mount['readOnly'] ?? false) || (bool)$meta['readOnly'],
            ];
        }
    }

    usort($storageMounts, static function (array $a, array $b): int {
        return [$a['claimName'], $a['container'], $a['mountPath']]
            <=> [$b['claimName'], $b['container'], $b['mountPath']];
    });

    return $storageMounts;
}



function ingress_name_for(string $id, string $host, string $path): string {
    $id = preg_replace('/[^a-z0-9-]/', '-', strtolower($id));
    $id = trim((string)$id, '-');
    if ($id === '') {
        $id = substr(sha1($host . '|' . $path), 0, 10);
    }
    $name = 'public-' . $id;
    if (strlen($name) > 63) {
        $name = 'public-' . substr(sha1($name), 0, 20);
    }
    // ensure dns label
    $name = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
    $name = trim($name, '-');
    if (!is_dns_label($name)) {
        $name = 'public-' . substr(sha1($host . '|' . $path), 0, 20);
    }
    return $name;
}

function parse_tls_secret_cert(array $secret): ?array {
    // expects Kubernetes Secret object
    $data = $secret['data'] ?? null;
    if (!is_array($data)) return null;
    $crtB64 = $data['tls.crt'] ?? null;
    if (!is_string($crtB64) || $crtB64 === '') return null;
    $pem = base64_decode($crtB64, true);
    if (!is_string($pem) || $pem === '') return null;
    if (!function_exists('openssl_x509_parse')) return null;

    $info = @openssl_x509_parse($pem);
    if (!is_array($info)) return null;

    $notAfter = $info['validTo_time_t'] ?? null;
    if (!is_int($notAfter)) return null;

    $now = time();
    $days = (int)floor(($notAfter - $now) / 86400);

    return [
        'notAfter' => gmdate('c', $notAfter),
        'daysRemaining' => $days,
        'expired' => $notAfter <= $now,
        'subject' => is_array($info['subject'] ?? null) ? $info['subject'] : null,
        'issuer' => is_array($info['issuer'] ?? null) ? $info['issuer'] : null,
    ];
}

function normalize_absolute_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return '/' . implode('/', $parts);
}

function path_is_within_root(string $path, string $root): bool
{
    $path = normalize_absolute_path($path);
    $root = normalize_absolute_path($root);

    if ($root === '/') {
        return true;
    }

    return $path === $root || str_starts_with($path, $root . '/');
}

function select_pod_for_deployment(KubernetesClient $k8s, string $namespace, array $deploymentData): ?array
{
    $matchLabels = $deploymentData['spec']['selector']['matchLabels'] ?? null;
    if (!is_array($matchLabels) || $matchLabels === []) {
        return null;
    }

    $parts = [];
    foreach ($matchLabels as $k => $v) {
        if (!is_string($k) || $k === '' || !is_string($v) || $v === '') {
            continue;
        }
        $parts[] = $k . '=' . $v;
    }
    if ($parts === []) {
        return null;
    }

    $podsRaw = $k8s->listPods($namespace, implode(',', $parts));
    $items = $podsRaw['items'] ?? [];
    if (!is_array($items) || $items === []) {
        return null;
    }

    usort($items, static function (array $a, array $b): int {
        $aPhase = (string)($a['status']['phase'] ?? '');
        $bPhase = (string)($b['status']['phase'] ?? '');
        $aRunning = $aPhase === 'Running' ? 0 : 1;
        $bRunning = $bPhase === 'Running' ? 0 : 1;

        $aReady = 1;
        foreach (($a['status']['containerStatuses'] ?? []) as $status) {
            if (is_array($status) && !empty($status['ready'])) {
                $aReady = 0;
                break;
            }
        }

        $bReady = 1;
        foreach (($b['status']['containerStatuses'] ?? []) as $status) {
            if (is_array($status) && !empty($status['ready'])) {
                $bReady = 0;
                break;
            }
        }

        $aCreated = (string)($a['metadata']['creationTimestamp'] ?? '');
        $bCreated = (string)($b['metadata']['creationTimestamp'] ?? '');

        return [$aRunning, $aReady, $aCreated, (string)($a['metadata']['name'] ?? '')]
            <=> [$bRunning, $bReady, $bCreated, (string)($b['metadata']['name'] ?? '')];
    });

    foreach ($items as $pod) {
        if (!is_array($pod)) {
            continue;
        }

        $name = $pod['metadata']['name'] ?? null;
        if (!is_string($name) || $name === '') {
            continue;
        }

        return $pod;
    }

    return null;
}

function storage_list_script(): string
{
    return <<<'SH'
ROOT="$1"
TARGET="$2"

if command -v python3 >/dev/null 2>&1; then
  python3 - "$ROOT" "$TARGET" <<'PY'
import json, os, sys
root = os.path.realpath(sys.argv[1])
target_in = sys.argv[2]
target = os.path.realpath(target_in)
if not os.path.isdir(root):
    print(json.dumps({"ok": False, "error": f"Le point de montage n'existe pas: {root}"}))
    raise SystemExit(2)
if target != root and not target.startswith(root + os.sep):
    print(json.dumps({"ok": False, "error": "Chemin hors du montage autorisé."}))
    raise SystemExit(3)
if not os.path.isdir(target):
    print(json.dumps({"ok": False, "error": f"Ce chemin n'est pas un dossier: {target_in}"}))
    raise SystemExit(4)
items = []
with os.scandir(target) as entries:
    for entry in entries:
        try:
            st = entry.stat(follow_symlinks=False)
        except FileNotFoundError:
            continue
        item_path = os.path.realpath(entry.path) if entry.is_dir(follow_symlinks=False) else os.path.normpath(entry.path)
        item = {
            "name": entry.name,
            "path": item_path,
            "type": "dir" if entry.is_dir(follow_symlinks=False) else "file",
            "size": None if entry.is_dir(follow_symlinks=False) else int(st.st_size),
            "mtime": int(st.st_mtime),
            "subPath": os.path.relpath(item_path, root),
        }
        if item["subPath"] == ".":
            item["subPath"] = ""
        items.append(item)
items.sort(key=lambda x: (0 if x["type"] == "dir" else 1, x["name"].lower(), x["name"]))
print(json.dumps({
    "ok": True,
    "root": root,
    "path": target,
    "items": items,
}, separators=(",", ":")))
PY
elif command -v php >/dev/null 2>&1; then
  php -r '
$rootInput = (string)($argv[1] ?? "");
$targetInput = (string)($argv[2] ?? "");
if ($rootInput === "" || $targetInput === "") {
    fwrite(STDOUT, json_encode(["ok" => false, "error" => "Arguments de navigation manquants."], JSON_UNESCAPED_SLASHES));
    exit(1);
}
$root = realpath($rootInput);
$target = realpath($targetInput);
if ($root === false || !is_dir($root)) {
    fwrite(STDOUT, json_encode(["ok" => false, "error" => "Le point de montage n'\''existe pas: " . $rootInput], JSON_UNESCAPED_SLASHES));
    exit(2);
}
if ($target === false || !is_dir($targetInput)) {
    fwrite(STDOUT, json_encode(["ok" => false, "error" => "Ce chemin n'\''est pas un dossier: " . $targetInput], JSON_UNESCAPED_SLASHES));
    exit(4);
}
if ($target !== $root && strpos($target, $root . DIRECTORY_SEPARATOR) !== 0) {
    fwrite(STDOUT, json_encode(["ok" => false, "error" => "Chemin hors du montage autorisé."], JSON_UNESCAPED_SLASHES));
    exit(3);
}
$entries = @scandir($targetInput);
if (!is_array($entries)) {
    fwrite(STDOUT, json_encode(["ok" => false, "error" => "Lecture du dossier impossible."], JSON_UNESCAPED_SLASHES));
    exit(5);
}
$items = [];
foreach ($entries as $entry) {
    if ($entry === "." || $entry === "..") continue;
    $entryPath = rtrim($targetInput, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry;
    $isDir = is_dir($entryPath);
    $realEntry = $isDir ? realpath($entryPath) : $entryPath;
    $mtime = @filemtime($entryPath);
    $size = $isDir ? null : @filesize($entryPath);
    $subPath = "";
    if (is_string($realEntry) && $realEntry !== "" && strpos($realEntry, $root) === 0) {
        $subPath = ltrim(substr($realEntry, strlen($root)), DIRECTORY_SEPARATOR);
    }
    $items[] = [
        "name" => $entry,
        "path" => $realEntry ?: $entryPath,
        "type" => $isDir ? "dir" : "file",
        "size" => $isDir ? null : ($size === false ? null : (int)$size),
        "mtime" => $mtime === false ? null : (int)$mtime,
        "subPath" => $subPath,
    ];
}
usort($items, static function(array $a, array $b): int {
    return [($a["type"] ?? "") === "dir" ? 0 : 1, strtolower((string)($a["name"] ?? "")), (string)($a["name"] ?? "")]
        <=> [($b["type"] ?? "") === "dir" ? 0 : 1, strtolower((string)($b["name"] ?? "")), (string)($b["name"] ?? "")];
});
fwrite(STDOUT, json_encode([
    "ok" => true,
    "root" => $root,
    "path" => $target,
    "items" => $items,
], JSON_UNESCAPED_SLASHES));
' "$ROOT" "$TARGET"
else
  printf '%s\n' '{"ok":false,"error":"Aucun runtime compatible trouvé dans le conteneur pour explorer les fichiers (python3 ou php requis)."}'
  exit 127
fi
SH;
}

function explain_storage_exec_error(Throwable $e): string
{
    $message = trim($e->getMessage());

    if (str_contains($message, 'pods/exec')) {
        return 'Le ServiceAccount du dashboard ne peut pas ouvrir un exec dans le pod. '
            . 'Ajoute la permission RBAC sur le sous-ressource pods/exec avec le verbe get '
            . 'pour le namespace ciblé.';
    }

    return $message;
}

function deployment_container_names(array $deploymentData): array
{
    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) {
        return [];
    }

    $names = [];
    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $name = $container['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);

    return $names;
}

function deployment_secret_names(array $deploymentData): array
{
    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) {
        return [];
    }

    $secretNames = [];
    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $env = $container['env'] ?? [];
        if (is_array($env)) {
            foreach ($env as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $secretName = $item['valueFrom']['secretKeyRef']['name'] ?? null;
                if (is_string($secretName) && $secretName !== '') {
                    $secretNames[] = $secretName;
                }
            }
        }

        $envFrom = $container['envFrom'] ?? [];
        if (!is_array($envFrom)) {
            continue;
        }

        foreach ($envFrom as $item) {
            if (!is_array($item)) {
                continue;
            }

            $secretName = $item['secretRef']['name'] ?? null;
            if (is_string($secretName) && $secretName !== '') {
                $secretNames[] = $secretName;
            }
        }
    }

    $secretNames = array_values(array_unique($secretNames));
    sort($secretNames, SORT_STRING);

    return $secretNames;
}

function secret_env_entries_from_deployment(KubernetesClient $k8s, string $namespace, array $deploymentData): array
{
    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) {
        return [
            'entries' => [],
            'secretErrors' => [],
        ];
    }

    $secretCache = [];
    $secretErrors = [];

    $loadSecretKeys = static function (string $secretName) use ($k8s, $namespace, &$secretCache, &$secretErrors): array {
        if (isset($secretCache[$secretName])) {
            return $secretCache[$secretName];
        }

        try {
            $secret = $k8s->getSecret($namespace, $secretName);
            $data = $secret['data'] ?? [];
            if (!is_array($data)) {
                $data = [];
            }

            $keys = [];
            foreach ($data as $key => $_value) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key;
                }
            }

            sort($keys, SORT_STRING);
            $secretCache[$secretName] = $keys;
            return $keys;
        } catch (Throwable $e) {
            $secretErrors[$secretName] = $e->getMessage();
            $secretCache[$secretName] = [];
            return [];
        }
    };

    $entries = [];

    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }

        $containerName = $container['name'] ?? null;
        if (!is_string($containerName) || $containerName === '') {
            continue;
        }

        $env = $container['env'] ?? [];
        if (is_array($env)) {
            foreach ($env as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $varName = $item['name'] ?? null;
                $secretName = $item['valueFrom']['secretKeyRef']['name'] ?? null;
                $secretKey = $item['valueFrom']['secretKeyRef']['key'] ?? null;
                $optional = (bool)($item['valueFrom']['secretKeyRef']['optional'] ?? false);

                if (!is_string($varName) || $varName === '' || !is_string($secretName) || $secretName === '' || !is_string($secretKey) || $secretKey === '') {
                    continue;
                }

                $entries[] = [
                    'container' => $containerName,
                    'envName' => $varName,
                    'secretName' => $secretName,
                    'secretKey' => $secretKey,
                    'optional' => $optional,
                    'source' => 'secretKeyRef',
                    'masked' => true,
                ];
            }
        }

        $envFrom = $container['envFrom'] ?? [];
        if (!is_array($envFrom)) {
            continue;
        }

        foreach ($envFrom as $item) {
            if (!is_array($item)) {
                continue;
            }

            $secretName = $item['secretRef']['name'] ?? null;
            $prefix = $item['prefix'] ?? '';
            $optional = (bool)($item['secretRef']['optional'] ?? false);

            if (!is_string($secretName) || $secretName === '') {
                continue;
            }
            if (!is_string($prefix)) {
                $prefix = '';
            }

            $keys = $loadSecretKeys($secretName);
            foreach ($keys as $secretKey) {
                $entries[] = [
                    'container' => $containerName,
                    'envName' => $prefix . $secretKey,
                    'secretName' => $secretName,
                    'secretKey' => $secretKey,
                    'optional' => $optional,
                    'source' => 'secretRef',
                    'prefix' => $prefix !== '' ? $prefix : null,
                    'masked' => true,
                ];
            }
        }
    }

    usort($entries, static function (array $a, array $b): int {
        return [
            (string)($a['container'] ?? ''),
            (string)($a['envName'] ?? ''),
            (string)($a['secretName'] ?? ''),
            (string)($a['secretKey'] ?? ''),
        ] <=> [
            (string)($b['container'] ?? ''),
            (string)($b['envName'] ?? ''),
            (string)($b['secretName'] ?? ''),
            (string)($b['secretKey'] ?? ''),
        ];
    });

    return [
        'entries' => $entries,
        'secretErrors' => $secretErrors,
    ];
}

if (!isset($_SESSION['user'])) {
    send_json(401, [
        'ok' => false,
        'error' => 'Unauthorized (cookie de session absent ? vérifie session.cookie_path)',
    ]);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) ($_SESSION['user']['id'] ?? 0))) {
    accountSessionsDestroyPhpSession();
    send_json(401, [
        'ok' => false,
        'error' => 'Cette session a été déconnectée depuis vos paramètres.',
    ]);
}

accountSessionsTouchCurrent($pdo, (int) ($_SESSION['user']['id'] ?? 0));

$user = $_SESSION['user'];
if (!is_array($user)) {
    send_json(500, [
        'ok' => false,
        'error' => 'Session user invalide (attendu: array).',
    ]);
}

require_once __DIR__ . '/KubernetesClient.php';

// Namespace vient du profil utilisateur (session).
$namespace = $user['k8s_namespace']
    ?? $user['k8sNamespace']
    ?? $user['namespace_k8s']
    ?? $user['k8s_ns']
    ?? $user['namespace']
    ?? null;

if (!is_string($namespace) || $namespace === '') {
    send_json(400, [
        'ok' => false,
        'error' => 'Namespace manquant dans le profil utilisateur (ex: user[k8s_namespace]).',
    ]);
}

if (!is_dns_subdomain($namespace)) {
    send_json(400, ['ok' => false, 'error' => 'Namespace invalide.']);
}

$action = (string)($_GET['action'] ?? '');

try {
    $k8s = new KubernetesClient();

    switch ($action) {
        case 'list_deployments': {
            $data = $k8s->listDeployments($namespace);
            $items = $data['items'] ?? [];
            $deployments = [];

            foreach ($items as $d) {
                $name = $d['metadata']['name'] ?? null;
                if (!is_string($name) || $name === '') continue;

                $deployments[] = [
                    'name' => $name,
                    'replicas' => (int)($d['spec']['replicas'] ?? 0),
                    'ready' => (int)($d['status']['readyReplicas'] ?? 0),
                    'updated' => (int)($d['status']['updatedReplicas'] ?? 0),
                    'available' => (int)($d['status']['availableReplicas'] ?? 0),
                    'createdAt' => is_string($d['metadata']['creationTimestamp'] ?? null) ? $d['metadata']['creationTimestamp'] : null,
                ];
            }

            usort($deployments, fn($a, $b) => strcmp($a['name'], $b['name']));
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployments' => $deployments]);
        }

        case 'get_deployment': {
            $deployment = (string)($_GET['deployment'] ?? $_GET['name'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            $d = $k8s->getDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $d]);
        }


        case 'get_deployment_storage': {
            $deployment = (string)($_GET['deployment'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $mounts = deployment_storage_mounts($d);

            $claims = [];
            foreach ($mounts as $mount) {
                if (is_string($mount['claimName'] ?? null) && $mount['claimName'] !== '') {
                    $claims[$mount['claimName']] = true;
                }
            }

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'mounts' => $mounts,
                'claimsCount' => count($claims),
                'mountsCount' => count($mounts),
            ]);
        }

        case 'list_files': {
            $deployment = (string)($_GET['deployment'] ?? '');
            $container = trim((string)($_GET['container'] ?? ''));
            $claim = trim((string)($_GET['claim'] ?? ''));
            $mountPath = normalize_absolute_path((string)($_GET['mountPath'] ?? '/'));
            $path = normalize_absolute_path((string)($_GET['path'] ?? $mountPath));

            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            if ($container === '' || !is_dns_label($container)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de container invalide.']);
            }
            if ($claim === '' || !is_dns_label($claim)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de PVC invalide.']);
            }
            if (!path_is_within_root($mountPath, $mountPath)) {
                send_json(400, ['ok' => false, 'error' => 'MountPath invalide.']);
            }
            if (!path_is_within_root($path, $mountPath)) {
                send_json(400, ['ok' => false, 'error' => 'Chemin hors du volume autorisé.']);
            }

            $deploymentData = $k8s->getDeployment($namespace, $deployment);
            $mounts = deployment_storage_mounts($deploymentData);

            $selectedMount = null;
            foreach ($mounts as $mount) {
                if (($mount['container'] ?? null) === $container
                    && ($mount['claimName'] ?? null) === $claim
                    && normalize_absolute_path((string)($mount['mountPath'] ?? '/')) === $mountPath) {
                    $selectedMount = $mount;
                    break;
                }
            }

            if ($selectedMount === null) {
                send_json(404, ['ok' => false, 'error' => 'Montage introuvable pour ce deployment.']);
            }

            $pod = select_pod_for_deployment($k8s, $namespace, $deploymentData);
            if ($pod === null) {
                send_json(404, ['ok' => false, 'error' => 'Aucun pod disponible pour ce deployment.']);
            }

            $podName = (string)($pod['metadata']['name'] ?? '');
            if ($podName === '') {
                send_json(404, ['ok' => false, 'error' => 'Pod invalide.']);
            }

            try {
                $exec = $k8s->execInPod(
                    $namespace,
                    $podName,
                    ['sh', '-lc', storage_list_script(), 'storage-list', $mountPath, $path],
                    $container
                );
            } catch (Throwable $e) {
                send_json(403, ['ok' => false, 'error' => explain_storage_exec_error($e)]);
            }

            $stdout = trim((string)($exec['stdout'] ?? ''));
            $stderr = trim((string)($exec['stderr'] ?? ''));
            $error = trim((string)($exec['error'] ?? ''));
            $exitCode = (int)($exec['exitCode'] ?? 0);

            if ($stdout === '') {
                $message = $stderr !== '' ? $stderr : ($error !== '' ? $error : 'Réponse vide du conteneur.');
                send_json(502, ['ok' => false, 'error' => 'Exploration du stockage impossible: ' . $message]);
            }

            $listed = json_decode($stdout, true);
            if (!is_array($listed)) {
                $message = preg_replace('/\s+/', ' ', trim($stdout));
                if ($stderr !== '') {
                    $message .= ($message !== '' ? ' | ' : '') . $stderr;
                }
                send_json(502, ['ok' => false, 'error' => 'Réponse d’exploration invalide: ' . $message]);
            }

            if (!($listed['ok'] ?? false)) {
                $message = is_string($listed['error'] ?? null) ? $listed['error'] : 'Erreur inconnue.';
                $status = $exitCode === 3 ? 403 : 400;
                send_json($status, ['ok' => false, 'error' => $message]);
            }

            $items = $listed['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }

            $normalizedItems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = $item['name'] ?? null;
                $itemPath = $item['path'] ?? null;
                $type = $item['type'] ?? 'file';
                if (!is_string($name) || $name === '' || !is_string($itemPath) || $itemPath === '') {
                    continue;
                }

                $normalizedPath = normalize_absolute_path($itemPath);
                if (!path_is_within_root($normalizedPath, $mountPath)) {
                    continue;
                }

                $normalizedItems[] = [
                    'name' => $name,
                    'path' => $normalizedPath,
                    'type' => ($type === 'dir' || $type === 'directory') ? 'dir' : 'file',
                    'size' => is_numeric($item['size'] ?? null) ? (int)$item['size'] : null,
                    'mtime' => is_numeric($item['mtime'] ?? null)
                        ? gmdate('c', (int)$item['mtime'])
                        : (is_string($item['mtime'] ?? null) ? $item['mtime'] : null),
                    'subPath' => is_string($item['subPath'] ?? null) ? $item['subPath'] : null,
                ];
            }

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'pod' => $podName,
                'container' => $container,
                'claim' => $claim,
                'mountPath' => $mountPath,
                'path' => is_string($listed['path'] ?? null) ? normalize_absolute_path((string)$listed['path']) : $path,
                'items' => $normalizedItems,
                'stderr' => $stderr !== '' ? $stderr : null,
            ]);
        }
 

        case 'restart_deployment': {
            csrf_check_or_bypass();

            $deployment = (string)($_POST['name'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $k8s->restartDeployment($namespace, $deployment);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment]);
        }

        case 'list_pods_for_deployment': {
            $deployment = (string)($_GET['deployment'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $matchLabels = $d['spec']['selector']['matchLabels'] ?? null;
            if (!is_array($matchLabels) || !$matchLabels) {
                send_json(200, ['ok' => true, 'namespace' => $namespace, 'pods' => []]);
            }

            $parts = [];
            foreach ($matchLabels as $k => $v) {
                if (!is_string($k) || $k === '' || !is_string($v) || $v === '') continue;
                $parts[] = $k . '=' . $v;
            }
            if (!$parts) {
                send_json(200, ['ok' => true, 'namespace' => $namespace, 'pods' => []]);
            }

            $podsRaw = $k8s->listPods($namespace, implode(',', $parts));
            $items = $podsRaw['items'] ?? [];
            if (!is_array($items)) $items = [];

            $pods = [];
            foreach ($items as $p) {
                if (!is_array($p)) continue;
                $name = $p['metadata']['name'] ?? null;
                if (!is_string($name) || $name === '') continue;

                $containers = [];
                $containerItems = $p['spec']['containers'] ?? [];
                if (is_array($containerItems)) {
                    foreach ($containerItems as $c) {
                        if (!is_array($c)) continue;
                        $cn = $c['name'] ?? null;
                        if (!is_string($cn) || $cn === '') continue;
                        $containers[] = ['name' => $cn];
                    }
                }

                $pods[] = [
                    'name' => $name,
                    'phase' => is_string($p['status']['phase'] ?? null) ? $p['status']['phase'] : null,
                    'containers' => $containers,
                ];
            }

            usort($pods, fn($a, $b) => strcmp((string)$a['name'], (string)$b['name']));
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'pods' => $pods]);
        }

        case 'pod_logs_tail': {
            $pod = (string)($_GET['pod'] ?? '');
            $container = (string)($_GET['container'] ?? '');
            $tail = (int)($_GET['tail'] ?? 200);
            $timestampsRaw = (string)($_GET['timestamps'] ?? '1');

            if ($pod === '' || !is_dns_label($pod)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de pod invalide.']);
            }

            $timestamps = !in_array(strtolower($timestampsRaw), ['0', 'false', 'off', 'no'], true);
            $text = $k8s->getPodLogs($namespace, $pod, $container !== '' ? $container : null, $tail, $timestamps);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'pod' => $pod,
                'container' => $container !== '' ? $container : null,
                'tail' => max(1, min($tail, 5000)),
                'timestamps' => $timestamps,
                'text' => $text,
            ]);
        }

        case 'list_deployment_secret_variables': {
            $deployment = (string)($_GET['deployment'] ?? $_GET['name'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $secretVars = secret_env_entries_from_deployment($k8s, $namespace, $d);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'containers' => deployment_container_names($d),
                'secrets' => deployment_secret_names($d),
                'entries' => $secretVars['entries'],
                'secretErrors' => $secretVars['secretErrors'],
            ]);
        }

        case 'create_deployment_secret_variable': {
            csrf_check_or_bypass();

            $deployment = (string)($_POST['name'] ?? '');
            $envName = trim((string)($_POST['env'] ?? ''));
            $secretName = trim((string)($_POST['secret'] ?? ''));
            $newValue = (string)($_POST['value'] ?? '');
            $secretKey = $envName;

            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            if ($envName === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de variable invalide.']);
            }
            if ($secretName === '' || !is_dns_label($secretName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de secret invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $allowedSecrets = deployment_secret_names($d);
            if (!in_array($secretName, $allowedSecrets, true)) {
                send_json(400, ['ok' => false, 'error' => 'Le secret sélectionné n’est pas disponible pour ce deployment.']);
            }

            $containers = $d['spec']['template']['spec']['containers'] ?? [];
            if (!is_array($containers)) {
                $containers = [];
            }

            $containersUsingSecret = [];
            foreach ($containers as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $containerName = (string)($candidate['name'] ?? '');
                if ($containerName === '') {
                    continue;
                }
                $containerEnvFrom = $candidate['envFrom'] ?? [];
                if (!is_array($containerEnvFrom)) {
                    continue;
                }
                foreach ($containerEnvFrom as $envFromEntry) {
                    if (!is_array($envFromEntry)) {
                        continue;
                    }
                    if (($envFromEntry['secretRef']['name'] ?? '') !== $secretName) {
                        continue;
                    }
                    $containersUsingSecret[] = $containerName;
                    break;
                }
            }

            if ($containersUsingSecret === []) {
                send_json(400, ['ok' => false, 'error' => 'Le secret sélectionné n’est pas injecté via envFrom dans ce deployment.']);
            }

            $existingSecretEnv = secret_env_entries_from_deployment($k8s, $namespace, $d);
            foreach (($existingSecretEnv['entries'] ?? []) as $envEntry) {
                if (!is_array($envEntry)) {
                    continue;
                }
                if (($envEntry['secretName'] ?? '') !== $secretName) {
                    continue;
                }
                if (!in_array((string)($envEntry['container'] ?? ''), $containersUsingSecret, true)) {
                    continue;
                }
                if (($envEntry['envName'] ?? '') === $envName) {
                    send_json(409, ['ok' => false, 'error' => 'Une variable avec ce nom existe déjà dans ce secret pour ce deployment.']);
                }
            }

            $k8s->getSecret($namespace, $secretName);
            $k8s->patchSecretDataKey($namespace, $secretName, $secretKey, $newValue);
            $k8s->restartDeployment($namespace, $deployment);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'containers' => $containersUsingSecret,
                'envName' => $envName,
                'secretName' => $secretName,
                'secretKey' => $secretKey,
                'valueMasked' => true,
                'deploymentRestarted' => true,
            ]);
        }

        case 'update_deployment_secret_variable': {
            csrf_check_or_bypass();

            $deployment = (string)($_POST['name'] ?? '');
            $secretName = (string)($_POST['secret'] ?? '');
            $secretKey = (string)($_POST['key'] ?? '');
            $envName = (string)($_POST['env'] ?? '');
            $container = (string)($_POST['container'] ?? '');

            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            if ($secretName === '' || !is_dns_label($secretName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de secret invalide.']);
            }
            if ($secretKey === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $secretKey)) {
                send_json(400, ['ok' => false, 'error' => 'Clé de secret invalide.']);
            }
            if (!array_key_exists('value', $_POST)) {
                send_json(400, ['ok' => false, 'error' => 'Nouvelle valeur manquante.']);
            }

            $newValue = (string)$_POST['value'];

            $d = $k8s->getDeployment($namespace, $deployment);
            $secretVars = secret_env_entries_from_deployment($k8s, $namespace, $d);
            $matchedEntry = null;

            foreach ($secretVars['entries'] as $entry) {
                if (($entry['secretName'] ?? '') !== $secretName) {
                    continue;
                }
                if (($entry['secretKey'] ?? '') !== $secretKey) {
                    continue;
                }
                if ($envName !== '' && ($entry['envName'] ?? '') !== $envName) {
                    continue;
                }
                if ($container !== '' && ($entry['container'] ?? '') !== $container) {
                    continue;
                }

                $matchedEntry = $entry;
                break;
            }

            if (!is_array($matchedEntry)) {
                send_json(404, ['ok' => false, 'error' => 'Variable secrète introuvable pour ce deployment.']);
            }

            $k8s->patchSecretDataKey($namespace, $secretName, $secretKey, $newValue);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'container' => $matchedEntry['container'] ?? null,
                'envName' => $matchedEntry['envName'] ?? null,
                'secretName' => $secretName,
                'secretKey' => $secretKey,
                'valueUpdated' => true,
                'valueMasked' => true,
            ]);
        }

        case 'delete_deployment_secret_variable': {
            csrf_check_or_bypass();

            $deployment = (string)($_POST['name'] ?? '');
            $secretName = (string)($_POST['secret'] ?? '');
            $secretKey = (string)($_POST['key'] ?? '');
            $envName = (string)($_POST['env'] ?? '');
            $container = (string)($_POST['container'] ?? '');

            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            if ($secretName === '' || !is_dns_label($secretName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de secret invalide.']);
            }
            if ($secretKey === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $secretKey)) {
                send_json(400, ['ok' => false, 'error' => 'Clé de secret invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $secretVars = secret_env_entries_from_deployment($k8s, $namespace, $d);
            $matchedEntry = null;

            foreach ($secretVars['entries'] as $entry) {
                if (($entry['secretName'] ?? '') !== $secretName) {
                    continue;
                }
                if (($entry['secretKey'] ?? '') !== $secretKey) {
                    continue;
                }
                if ($envName !== '' && ($entry['envName'] ?? '') !== $envName) {
                    continue;
                }
                if ($container !== '' && ($entry['container'] ?? '') !== $container) {
                    continue;
                }

                $matchedEntry = $entry;
                break;
            }

            if (!is_array($matchedEntry)) {
                send_json(404, ['ok' => false, 'error' => 'Variable secrète introuvable pour ce deployment.']);
            }
            if (($matchedEntry['source'] ?? '') !== 'secretRef') {
                send_json(400, ['ok' => false, 'error' => 'La suppression est réservée aux variables injectées via envFrom.']);
            }

            $k8s->getSecret($namespace, $secretName);
            $k8s->deleteSecretDataKey($namespace, $secretName, $secretKey);

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deployment,
                'container' => $matchedEntry['container'] ?? null,
                'envName' => $matchedEntry['envName'] ?? null,
                'secretName' => $secretName,
                'secretKey' => $secretKey,
                'deleted' => true,
            ]);
        }

        // -------- Images (dropdown) --------
        case 'list_deployment_images': {
            $deployment = (string)($_GET['deployment'] ?? $_GET['name'] ?? '');
            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }

            $d = $k8s->getDeployment($namespace, $deployment);
            $containers = $d['spec']['template']['spec']['containers'] ?? [];
            if (!is_array($containers)) $containers = [];

            $out = [];
            foreach ($containers as $c) {
                if (!is_array($c)) continue;
                $cName = $c['name'] ?? '';
                $img   = $c['image'] ?? '';
                if (!is_string($cName) || $cName === '' || !is_string($img) || $img === '') continue;

                $ref = parse_image_ref($img);

                $currentTag = is_string($ref['tag']) ? $ref['tag'] : null;
                $availableTags = [];
                $latestTag = null;
                $hasUpdate = false;
                $note = null;

                // Default: try Docker Hub public tags for docker.io images only (for now).
                if ($currentTag === null) {
                    $note = 'Tag absent (image sans ":tag").';
                } elseif ($ref['registry'] !== 'docker.io') {
                    $note = 'Repertoire "' . $ref['registry'] . '" non supporté.';
                } else {
                    $tags = dockerhub_list_tags((string)$ref['path']);

                    $split = split_tag_version($currentTag);
                    $wantSuffix = (string)($split['suffix'] ?? '');

                    if ($split['version'] === null) {
                        // current tag isn't a version (e.g., "latest"): just show a small alphabetical list.
                        sort($tags, SORT_STRING);
                        $availableTags = array_slice($tags, 0, 50);
                    } else {
                        $cands = [];
                        foreach ($tags as $t) {
                            if (!is_string($t) || $t === '') continue;
                            $s = split_tag_version($t);
                            if ($s['version'] === null) continue;
                            if ((string)$s['suffix'] !== $wantSuffix) continue;

                            $cands[] = [
                                'tag' => $t,
                                'tuple' => version_tuple((string)$s['version']),
                            ];
                        }

                        usort($cands, function($a, $b){
                            $ta = $a['tuple']; $tb = $b['tuple'];
                            for ($i = 0; $i < 3; $i++) {
                                if ($ta[$i] === $tb[$i]) continue;
                                return ($ta[$i] < $tb[$i]) ? 1 : -1; // desc
                            }
                            return strcmp((string)$a['tag'], (string)$b['tag']);
                        });

                        $availableTags = array_values(array_map(fn($x) => $x['tag'], $cands));
                        $availableTags = array_slice($availableTags, 0, 60);
                        $latestTag = $availableTags[0] ?? null;
                        $hasUpdate = is_string($latestTag) && $latestTag !== $currentTag;
                    }
                }

                $out[] = [
                    'name' => $cName,
                    'currentImage' => $img,
                    'repo' => $ref['repo'],
                    'registry' => $ref['registry'],
                    'path' => $ref['path'],
                    'currentTag' => $currentTag,
                    'availableTags' => $availableTags,
                    'latestTag' => $latestTag,
                    'hasUpdate' => $hasUpdate,
                    'note' => $note,
                ];
            }

            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment, 'containers' => $out]);
        }

        case 'set_deployment_image_tag': {
            csrf_check_or_bypass();

            $deployment = (string)($_POST['name'] ?? '');
            $container  = (string)($_POST['container'] ?? '');
            $newTag     = (string)($_POST['tag'] ?? '');

            if ($deployment === '' || !is_dns_label($deployment)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de deployment invalide.']);
            }
            if ($container === '' || !is_dns_label($container)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de container invalide.']);
            }
            // Allow typical tag chars (avoid spaces and weird stuff)
            if ($newTag === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $newTag)) {
                send_json(400, ['ok' => false, 'error' => 'Tag invalide.']);
            }

            // Fetch current deployment to validate repo + container existence
            $d = $k8s->getDeployment($namespace, $deployment);
            $containers = $d['spec']['template']['spec']['containers'] ?? [];
            if (!is_array($containers)) $containers = [];

            $currentImage = null;
            foreach ($containers as $c) {
                if (!is_array($c)) continue;
                if (($c['name'] ?? '') === $container && is_string($c['image'] ?? null)) {
                    $currentImage = (string)$c['image'];
                    break;
                }
            }
            if ($currentImage === null) {
                send_json(404, ['ok' => false, 'error' => 'Container introuvable dans ce deployment.']);
            }

            $ref = parse_image_ref($currentImage);
            $currentTag = is_string($ref['tag']) ? $ref['tag'] : null;
            if ($currentTag === null) {
                send_json(400, ['ok' => false, 'error' => 'Image actuelle sans tag, impossible de changer juste la version.']);
            }

            // Safety: only allow switching tags within same image repo.
            if ($ref['registry'] === 'docker.io') {
                $tags = dockerhub_list_tags((string)$ref['path']);

                $split = split_tag_version($currentTag);
                $wantSuffix = (string)($split['suffix'] ?? '');

                if ($split['version'] !== null) {
                    $allowed = [];
                    foreach ($tags as $t) {
                        $s = split_tag_version((string)$t);
                        if ($s['version'] === null) continue;
                        if ((string)$s['suffix'] !== $wantSuffix) continue;
                        $allowed[$t] = true;
                    }
                    if (!isset($allowed[$newTag])) {
                        send_json(400, ['ok' => false, 'error' => 'Tag non autorisé (suffixe/version).']);
                    }
                }
            }

            $newImage = (string)$ref['repo'] . ':' . $newTag;

            // Strategic merge patch minimal: merge sur containers[].name
            $patch = [
                'spec' => [
                   'template' => [
                        'spec' => [
                            'containers' => [[
                                'name'  => $container,
                                'image' => $newImage,
                            ]],
                        ],
                    ],
                ],
            ];

            $ns = rawurlencode($namespace);
            $dp = rawurlencode($deployment);
            $k8s->patch("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}", $patch);

            send_json(200, ['ok' => true, 'namespace' => $namespace, 'deployment' => $deployment, 'container' => $container, 'tag' => $newTag, 'newImage' => $newImage]);
        }

        // -------- Network (Services / Ingress) --------
        case 'list_services': {
            $svc = $k8s->listServices($namespace);
            $items = $svc['items'] ?? [];
            $out = [];
            foreach ($items as $s) {
                if (!is_array($s)) continue;
                $n = $s['metadata']['name'] ?? null;
                if (!is_string($n) || $n === '') continue;
                $ports = $s['spec']['ports'] ?? [];
                $pOut = [];
                if (is_array($ports)) {
                    foreach ($ports as $p) {
                        if (!is_array($p)) continue;
                        $pOut[] = [
                            'name' => is_string($p['name'] ?? null) ? $p['name'] : null,
                            'port' => (int)($p['port'] ?? 0),
                            'protocol' => is_string($p['protocol'] ?? null) ? $p['protocol'] : null,
                            'targetPort' => $p['targetPort'] ?? null,
                        ];
                    }
                }
                $out[] = [
                    'name' => $n,
                    'ports' => $pOut,
                    'selector' => is_array($s['spec']['selector'] ?? null) ? $s['spec']['selector'] : [],
                ];
            }
            usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'services' => $out]);
        }

        case 'list_public_urls': {
            $deploymentFilter = (string)($_GET['deployment'] ?? '');
            if ($deploymentFilter !== '' && !is_dns_label($deploymentFilter)) {
                send_json(400, ['ok' => false, 'error' => 'Paramètre deployment invalide.']);
            }

            $allowedServices = null;

            // For a deployment filter, identify services pointing to this deployment (selector match).
            $svcData = $k8s->listServices($namespace);
            $svcItems = $svcData['items'] ?? [];

            if ($deploymentFilter !== '') {
                $dep = $k8s->getDeployment($namespace, $deploymentFilter);
                $matchLabels = $dep['spec']['selector']['matchLabels'] ?? [];
                if (!is_array($matchLabels)) $matchLabels = [];

                $allowedServices = [];
                foreach ($svcItems as $s) {
                    if (!is_array($s)) continue;
                    $sName = $s['metadata']['name'] ?? null;
                    if (!is_string($sName) || $sName === '') continue;
                    $sel = $s['spec']['selector'] ?? null;
                    if (!is_array($sel) || count($sel) === 0) continue;
                    $ok = true;
                    foreach ($matchLabels as $k => $v) {
                        if (!isset($sel[$k]) || (string)$sel[$k] !== (string)$v) { $ok = false; break; }
                    }
                    if ($ok) $allowedServices[$sName] = true;
                }
            }

            // Services list (for UI)
            $servicesOut = [];
            foreach ($svcItems as $s) {
                if (!is_array($s)) continue;
                $n = $s['metadata']['name'] ?? null;
                if (!is_string($n) || $n === '') continue;
                $ports = $s['spec']['ports'] ?? [];
                $pOut = [];
                if (is_array($ports)) {
                    foreach ($ports as $p) {
                        if (!is_array($p)) continue;
                        $pOut[] = [
                            'name' => is_string($p['name'] ?? null) ? $p['name'] : null,
                            'port' => (int)($p['port'] ?? 0),
                            'protocol' => is_string($p['protocol'] ?? null) ? $p['protocol'] : null,
                            'targetPort' => $p['targetPort'] ?? null,
                        ];
                    }
                }
                $servicesOut[] = [
                    'name' => $n,
                    'ports' => $pOut,
                ];
            }
            usort($servicesOut, fn($a, $b) => strcmp($a['name'], $b['name']));

            // Ingresses
            $ing = $k8s->listIngresses($namespace);
            $items = $ing['items'] ?? [];
            if (!is_array($items)) $items = [];

            $entries = [];
            $tlsSecretsNeeded = [];
            $certBySecret = [];

            // Try cert-manager Certificates if available.
            try {
                $ns = rawurlencode($namespace);
                $certs = $k8s->get("/apis/cert-manager.io/v1/namespaces/{$ns}/certificates?limit=500");
                $cItems = $certs['items'] ?? [];
                if (is_array($cItems)) {
                    foreach ($cItems as $c) {
                        if (!is_array($c)) continue;
                        $secretName = $c['spec']['secretName'] ?? null;
                        if (!is_string($secretName) || $secretName === '') continue;
                        $conds = $c['status']['conditions'] ?? [];
                        $ready = null;
                        if (is_array($conds)) {
                            foreach ($conds as $cond) {
                                if (!is_array($cond)) continue;
                                if (($cond['type'] ?? '') === 'Ready') {
                                    $ready = (string)($cond['status'] ?? 'Unknown');
                                }
                            }
                        }
                        $notAfter = $c['status']['notAfter'] ?? null;
                        $certBySecret[$secretName] = [
                            'source' => 'cert-manager',
                            'ready' => $ready,
                            'notAfter' => is_string($notAfter) ? $notAfter : null,
                        ];
                    }
                }
            } catch (Throwable $e) {
                // ignore if CRD not installed / RBAC denied
            }

            foreach ($items as $i) {
                if (!is_array($i)) continue;
                $meta = $i['metadata'] ?? [];
                $spec = $i['spec'] ?? [];
                $status = $i['status'] ?? [];

                $ingName = is_string($meta['name'] ?? null) ? (string)$meta['name'] : '';
                if ($ingName === '') continue;

                $annotations = is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
                $managed = ((string)($annotations[managed_annotation_key()] ?? '')) === 'dashboard';
                $entryId = is_string($annotations[entry_id_annotation_key()] ?? null)
                    ? (string)$annotations[entry_id_annotation_key()]
                    : null;

                $lb = $status['loadBalancer']['ingress'] ?? [];
                $lbArr = [];
                if (is_array($lb)) {
                    foreach ($lb as $x) {
                        if (!is_array($x)) continue;
                        $lbArr[] = [
                            'ip' => is_string($x['ip'] ?? null) ? $x['ip'] : null,
                            'hostname' => is_string($x['hostname'] ?? null) ? $x['hostname'] : null,
                        ];
                    }
                }

                // TLS map: host -> secretName
                $tlsHostToSecret = [];
                $tls = $spec['tls'] ?? [];
                if (is_array($tls)) {
                    foreach ($tls as $t) {
                        if (!is_array($t)) continue;
                        $sec = $t['secretName'] ?? null;
                        if (!is_string($sec) || $sec === '') continue;
                        $hosts = $t['hosts'] ?? [];
                        if (!is_array($hosts)) continue;
                        foreach ($hosts as $h) {
                            if (is_string($h) && $h !== '') $tlsHostToSecret[$h] = $sec;
                        }
                    }
                }

                $rules = $spec['rules'] ?? [];
                if (!is_array($rules)) $rules = [];

                foreach ($rules as $r) {
                    if (!is_array($r)) continue;
                    $host = $r['host'] ?? '';
                    if (!is_string($host) || $host === '') continue;

                    $http = $r['http'] ?? null;
                    if (!is_array($http)) continue;
                    $paths = $http['paths'] ?? [];
                    if (!is_array($paths)) $paths = [];

                    foreach ($paths as $p) {
                        if (!is_array($p)) continue;
                        $path = $p['path'] ?? '/';
                        if (!is_string($path) || $path === '') $path = '/';

                        // networking.k8s.io/v1 backend
                        $backend = $p['backend'] ?? null;
                        if (!is_array($backend)) continue;
                        $svc = $backend['service'] ?? null;
                        if (!is_array($svc)) continue;

                        $svcName = $svc['name'] ?? null;
                        if (!is_string($svcName) || $svcName === '') continue;

                        $port = null;
                        $portSpec = $svc['port'] ?? null;
                        if (is_array($portSpec)) {
                            if (isset($portSpec['number'])) $port = (int)$portSpec['number'];
                            elseif (isset($portSpec['name'])) $port = (string)$portSpec['name'];
                        }

                        if (is_array($allowedServices) && !isset($allowedServices[$svcName])) {
                            continue;
                        }

                        $tlsSecret = $tlsHostToSecret[$host] ?? null;
                        if (is_string($tlsSecret) && $tlsSecret !== '') {
                            $tlsSecretsNeeded[$tlsSecret] = true;
                        }

                        $id = $entryId ?? substr(sha1($ingName . '|' . $host . '|' . $path . '|' . $svcName), 0, 12);

                        $scheme = (is_string($tlsSecret) && $tlsSecret !== '') ? 'https' : 'http';
                        $url = $scheme . '://' . $host . $path;

                        $entries[] = [
                            'id' => $id,
                            'ingressName' => $ingName,
                            'managed' => $managed,
                            'host' => $host,
                            'path' => $path,
                            'service' => $svcName,
                            'port' => $port,
                            'tlsSecret' => $tlsSecret,
                            'scheme' => $scheme,
                            'url' => $url,
                            'loadBalancer' => $lbArr,
                            'cert' => null,
                        ];
                    }
                }
            }

            // Enrich cert status per secret
            $secretCache = [];
            foreach (array_keys($tlsSecretsNeeded) as $secName) {
                // Prefer cert-manager status
                if (isset($certBySecret[$secName])) {
                    $c = $certBySecret[$secName];
                    $ready = $c['ready'] ?? null;
                    $notAfter = $c['notAfter'] ?? null;
                    $status = 'unknown';
                    $msg = 'Cert-manager';
                    if ($ready === 'True') $status = 'valid';
                    elseif ($ready === 'False') $status = 'error';

                    $secretCache[$secName] = [
                        'status' => $status,
                        'source' => 'cert-manager',
                        'notAfter' => is_string($notAfter) ? $notAfter : null,
                        'daysRemaining' => null,
                        'message' => $msg,
                    ];
                    continue;
                }

                // Fallback: parse tls.crt from Secret
                try {
                    $secret = $k8s->getSecret($namespace, $secName);
                    $parsed = parse_tls_secret_cert($secret);
                    if ($parsed === null) {
                        $secretCache[$secName] = [
                            'status' => 'unknown',
                            'source' => 'secret',
                            'notAfter' => null,
                            'daysRemaining' => null,
                            'message' => 'Secret TLS illisible ou tls.crt absent',
                        ];
                    } else {
                        $secretCache[$secName] = [
                            'status' => $parsed['expired'] ? 'expired' : 'valid',
                            'source' => 'secret',
                            'notAfter' => $parsed['notAfter'],
                            'daysRemaining' => $parsed['daysRemaining'],
                            'message' => $parsed['expired'] ? 'Certificat expiré' : 'Certificat valide',
                        ];
                    }
                } catch (Throwable $e) {
                    $secretCache[$secName] = [
                        'status' => 'unknown',
                        'source' => 'secret',
                        'notAfter' => null,
                        'daysRemaining' => null,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            foreach ($entries as &$e) {
                $sec = $e['tlsSecret'] ?? null;
                if (is_string($sec) && $sec !== '' && isset($secretCache[$sec])) {
                    $e['cert'] = $secretCache[$sec];
                } elseif (empty($sec)) {
                    $e['cert'] = ['status' => 'none', 'message' => 'Pas de TLS'];
                }
            }
            unset($e);

            usort($entries, function($a, $b){
                $c = strcmp((string)$a['host'], (string)$b['host']);
                if ($c !== 0) return $c;
                return strcmp((string)$a['path'], (string)$b['path']);
            });

            send_json(200, [
                'ok' => true,
                'namespace' => $namespace,
                'deployment' => $deploymentFilter !== '' ? $deploymentFilter : null,
                'entries' => $entries,
                'services' => $servicesOut,
            ]);
        }

        case 'upsert_public_url': {
            csrf_check_or_bypass();

            $id = (string)($_POST['id'] ?? '');
            $ingressName = (string)($_POST['ingressName'] ?? '');
            $host = strtolower(trim((string)($_POST['host'] ?? '')));
            $path = trim((string)($_POST['path'] ?? '/'));
            $service = trim((string)($_POST['service'] ?? ''));
            $portRaw = (string)($_POST['port'] ?? '');
            $tlsEnabled = (string)($_POST['tls'] ?? '');
            $tlsSecret = trim((string)($_POST['tlsSecret'] ?? ''));

            if ($host === '' || !is_host($host)) {
                send_json(400, ['ok' => false, 'error' => 'Host invalide.']);
            }
            if ($path === '' || $path[0] !== '/') {
                send_json(400, ['ok' => false, 'error' => 'Path invalide (doit commencer par /).']);
            }
            if ($service === '' || !is_dns_label($service)) {
                send_json(400, ['ok' => false, 'error' => 'Service invalide.']);
            }

            $port = null;
            if ($portRaw !== '') {
                if (ctype_digit($portRaw)) {
                    $port = (int)$portRaw;
                    if ($port < 1 || $port > 65535) {
                        send_json(400, ['ok' => false, 'error' => 'Port invalide.']);
                    }
                } else {
                    // allow named ports
                    if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $portRaw)) {
                        send_json(400, ['ok' => false, 'error' => 'Port name invalide.']);
                    }
                    $port = $portRaw;
                }
            } else {
                $port = 80;
            }

            $tls = ($tlsEnabled === '1' || strtolower($tlsEnabled) === 'true' || $tlsEnabled === 'on');
            if ($tls) {
                if ($tlsSecret === '' || !is_dns_label($tlsSecret)) {
                    send_json(400, ['ok' => false, 'error' => 'Secret TLS invalide (nom Kubernetes).']);
                }
            } else {
                $tlsSecret = '';
            }

            $className = getenv_non_empty('K8S_INGRESS_CLASS');

            if ($ingressName !== '') {
                if (!is_dns_label($ingressName)) {
                    send_json(400, ['ok' => false, 'error' => 'Ingress name invalide.']);
                }
                // Safety: can only update managed ingresses
                $ing = $k8s->get("/apis/networking.k8s.io/v1/namespaces/" . rawurlencode($namespace) . "/ingresses/" . rawurlencode($ingressName));
                $ann = $ing['metadata']['annotations'] ?? [];
                $managed = is_array($ann) && ((string)($ann[managed_annotation_key()] ?? '')) === 'dashboard';
                if (!$managed) {
                    send_json(403, ['ok' => false, 'error' => 'Ingress non géré par le dashboard (refus de modification).']);
                }
            }

            $finalIngressName = $ingressName !== '' ? $ingressName : ingress_name_for($id, $host, $path);
            $finalEntryId = $id !== '' ? $id : substr(sha1($finalIngressName . '|' . $host . '|' . $path), 0, 12);

            $manifest = [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'Ingress',
                'metadata' => [
                    'name' => $finalIngressName,
                    'annotations' => [
                        managed_annotation_key() => 'dashboard',
                        entry_id_annotation_key() => $finalEntryId,
                    ],
                ],
                'spec' => array_filter([
                    'ingressClassName' => $className,
                    'rules' => [[
                        'host' => $host,
                        'http' => [
                            'paths' => [[
                                'path' => $path,
                                'pathType' => 'Prefix',
                                'backend' => [
                                    'service' => [
                                        'name' => $service,
                                        'port' => is_int($port)
                                            ? ['number' => $port]
                                            : ['name' => $port],
                                    ],
                                ],
                            ]],
                        ],
                    ]],
                    'tls' => $tls ? [[
                        'hosts' => [$host],
                        'secretName' => $tlsSecret,
                    ]] : null,
                ], fn($v) => $v !== null),
            ];

            if ($ingressName === '') {
                // Create
                $k8s->createIngress($namespace, $manifest);
                send_json(200, ['ok' => true, 'namespace' => $namespace, 'ingressName' => $finalIngressName, 'id' => $finalEntryId]);
            }

            // Patch
            $patch = [
                'metadata' => [
                    'annotations' => $manifest['metadata']['annotations'],
                ],
                'spec' => $manifest['spec'],
            ];
            $k8s->patchIngress($namespace, $finalIngressName, $patch, 'application/merge-patch+json');
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'ingressName' => $finalIngressName, 'id' => $finalEntryId]);
        }

        case 'delete_public_url': {
            csrf_check_or_bypass();

            $ingressName = (string)($_POST['ingressName'] ?? '');
            if ($ingressName === '' || !is_dns_label($ingressName)) {
                send_json(400, ['ok' => false, 'error' => 'Ingress name invalide.']);
            }

            // Safety: only delete managed ingresses
            $ing = $k8s->get("/apis/networking.k8s.io/v1/namespaces/" . rawurlencode($namespace) . "/ingresses/" . rawurlencode($ingressName));
            $ann = $ing['metadata']['annotations'] ?? [];
            $managed = is_array($ann) && ((string)($ann[managed_annotation_key()] ?? '')) === 'dashboard';
            if (!$managed) {
                send_json(403, ['ok' => false, 'error' => 'Ingress non géré par le dashboard (refus de suppression).']);
            }

            $k8s->deleteIngress($namespace, $ingressName);
            send_json(200, ['ok' => true, 'namespace' => $namespace, 'ingressName' => $ingressName]);
        }

        // ══════════════════════════════════════════════════════════
        // GET ConfigMap  — lit un ConfigMap du namespace utilisateur
        // GET /data/k8s_api.php?action=get_configmap&name=xxx[&namespace=xxx]
        // Le paramètre namespace est ignoré : on utilise toujours le namespace
        // de la session pour éviter toute élévation de privilège.
        // ══════════════════════════════════════════════════════════
        case 'get_configmap': {
            $cmName = trim((string)($_GET['name'] ?? ''));

            if ($cmName === '' || !is_dns_subdomain($cmName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de ConfigMap invalide.']);
            }

            $ns = rawurlencode($namespace);
            $n  = rawurlencode($cmName);

            $cm = $k8s->get("/api/v1/namespaces/{$ns}/configmaps/{$n}");

            $data = $cm['data'] ?? null;
            if (!is_array($data)) {
                $data = (object)[];   // JSON {}
            }

            send_json(200, [
                'ok'        => true,
                'namespace' => $namespace,
                'name'      => $cmName,
                'data'      => $data,
            ]);
        }

        // ══════════════════════════════════════════════════════════
        // SET ConfigMap  — crée ou met à jour une clé d'un ConfigMap
        // POST /data/k8s_api.php?action=set_configmap
        // Body: name, key, content  (namespace ignoré → session)
        // ══════════════════════════════════════════════════════════
        case 'set_configmap': {
            csrf_check_or_bypass();

            $cmName  = trim((string)($_POST['name']    ?? ''));
            $cmKey   = trim((string)($_POST['key']     ?? ''));
            $content = (string)($_POST['content'] ?? '');

            if ($cmName === '' || !is_dns_subdomain($cmName)) {
                send_json(400, ['ok' => false, 'error' => 'Nom de ConfigMap invalide.']);
            }
            if ($cmKey === '' || strlen($cmKey) > 253) {
                send_json(400, ['ok' => false, 'error' => 'Clé de ConfigMap invalide.']);
            }
            // Refuse les clés avec des caractères dangereux (path traversal etc.)
            if (preg_match('/[\x00-\x1f\x7f]/', $cmKey)) {
                send_json(400, ['ok' => false, 'error' => 'Clé de ConfigMap contient des caractères interdits.']);
            }
            // Taille max 1 MiB par clé (limite etcd)
            if (strlen($content) > 1_048_576) {
                send_json(400, ['ok' => false, 'error' => 'Contenu trop volumineux (max 1 MiB par clé).']);
            }

            $ns = rawurlencode($namespace);
            $n  = rawurlencode($cmName);

            // Essayer de récupérer le ConfigMap existant
            $existing = null;
            try {
                $existing = $k8s->get("/api/v1/namespaces/{$ns}/configmaps/{$n}");
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'HTTP 404')) {
                    throw $e;
                }
                // ConfigMap absent → on le créera
            }

            if ($existing !== null) {
                // PATCH stratégique : on n'écrase que la clé demandée,
                // les autres clés du ConfigMap sont préservées.
                $patch = [
                    'data' => [
                        $cmKey => $content,
                    ],
                ];
                $k8s->patch(
                    "/api/v1/namespaces/{$ns}/configmaps/{$n}",
                    $patch,
                    'application/merge-patch+json'
                );
            } else {
                // Création via server-side apply (PATCH avec fieldManager)
                // Crée le ConfigMap s'il n'existe pas, sans nécessiter de méthode POST dédiée.
                $manifest = [
                    'apiVersion' => 'v1',
                    'kind'       => 'ConfigMap',
                    'metadata'   => [
                        'name'        => $cmName,
                        'namespace'   => $namespace,
                        'annotations' => ['gnl-solution.fr/managed-by' => 'dashboard'],
                    ],
                    'data' => [$cmKey => $content],
                ];
                $k8s->patch(
                    "/api/v1/namespaces/{$ns}/configmaps/{$n}?fieldManager=dashboard&force=true",
                    $manifest,
                    'application/apply-patch+yaml'
                );
                $created = true;
            }

            send_json(200, [
                'ok'        => true,
                'namespace' => $namespace,
                'name'      => $cmName,
                'key'       => $cmKey,
                'created'   => ($existing === null),
            ]);
        }

        default:
            send_json(400, ['ok' => false, 'error' => 'Action inconnue.']);
    }

} catch (Throwable $e) {
    // Ne masque pas les 403/404 Kubernetes derrière un 500 “mystère”.
    $msg = $e->getMessage();
    $status = 500;
    if (preg_match('/\(HTTP\s+(\d{3})\)/', $msg, $m)) {
        $s = (int)$m[1];
        if ($s >= 400 && $s <= 599) $status = $s;
    }
    send_json($status, ['ok' => false, 'error' => $msg]);
}