<?php

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/../include/account_sessions.php';
require_once __DIR__ . '/dolbar_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function projects_menu_send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function projects_menu_extract_rows(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (['data', 'items', 'results', 'projects', 'projets'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    return [];
}

function projects_menu_tag_value($value): string
{
    if (!(is_string($value) || is_numeric($value))) {
        return '';
    }

    return trim((string)$value);
}


function projects_menu_user_namespace(array $user): string
{
    foreach (['k8s_namespace', 'k8sNamespace', 'namespace_k8s', 'k8s_ns', 'namespace'] as $key) {
        $value = trim((string)($user[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function projects_menu_build_from_kubernetes_namespace(array $user): array
{
    $namespace = projects_menu_user_namespace($user);
    if ($namespace === '') {
        throw new RuntimeException('Namespace manquant dans le profil Keycloak.');
    }

    require_once __DIR__ . '/KubernetesClient.php';

    $k8s = new KubernetesClient(null, null, null, 5);
    $payload = $k8s->listDeployments($namespace);
    $items = $payload['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $projects = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $metadata = $item['metadata'] ?? [];
        if (!is_array($metadata)) {
            continue;
        }

        $deploymentName = projects_menu_tag_value($metadata['name'] ?? null);
        if ($deploymentName === '') {
            continue;
        }

        $labels = $metadata['labels'] ?? [];
        if (!is_array($labels)) {
            $labels = [];
        }

        $displayName = projects_menu_tag_value(
            $labels['app.kubernetes.io/part-of']
                ?? $labels['app.kubernetes.io/name']
                ?? $labels['app']
                ?? $deploymentName
        );

        $projects[] = [
            'name' => $displayName !== '' ? $displayName : $deploymentName,
            'deployment_subtag' => $deploymentName,
            'namespace' => $namespace,
        ];
    }

    usort($projects, static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $projects;
}

function projects_menu_is_deployment_parent_label(string $label): bool
{
    $normalized = strtolower(trim($label));
    return in_array($normalized, ['deploiment', 'deployment'], true);
}

function projects_menu_build_subtags_by_project(array $projects, callable $dolibarrRequest): array
{
    $projectIds = [];
    foreach ($projects as $project) {
        if (!is_array($project)) {
            continue;
        }
        $id = (int)($project['id'] ?? 0);
        if ($id > 0) {
            $projectIds[$id] = true;
        }
    }
    if ($projectIds === []) {
        return [];
    }

    $rawCategories = $dolibarrRequest('/categories', ['type' => 'project', 'limit' => 1000, 'sortfield' => 't.rowid']);
    $categoryRows = is_array($rawCategories) ? projects_menu_extract_rows($rawCategories) : [];
    if ($categoryRows === [] && is_array($rawCategories)) {
        $categoryRows = $rawCategories;
    }

    $categoriesById = [];
    foreach ($categoryRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $categoryId = (int)($row['id'] ?? $row['rowid'] ?? 0);
        if ($categoryId <= 0) {
            continue;
        }

        $categoriesById[$categoryId] = [
            'label' => projects_menu_tag_value($row['label'] ?? $row['name'] ?? null),
            'parent_id' => (int)($row['fk_parent'] ?? $row['parent'] ?? 0),
        ];
    }

    $projectCategoryIds = [];
    foreach ($categoriesById as $categoryId => $meta) {
        if (($meta['label'] ?? '') === '') {
            continue;
        }

        try {
            $rawProjectsForCategory = $dolibarrRequest('/projects', [
                'category' => $categoryId,
                'limit' => 1000,
                'sortfield' => 't.rowid',
                'sortorder' => 'ASC',
            ]);
        } catch (Throwable $e) {
            continue;
        }

        $rowsForCategory = is_array($rawProjectsForCategory) ? projects_menu_extract_rows($rawProjectsForCategory) : [];
        if ($rowsForCategory === [] && is_array($rawProjectsForCategory)) {
            $rowsForCategory = $rawProjectsForCategory;
        }

        foreach ($rowsForCategory as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = (int)($row['id'] ?? $row['rowid'] ?? 0);
            if ($projectId <= 0 || !isset($projectIds[$projectId])) {
                continue;
            }

            $projectCategoryIds[$projectId][] = (int)$categoryId;
        }
    }

    $deploymentSubtagsByProject = [];
    foreach ($projectCategoryIds as $projectId => $categoryIds) {
        $subtags = [];
        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $meta = $categoriesById[$categoryId] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            $label = (string)($meta['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $parentId = (int)($meta['parent_id'] ?? 0);
            if ($parentId <= 0) {
                continue;
            }

            $parentMeta = $categoriesById[$parentId] ?? null;
            if (!is_array($parentMeta)) {
                continue;
            }

            if (projects_menu_is_deployment_parent_label((string)($parentMeta['label'] ?? '')) && !projects_menu_is_deployment_parent_label($label)) {
                $subtags[] = $label;
            }
        }

        $subtags = array_values(array_unique(array_filter($subtags, static fn($v): bool => $v !== '')));
        if ($subtags !== []) {
            $deploymentSubtagsByProject[(int)$projectId] = $subtags[0];
        }
    }

    return $deploymentSubtagsByProject;
}

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    projects_menu_send_json(401, ['ok' => false, 'error' => 'Non authentifié.']);
}

if (accountSessionsIsCurrentSessionRevoked($pdo, (int)($_SESSION['user']['id'] ?? 0))) {
    accountSessionsDestroyPhpSession();
    projects_menu_send_json(401, ['ok' => false, 'error' => 'Session révoquée.']);
}

accountSessionsTouchCurrent($pdo, (int)$_SESSION['user']['id']);

if (!dolbarApiIntegrationEnabled()) {
    try {
        projects_menu_send_json(200, [
            'ok' => true,
            'source' => 'kubernetes',
            'projects' => projects_menu_build_from_kubernetes_namespace($_SESSION['user']),
        ]);
    } catch (Throwable $e) {
        projects_menu_send_json(500, ['ok' => false, 'source' => 'kubernetes', 'error' => $e->getMessage()]);
    }
}

try {
    $apiUrl = dolbarApiConfigValue(dolbarApiCandidateUrlKeys(), $_SESSION['user']);
    $login = dolbarApiConfigValue(dolbarApiCandidateLoginKeys(), $_SESSION['user']);
    $password = dolbarApiConfigValue(dolbarApiCandidatePasswordKeys(), $_SESSION['user']);
    $apiKey = dolbarApiConfigValue(dolbarApiCandidateKeyKeys(), $_SESSION['user']);
    $sessionToken = dolbarApiResolveSessionToken($_SESSION);

    if ($apiUrl === null) {
        throw new RuntimeException('Configuration Dolbar incomplète (URL manquante).');
    }

    $apiUrl = dolbarApiNormalizeBaseUrl($apiUrl);
    $loginToken = null;

    $requestDolibarr = static function (string $path, array $params = []) use (
        $apiUrl,
        $sessionToken,
        $login,
        $password,
        $apiKey,
        &$loginToken
    ) {
        if ($sessionToken !== '') {
            return dolbarApiCallWithToken($apiUrl, $path, $sessionToken, 'GET', $params, [], 12);
        }
        if ($login !== null && $password !== null) {
            if ($loginToken === null) {
                $loginToken = dolbarApiLoginToken($apiUrl, $login, $password, 8);
            }
            return dolbarApiCallWithToken($apiUrl, $path, $loginToken, 'GET', $params, [], 12);
        }
        if ($apiKey !== null) {
            return dolbarApiCall($apiUrl, $path, $apiKey, 'GET', $params, [], 12);
        }

        throw new RuntimeException('Configuration Dolibarr incomplète (login/mot de passe ou clé API manquants).');
    };

    $rawProjects = $requestDolibarr('/projects', ['sortfield' => 't.rowid', 'sortorder' => 'DESC', 'limit' => 100]);
    $projects = array_values(array_filter(
        projects_menu_extract_rows(is_array($rawProjects) ? $rawProjects : []),
        static fn($row): bool => is_array($row)
    ));

    $deploymentSubtagsByProject = projects_menu_build_subtags_by_project($projects, $requestDolibarr);

    $result = [];
    foreach ($projects as $project) {
        $projectId = (int)($project['id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $projectName = projects_menu_tag_value($project['title'] ?? $project['label'] ?? $project['name'] ?? null);
        $deploymentSubtag = $deploymentSubtagsByProject[$projectId] ?? '';

        if ($projectName === '' || $deploymentSubtag === '') {
            continue;
        }

        $result[] = [
            'name' => $projectName,
            'deployment_subtag' => $deploymentSubtag,
        ];
    }

    projects_menu_send_json(200, ['ok' => true, 'projects' => $result]);
} catch (Throwable $e) {
    projects_menu_send_json(500, ['ok' => false, 'error' => $e->getMessage()]);
}
