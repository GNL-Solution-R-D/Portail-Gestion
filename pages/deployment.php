<?php

declare(strict_types=1);

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';
require_once '../config_loader.php';
require_once '../include/account_sessions.php';


if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

require_once '../data/KubernetesClient.php';

if (!function_exists('includeIsolated')) {
    function includeIsolated(string $file, array $vars = []): void
    {
        if (!is_file($file)) {
            return;
        }
        (static function (string $__file, array $__vars): void {
            if ($__vars !== []) {
                extract($__vars, EXTR_SKIP);
            }
            include $__file;
        })($file, $vars);
    }
}

if (!function_exists('deploymentBaseDomainFromHost')) {
    function deploymentBaseDomainFromHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }
        $host = (string) preg_replace('/:\d+$/', '', $host);
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $parts = array_values(array_filter(explode('.', $host), static fn ($p): bool => $p !== ''));
        $count = count($parts);
        if ($count <= 2) {
            return $host;
        }
        $lastTwo = $parts[$count - 2] . '.' . $parts[$count - 1];
        $twoLevelSuffixes = [
            'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'net.uk',
            'com.au', 'net.au', 'org.au',
            'co.nz', 'org.nz',
            'com.br', 'com.mx', 'co.jp',
        ];
        if (in_array($lastTwo, $twoLevelSuffixes, true) && $count >= 3) {
            return $parts[$count - 3] . '.' . $lastTwo;
        }
        return $lastTwo;
    }
}

if (!function_exists('deploymentIngressBaseDomains')) {
    function deploymentIngressBaseDomains(KubernetesClient $k8s, string $namespace): array
    {
        if ($namespace === '') {
            return [];
        }
        try {
            $ingresses = $k8s->listIngresses($namespace);
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
            $ns = rawurlencode($namespace);
            $ingresses = $k8s->get("/apis/extensions/v1beta1/namespaces/{$ns}/ingresses?limit=500");
        }
        $hosts = [];
        foreach (($ingresses['items'] ?? []) as $ingress) {
            if (!is_array($ingress)) continue;
            $spec = $ingress['spec'] ?? [];
            if (!is_array($spec)) continue;
            foreach (($spec['rules'] ?? []) as $rule) {
                $h = is_array($rule) ? (string)($rule['host'] ?? '') : '';
                if ($h !== '') $hosts[] = $h;
            }
            foreach (($spec['tls'] ?? []) as $tlsEntry) {
                $tlsHosts = is_array($tlsEntry) ? ($tlsEntry['hosts'] ?? []) : [];
                if (!is_array($tlsHosts)) continue;
                foreach ($tlsHosts as $h) {
                    $h = (string)$h;
                    if ($h !== '') $hosts[] = $h;
                }
            }
        }
        $baseDomains = [];
        foreach ($hosts as $h) {
            $bd = deploymentBaseDomainFromHost($h);
            if ($bd !== '') $baseDomains[$bd] = true;
        }
        $domains = array_keys($baseDomains);
        sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
        return $domains;
    }
}

$userNamespace = (string)(
    $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? ''
);

$deploymentParam = $_GET['deployment'] ?? $_GET['name'] ?? '';
$deploymentName  = is_string($deploymentParam) ? $deploymentParam : '';

if (isset($_GET['name']) && !isset($_GET['deployment']) && $deploymentName !== '') {
    $canonicalQuery = $_GET;
    unset($canonicalQuery['name']);
    $canonicalQuery['deployment'] = $deploymentName;
    header('Location: /deployment?' . http_build_query($canonicalQuery, '', '&', PHP_QUERY_RFC3986), true, 302);
    exit;
}

if (
    $deploymentName === ''
    || !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $deploymentName)
) {
    http_response_code(400);
    echo t('Deployment invalide.');
    exit;
}

if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

$k8sError      = null;
$deploymentData = null;
$storageMounts  = [];
$claims         = [];

try {
    $k8s            = new KubernetesClient();
    $deploymentData = $k8s->getDeployment($userNamespace, $deploymentName);
    $k8s_ingress_base_domains = deploymentIngressBaseDomains($k8s, $userNamespace);

    $volumes = $deploymentData['spec']['template']['spec']['volumes'] ?? [];
    if (!is_array($volumes)) $volumes = [];

    $pvcByVolumeName = [];
    foreach ($volumes as $volume) {
        if (!is_array($volume)) continue;
        $volumeName = $volume['name'] ?? null;
        $claimName  = $volume['persistentVolumeClaim']['claimName'] ?? null;
        if (!is_string($volumeName) || $volumeName === '' || !is_string($claimName) || $claimName === '') continue;
        $claims[$claimName] = true;
        $pvcByVolumeName[$volumeName] = [
            'volumeName' => $volumeName,
            'claimName'  => $claimName,
            'readOnly'   => (bool)($volume['persistentVolumeClaim']['readOnly'] ?? false),
        ];
    }

    $containers = $deploymentData['spec']['template']['spec']['containers'] ?? [];
    if (!is_array($containers)) $containers = [];

    foreach ($containers as $container) {
        if (!is_array($container)) continue;
        $containerName = $container['name'] ?? null;
        if (!is_string($containerName) || $containerName === '') continue;
        $volumeMounts = $container['volumeMounts'] ?? [];
        if (!is_array($volumeMounts)) $volumeMounts = [];
        foreach ($volumeMounts as $mount) {
            if (!is_array($mount)) continue;
            $volumeName = $mount['name'] ?? null;
            $mountPath  = $mount['mountPath'] ?? null;
            if (!is_string($volumeName) || $volumeName === '' || !isset($pvcByVolumeName[$volumeName])) continue;
            if (!is_string($mountPath) || $mountPath === '') continue;
            $meta = $pvcByVolumeName[$volumeName];
            $storageMounts[] = [
                'container'  => $containerName,
                'volumeName' => $meta['volumeName'],
                'claimName'  => $meta['claimName'],
                'mountPath'  => $mountPath,
                'subPath'    => is_string($mount['subPath'] ?? null) ? $mount['subPath'] : null,
                'readOnly'   => (bool)($mount['readOnly'] ?? false) || (bool)$meta['readOnly'],
            ];
        }
    }
} catch (Throwable $e) {
    $k8sError = $e->getMessage();
}

$mountsCount = count($storageMounts);

// Annotation "webstorage.access" : pilote l'affichage de l'explorateur de fichiers.
//   "no"            => explorateur masqué (quel que soit le nombre de montages)
//   "yes" / absent  => comportement habituel (affiché si des montages existent)
// L'annotation est cherchée sur le Deployment, puis sur le template de Pod.
$webstorageAccessRaw = '';
if (is_array($deploymentData)) {
    $deploymentAnnotations = $deploymentData['metadata']['annotations'] ?? null;
    if (is_array($deploymentAnnotations) && isset($deploymentAnnotations['webstorage.access'])) {
        $webstorageAccessRaw = (string) $deploymentAnnotations['webstorage.access'];
    } else {
        $templateAnnotations = $deploymentData['spec']['template']['metadata']['annotations'] ?? null;
        if (is_array($templateAnnotations) && isset($templateAnnotations['webstorage.access'])) {
            $webstorageAccessRaw = (string) $templateAnnotations['webstorage.access'];
        }
    }
}
$webstorageAccess       = strtolower(trim($webstorageAccessRaw));
$storageExplorerEnabled = ($webstorageAccess !== 'no');

$replicas  = (int)($deploymentData['spec']['replicas'] ?? 0);
$ready     = (int)($deploymentData['status']['readyReplicas'] ?? 0);
$updated   = (int)($deploymentData['status']['updatedReplicas'] ?? 0);
$available = (int)($deploymentData['status']['availableReplicas'] ?? 0);

$deploymentStatusLabel     = t('État indisponible');
$deploymentStatusIconColor = '#ef4444';

if ($k8sError === null) {
    if ($replicas > 0 && $ready >= $replicas && $available >= $replicas) {
        $deploymentStatusLabel     = t('Déploiement opérationnel');
        $deploymentStatusIconColor = '#22c55e';
    } elseif ($ready > 0 || $updated > 0 || $available > 0) {
        $deploymentStatusLabel     = t('Déploiement en cours');
        $deploymentStatusIconColor = '#3b82f6';
    } else {
        $deploymentStatusLabel     = t('Service non démarré');
        $deploymentStatusIconColor = '#f59e0b';
    }
}

$pageTitle = $deploymentName;

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media(max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto!important;}
      .dashboard-main{padding:1rem;}
    }
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
    .widget-hero-icon{width:.75rem;height:.75rem;flex:0 0 .75rem;display:block;}
    .widget-back-icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}
    .storage-grid{display:flex;flex-direction:column;gap:16px;align-items:stretch;width:100%;}
    .storage-column{min-width:0;width:100%;}
    .crumbs{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
    .crumb-sep{opacity:.55;}
    .explorer-path{display:flex;flex-wrap:wrap;align-items:center;gap:0;font-size:.875rem;color:inherit;}
    .explorer-path-prefix{margin-right:6px;color:inherit;font-weight:500;}
    .explorer-path-sep{opacity:.55;}
    .explorer-path-link{background:none;border:0;padding:0;margin:0;font:inherit;color:inherit;cursor:pointer;}
    .explorer-path-link:hover{text-decoration:underline;}
    .explorer-path-text{color:inherit;}
    .secret-env-row{display:grid;gap:.75rem 1rem;align-items:start;}
    .secret-env-meta,.secret-env-controls{min-width:0;}
    .secret-env-form{display:flex;width:100%;gap:.5rem;align-items:center;}
    .secret-env-input{min-width:0;width:100%;flex:1 1 auto;}
    .secret-env-button{flex:0 0 auto;}
    @media(min-width:1024px){
      #secretTools{--secret-meta-width:420px;}
      .secret-env-row{grid-template-columns:minmax(260px,var(--secret-meta-width)) minmax(0,1fr);}
    }
    @media(max-width:639px){
      .secret-env-form{flex-direction:column;align-items:stretch;}
      .secret-env-button{width:100%;}
    }
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){.collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none!important;}}

    /* ── htaccess editor ── */
    #htaccessEditor{
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      font-size:.75rem;
      line-height:1.6;
      min-height:200px;
      max-height:70vh;
      width:100%;
      resize:vertical;
      white-space:pre;
      overflow:auto;
      border:none;
      outline:none;
      padding:1rem;
      border-radius:.5rem;
    }
    #htaccessEditor:focus{box-shadow:0 0 0 2px rgba(99,102,241,.4);}
    #htaccessEditor:read-only{opacity:.6;cursor:default;}
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main bg-surface">
      <div class="app-shell-offset-min-height w-full p-6">

        <?php if ($k8sError !== null): ?>

          <div class="bg-background rounded-xl border p-6 text-red-600">
            <strong><?= t('Erreur Kubernetes :') ?></strong>
            <div class="mt-2 mono text-sm"><?= htmlspecialchars($k8sError, ENT_QUOTES, 'UTF-8') ?></div>
          </div>

        <?php else: ?>

          <!-- ══════════════════════════════════════════════
               HERO CARD
          ══════════════════════════════════════════════ -->
          <div class="w-full bg-surface">
            <div data-slot="card" class="bg-card text-card-foreground flex flex-col gap-6 rounded-xl group relative overflow-hidden border-0 shadow-lg transition-shadow hover:shadow-xl">
              <div class="absolute inset-0">
                <img
                  src="https://images.unsplash.com/photo-1494984858525-798dd0b282f5?ixlib=rb-4.1.0&auto=format&fit=crop&q=80&w=2070"
                  alt=""
                  class="h-full w-full object-cover"
                />
                <div class="absolute inset-0 bg-gradient-to-r from-black/80 via-black/60 to-black/40 dark:from-black/90 dark:via-black/70 dark:to-black/50"></div>
              </div>

              <div data-slot="card-content" class="relative z-10 space-y-6 p-8 md:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between" style="margin-block-end: 0px;">
                  <div class="space-y-3">
                    <h1 class="text-3xl font-bold text-white md:text-xl lg:text-2xl">
                      <span class="mono" id="deploymentDisplayName"><?= htmlspecialchars($deploymentName, ENT_QUOTES, 'UTF-8') ?></span>
                    </h1>
                    <a href="/dashboard" class="flex items-center gap-2 text-sm text-muted-foreground hover:text-white transition-colors">
                      <svg class="widget-back-icon" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M595.9 757L350.6 511.7l245.3-245.3 51.7 51.7L454 511.7l193.6 193.5z" fill="#ffffff"/>
                      </svg>
                      <span><?= t('Retour dashboard') ?></span>
                    </a>

                  </div>

                  <div class="flex md:justify-end md:pt-1">
                    <span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 overflow-hidden border-transparent bg-white/20 text-white backdrop-blur-sm hover:bg-white/30">
                      <svg class="widget-hero-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M7.493 0.015C7.442 0.021 7.268 0.039 7.107 0.055C5.234 0.242 3.347 1.208 2.071 2.634C0.66 4.211 -0.057 6.168 0.009 8.253C0.124 11.854 2.599 14.903 6.11 15.771C8.169 16.28 10.433 15.917 12.227 14.791C14.017 13.666 15.27 11.933 15.771 9.887C15.943 9.186 15.983 8.829 15.983 8C15.983 7.171 15.943 6.814 15.771 6.113C14.979 2.878 12.315 0.498 9 0.064C8.716 0.027 7.683 -0.006 7.493 0.015ZM8.853 1.563C9.967 1.707 11.01 2.136 11.944 2.834C12.273 3.08 12.92 3.727 13.166 4.056C13.727 4.807 14.142 5.69 14.33 6.535C14.544 7.5 14.544 8.5 14.33 9.465C13.916 11.326 12.605 12.978 10.867 13.828C10.239 14.135 9.591 14.336 8.88 14.444C8.456 14.509 7.544 14.509 7.12 14.444C5.172 14.148 3.528 13.085 2.493 11.451C2.279 11.114 1.999 10.526 1.859 10.119C1.618 9.422 1.514 8.781 1.514 8C1.514 6.961 1.715 6.075 2.16 5.16C2.5 4.462 2.846 3.98 3.413 3.413C3.98 2.846 4.462 2.5 5.16 2.16C6.313 1.599 7.567 1.397 8.853 1.563ZM7.706 4.29C7.482 4.363 7.355 4.491 7.293 4.705C7.257 4.827 7.253 5.106 7.259 6.816C7.267 8.786 7.267 8.787 7.325 8.896C7.398 9.033 7.538 9.157 7.671 9.204C7.803 9.25 8.197 9.25 8.329 9.204C8.462 9.157 8.602 9.033 8.675 8.896C8.733 8.787 8.733 8.786 8.741 6.816C8.749 4.664 8.749 4.662 8.596 4.481C8.472 4.333 8.339 4.284 8.04 4.276C7.893 4.272 7.743 4.278 7.706 4.29ZM7.786 10.53C7.597 10.592 7.41 10.753 7.319 10.932C7.249 11.072 7.237 11.325 7.294 11.495C7.388 11.78 7.697 12 8 12C8.303 12 8.612 11.78 8.706 11.495C8.763 11.325 8.751 11.072 8.681 10.932C8.616 10.804 8.46 10.646 8.333 10.58C8.217 10.52 7.904 10.491 7.786 10.53Z"
                          fill="<?= htmlspecialchars($deploymentStatusIconColor, ENT_QUOTES, 'UTF-8') ?>"/>
                      </svg>
                      <?= htmlspecialchars($deploymentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                </div>


                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <p class="max-w-2xl text-base text-muted-foreground md:text-sm"></p>
                  <div>
                    <button data-slot="button" id="restartBtn" class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">
                      <?= t('Redémarrer l\'application') ?>
                    </button>
                    <div id="restartMsg" class="text-xs text-white/80 mt-1"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- ══════════════════════════════════════════════
               MODAL RESTART
          ══════════════════════════════════════════════ -->
          <div id="restartPopup" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
               role="dialog" aria-modal="true" aria-labelledby="restartPopupTitle" aria-describedby="restartPopupText">
            <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
              <div class="p-6">
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <h2 id="restartPopupTitle" class="text-lg font-semibold"><?= t('Redémarrage') ?></h2>
                    <p id="restartPopupText" class="mt-2 text-sm text-muted-foreground"><?= t('Le service redémarre.') ?></p>
                  </div>
                  <button type="button" id="restartPopupClose"
                    class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"
                    aria-label="<?= t('Fermer') ?>"><?= t('Fermer') ?></button>
                </div>
              </div>
            </div>
          </div>

          <!-- ══════════════════════════════════════════════
               MODAL DELETE VAR
          ══════════════════════════════════════════════ -->
          <div id="deleteVarModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/50 backdrop-blur-sm p-4"
               role="dialog" aria-modal="true" aria-labelledby="deleteVarModalTitle" aria-describedby="deleteVarModalText">
            <div class="w-full max-w-md rounded-xl border bg-card text-card-foreground shadow-lg">
              <div class="p-6">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0 flex-1">
                    <h2 id="deleteVarModalTitle" class="text-lg font-semibold"><?= t('Suppression de la variable') ?></h2>
                    <p id="deleteVarModalText" class="mt-2 text-sm text-muted-foreground"><?= t('Saisissez le nom de la variable pour confirmer sa suppression irréversible.') ?></p>
                  </div>
                  <button type="button" id="deleteVarModalClose"
                    class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"
                    aria-label="<?= t('Fermer') ?>"><?= t('Fermer') ?></button>
                </div>
                <form id="deleteVarModalForm" class="mt-6 space-y-4">
                  <div>
                    <label for="deleteVarModalInput" class="mb-2 block text-sm font-semibold"><?= t('Nom de la variable') ?></label>
                    <input id="deleteVarModalInput" type="text"
                      class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                      placeholder="VAR_EX_TEST" autocomplete="off" />
                  </div>
                  <div id="deleteVarModalStatus" class="text-xs text-muted-foreground"></div>
                  <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" id="deleteVarModalCancel"
                      class="inline-flex h-9 items-center justify-center rounded-md border px-3 text-sm font-medium transition-all hover:bg-secondary"><?= t('Annuler') ?></button>
                    <button type="submit" id="deleteVarModalConfirm"
                      class="inline-flex h-9 items-center justify-center rounded-md bg-red-600 px-3 text-sm font-medium text-white transition-all hover:bg-red-700 disabled:opacity-50"><?= t('Supprimer') ?></button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <!-- ══════════════════════════════════════════════
               URLs PUBLIQUES
          ══════════════════════════════════════════════ -->
          <div id="urlsCard" class="mt-4">
            <div id="publicUrls" class="flex flex-wrap gap-3 text-sm grid md:grid-cols-2 xl:grid-cols-3">
              <div class="text-muted-foreground"><?= t('Chargement…') ?></div>
            </div>
          </div>

          <!-- Logs -->
          <div class="mt-3 flex justify-end">
            <a class="inline-flex h-9 items-center justify-center rounded-md px-3 text-sm hover:bg-secondary transition-colors"
               href="/log?deployment=<?= urlencode($deploymentName) ?>">
              Accéder aux Logs →
            </a>
          </div>

          <!-- ══════════════════════════════════════════════
               HTACCESS / APACHE CONF EDITOR
          ══════════════════════════════════════════════ -->
          <div class="bg-background rounded-xl border px-4 py-3 mt-6" id="htaccessCard">
            <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
              <div class="flex items-center gap-2 min-w-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" class="shrink-0">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                  <line x1="16" y1="13" x2="8" y2="13"/>
                  <line x1="16" y1="17" x2="8" y2="17"/>
                  <polyline points="10 9 9 9 8 9"/>
                </svg>
                <span class="text-sm font-medium shrink-0"><?= t('Configuration Apache') ?></span>
                <span id="htaccessConfigName" class="mono text-xs text-muted-foreground truncate"></span>
              </div>
              <div class="flex items-center gap-2 flex-wrap">
                <span id="htaccessStatus" class="text-xs text-muted-foreground"></span>
                <button type="button" id="htaccessReloadBtn"
                  class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs hover:bg-secondary transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                       fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/>
                    <path d="M21 22v-6h-6"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/>
                  </svg>
                  <?= t('Recharger') ?>
                </button>
                <button type="button" id="htaccessSaveBtn"
                  class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs hover:bg-secondary transition-colors disabled:opacity-50 disabled:pointer-events-none"
                  disabled>
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                       fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                  </svg>
                  <?= t('Enregistrer') ?>
                </button>
              </div>
            </div>

            <div id="htaccessValidation" class="hidden mb-2 rounded-md border px-3 py-2 text-xs"></div>

            <textarea
              id="htaccessEditor"
              class="bg-muted"
              spellcheck="false"
              placeholder="<?= t('Chargement du ConfigMap…') ?>"
              readonly
              aria-label="<?= t('Éditeur de configuration Apache') ?>"></textarea>

            <div class="mt-2 flex items-center justify-between gap-2 flex-wrap">
              <div id="htaccessMeta" class="text-xs text-muted-foreground mono"></div>
              <div id="htaccessSaveMsg" class="text-xs text-muted-foreground"></div>
            </div>
          </div>



          <!-- ══════════════════════════════════════════════
               VARIABLES SECRÈTES
          ══════════════════════════════════════════════ -->
          <div class="mt-6" id="secretCard">
            <div id="secretTools" class="space-y-3">
              <div class="text-muted-foreground text-sm"><?= t('Chargement…') ?></div>
            </div>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 mt-4">
              <button type="button" id="secretCreateToggle"
                class="h-9 rounded-md border px-3 text-sm hover:bg-secondary transition-colors"><?= t('Nouvelle variable') ?></button>
            </div>
            <div id="secretCreatePanel" class="bg-background mb-4 hidden rounded-lg border p-4">
              <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground"><?= t('Nom de la variable') ?></span>
                  <input id="secretCreateEnv" type="text"
                    class="h-10 w-full rounded-md border bg-background px-3 text-sm" placeholder="<?= t('ex : API_TOKEN') ?>" />
                </label>
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground"><?= t('Valeur initiale masquée (optionnel)') ?></span>
                  <input id="secretCreateValue" type="password"
                    class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                    placeholder="<?= t('Laisser vide pour créer une valeur vide') ?>" autocomplete="new-password" />
                </label>
                <label class="text-sm">
                  <span class="mb-1 block text-xs text-muted-foreground"><?= t('Secret') ?></span>
                  <select id="secretCreateSecret" class="h-10 w-full rounded-md border bg-background px-3 text-sm"></select>
                </label>
              </div>
              <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div id="secretCreateStatus" class="text-xs text-muted-foreground"></div>
                <button type="button" id="secretCreateSubmit"
                  class="h-10 rounded-md bg-background border px-3 text-sm hover:bg-secondary transition-colors"><?= t('Créer la variable') ?></button>
              </div>
            </div>
          </div>


          <!-- ══════════════════════════════════════════════
               IMAGES / VERSION UPDATER
          ══════════════════════════════════════════════ -->
          <div class="mt-6" id="imageCard">
            <div id="imageTools" class="grid gap-3 md:grid-cols-2 xl:grid-cols-2">
              <div class="text-muted-foreground text-sm"><?= t('Chargement…') ?></div>
            </div>
          </div>

        <?php endif; ?>

          <!-- ══════════════════════════════════════════════
               EXPLORATEUR DE FICHIERS
          ══════════════════════════════════════════════ -->
          <?php if (!$storageExplorerEnabled): ?>
<!--             <div class="bg-background rounded-xl border p-6 mt-6" id="storageExplorerCard">
              <h2 class="text-lg font-semibold mb-3"><?= t('Explorateur de fichiers') ?></h2>
              <p class="text-sm text-muted-foreground">
                L'accès à l'explorateur de fichiers est désactivé pour ce Deployment
                (annotation <span class="mono">webstorage.access: "no"</span>).
              </p>
            </div> -->
          <?php elseif ($mountsCount === 0): ?>
            <div class="bg-background rounded-xl border p-6 mt-6" id="storageExplorerCard">
              <h2 class="text-lg font-semibold mb-3"><?= t('Explorateur de fichiers') ?></h2>
              <p class="text-sm text-muted-foreground">
                <?= t('Ce Deployment n\'expose aucun volume de type') ?> <span class="mono">persistentVolumeClaim</span> <?= t('dans son template de Pod.') ?>
              </p>
            </div>
          <?php else: ?>
          <div class="storage-grid mt-6" id="storageExplorerCard">
            <section class="storage-column">
              <div>
                <div id="explorerMeta" class="hidden" style="display:none"></div>
                <div id="explorerStatus" class="mt-4 text-sm text-muted-foreground"><?= t('Sélectionne un volume pour commencer.') ?></div>

                <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-sm">
                  <div class="space-y-6 px-4">
                    <div class="flex flex-col flex-wrap gap-6 sm:flex-row sm:items-center sm:justify-between">
                      <div class="flex items-start gap-3">
                        <div class="bg-muted rounded-lg p-2.5">
                          <svg width="25" height="25" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M3 8.2C3 7.08 3 6.52 3.218 6.092C3.41 5.716 3.716 5.41 4.092 5.218C4.52 5 5.08 5 6.2 5H9.675C10.164 5 10.408 5 10.638 5.055C10.843 5.104 11.038 5.185 11.217 5.295C11.418 5.418 11.591 5.591 11.937 5.937L12.063 6.063C12.409 6.409 12.582 6.582 12.783 6.705C12.962 6.815 13.157 6.896 13.362 6.945C13.592 7 13.836 7 14.325 7H17.8C18.92 7 19.48 7 19.908 7.218C20.284 7.41 20.59 7.716 20.782 8.092C21 8.52 21 9.08 21 10.2V15.8C21 16.92 21 17.48 20.782 17.908C20.59 18.284 20.284 18.59 19.908 18.782C19.48 19 18.92 19 17.8 19H6.2C5.08 19 4.52 19 4.092 18.782C3.716 18.59 3.41 18.284 3.218 17.908C3 17.48 3 16.92 3 15.8V8.2Z"
                              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                          </svg>
                        </div>
                        <div class="space-y-1">
                          <h3 class="text-xl font-semibold"><?= t('Explorateur de fichiers') ?></h3>
                          <div id="explorerCardSubtitle" class="text-sm text-muted-foreground mono break-all"></div>
                          <div id="breadcrumbs" class="crumbs text-sm" style="display:none"></div>
                        </div>
                      </div>
                      <div class="flex w-full items-center gap-3 sm:w-max">
                        <button id="reloadDirBtn" data-slot="button"
                          class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2 w-full gap-2 transition-all sm:w-auto">
                          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 2v6h6"/><path d="M21 12A9 9 0 0 0 6 5.3L3 8"/>
                            <path d="M21 22v-6h-6"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/>
                          </svg>
                          <?= t('Recharger') ?>
                        </button>
                      </div>
                    </div>
                    <div role="none" class="bg-border shrink-0 h-px w-full"></div>
                    <div class="flex flex-col flex-wrap items-center justify-between gap-6 sm:flex-row">
                      <div data-orientation="horizontal" data-slot="tabs" class="flex flex-col gap-2 w-full sm:w-max">
                        <div id="mountTabs" role="tablist" aria-orientation="horizontal" data-slot="tabs-list"
                          class="text-muted-foreground inline-flex h-9 items-center justify-center rounded-lg p-[3px] bg-muted/50 w-full overflow-x-auto"></div>
                      </div>
                      <div class="flex w-full flex-col items-center gap-2 sm:w-max sm:flex-row">
                        <select id="explorerSort"
                          class="border-input dark:bg-input/30 flex items-center justify-between gap-2 rounded-md border bg-transparent px-3 py-2 text-sm whitespace-nowrap shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 h-9 hover:bg-muted w-full transition-all sm:w-max">
                          <option value="name-asc"><?= t('Nom A → Z') ?></option>
                          <option value="name-desc"><?= t('Nom Z → A') ?></option>
                          <option value="mtime-desc"><?= t('Modifiés récemment') ?></option>
                          <option value="size-desc"><?= t('Taille décroissante') ?></option>
                          <option value="type-asc"><?= t('Type') ?></option>
                        </select>
                        <div class="relative w-full">
                          <input id="explorerSearchInput" type="text"
                            class="h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none pl-9 transition-all focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                            placeholder="<?= t('Rechercher un fichier ou dossier…') ?>"/>
                          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                               class="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                          </svg>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div data-slot="card-content" class="overflow-scroll rounded-none p-0">
                    <table class="w-full min-w-max table-auto text-left">
                      <thead>
                        <tr>
                          <th class="border-surface border-b p-4">
                            <div class="flex items-center gap-2">
                              <button id="selectAllRows" type="button" role="checkbox" aria-checked="false"
                                data-state="unchecked" value="on" data-slot="checkbox"
                                class="peer border-input dark:bg-input/30 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary size-4 shrink-0 rounded-[4px] border shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"></button>
                              <label for="selectAllRows" class="text-default block text-sm font-medium"><?= t('Nom') ?></label>
                            </div>
                          </th>
                          <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Modifié') ?></p></th>
                          <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Statut') ?></p></th>
                          <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Taille') ?></p></th>
                          <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"></p></th>
                        </tr>
                      </thead>
                      <tbody id="fileListBody">
                        <tr>
                          <td colspan="5" class="border-surface border-b p-4 text-muted-foreground"><?= t('Aucun dossier chargé.') ?></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </section>
          </div>
          <?php endif; ?>

      </div>
    </main>
  </div>


    <!-- ══════════════════════════════════════════════════════════════
       RENAME DISPLAY NAME SCRIPT
  ══════════════════════════════════════════════════════════════ -->
<script>
(async () => {
  try {
    const res = await fetch('../data/deployments_api.php?action=list', {
      credentials: 'same-origin'
    });

    const data = await res.json();

    const deployments = Array.isArray(data.deployments)
      ? data.deployments
      : [];

    const row = deployments.find(
      d => String(d.deployment_name || '').trim() === DEPLOYMENT_NAME
    );

    const displayName = String(row?.display_name || '').trim();

    if (displayName) {
      const el = document.getElementById('deploymentDisplayName');
      if (el) el.textContent = displayName;
      document.title = 'Deployment ' + displayName;
    }
  } catch (e) {
    console.warn('Impossible de charger le display_name :', e);
  }
})();
</script>

  <!-- ══════════════════════════════════════════════════════════════
       VARIABLES JS GLOBALES
  ══════════════════════════════════════════════════════════════ -->
  <script>
    const DEPLOYMENT_NAME = <?= json_encode($deploymentName,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const USER_NAMESPACE  = <?= json_encode($userNamespace,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CSRF_TOKEN      = <?= json_encode($csrfToken,         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const DETECTED_MOUNTS = <?= json_encode(array_values($storageMounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       RESTART
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    const btn        = document.getElementById('restartBtn');
    const msg        = document.getElementById('restartMsg');
    const popup      = document.getElementById('restartPopup');
    const popupTitle = document.getElementById('restartPopupTitle');
    const popupText  = document.getElementById('restartPopupText');
    const popupClose = document.getElementById('restartPopupClose');
    if (!btn) return;

    const openPopup  = (title, text) => { if (!popup) return; if (popupTitle) popupTitle.textContent = title; if (popupText) popupText.textContent = text; popup.classList.remove('hidden'); popup.classList.add('flex'); };
    const closePopup = () => { if (!popup) return; popup.classList.remove('flex'); popup.classList.add('hidden'); };

    popupClose?.addEventListener('click', closePopup);
    popup?.addEventListener('click', (e) => { if (e.target === popup) closePopup(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePopup(); });

    btn.addEventListener('click', async () => {
      btn.disabled = true;
      msg.textContent = '';
      try {
        const u = new URL('../data/k8s_api.php', window.location.href);
        u.searchParams.set('action', 'restart_deployment');
        const res = await fetch(u.toString(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
          body: new URLSearchParams({ name: DEPLOYMENT_NAME }),
        });
        const ct  = (res.headers.get('content-type') || '').toLowerCase();
        const raw = await res.text();
        let data  = null;
        try { data = JSON.parse(raw); } catch (_) {}
        if (!ct.includes('application/json') || !data) throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
        openPopup(<?= json_encode(t('Redémarrage'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, <?= json_encode(t('Le service redémarre.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
      } catch (e) {
        closePopup();
        msg.textContent = 'Erreur : ' + (e?.message || String(e));
      } finally {
        btn.disabled = false;
      }
    });
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       URLs PUBLIQUES
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    const host = document.getElementById('publicUrls');
    if (!host) return;

    const escHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

    const badge = (text, kind = 'muted') => {
      const cls = kind === 'ok'   ? 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400'
                : kind === 'warn' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'
                : kind === 'err'  ? 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-400'
                : 'bg-muted text-muted-foreground';
      return `<span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium border-transparent ${cls}">${escHtml(text)}</span>`;
    };

    const openPopup = (targetUrl) => {
      const w = 1024, h = 768;
      const dualLeft = window.screenLeft ?? window.screenX ?? 0;
      const dualTop  = window.screenTop  ?? window.screenY ?? 0;
      const vw = window.innerWidth  || document.documentElement.clientWidth  || screen.width;
      const vh = window.innerHeight || document.documentElement.clientHeight || screen.height;
      const left = Math.max(0, dualLeft + (vw - w) / 2);
      const top  = Math.max(0, dualTop  + (vh - h) / 2);
      const features = `popup=yes,scrollbars=yes,resizable=yes,width=${w},height=${h},top=${top},left=${left}`;
      const win = window.open(targetUrl, '_blank', features);
      if (win) win.focus();
    };

    (async () => {
      try {
        const u = new URL('../data/k8s_api.php', window.location.href);
        u.searchParams.set('action', 'list_public_urls');
        u.searchParams.set('deployment', DEPLOYMENT_NAME);
        const res = await fetch(u.toString(), { credentials: 'same-origin' });
        const ct  = (res.headers.get('content-type') || '').toLowerCase();
        const raw = await res.text();
        let data  = null;
        try { data = JSON.parse(raw); } catch (_) {}
        if (!ct.includes('application/json') || !data) throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));

        const entries = Array.isArray(data.entries) ? data.entries : [];
        host.innerHTML = '';
        if (entries.length === 0) {
          host.innerHTML = '<div class="text-muted-foreground">Aucune URL publique trouvée pour ce déploiement.</div>';
          return;
        }

        for (const e of entries) {
          const url  = e.url || ((e.scheme || 'http') + '://' + e.host + (e.path || '/'));
          let cert   = '';
          if (e.cert?.status) {
            if (e.cert.status === 'valid')   cert = badge(<?= json_encode(t('TLS OK'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> + (e.cert.daysRemaining != null ? ` (${e.cert.daysRemaining}j)` : ''), 'ok');
            else if (e.cert.status === 'expired') cert = badge(<?= json_encode(t('TLS expiré'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'err');
            else if (e.cert.status === 'none')    cert = badge(<?= json_encode(t('Sans TLS'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'muted');
            else                                   cert = badge('TLS ?', 'warn');
          }
          const row = document.createElement('div');
          row.className = 'bg-background flex min-w-[320px] flex-1 flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2';
          row.innerHTML = `
            <div class="min-w-0">
              <a data-public-url class="font-medium hover:underline break-all" href="${escHtml(url)}" rel="noopener noreferrer">${escHtml(url)}<svg class="ml-1 inline-block align-middle shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M20 4L12 12M20 4V8.5M20 4H15.5M19 12.5V16.8C19 17.9201 19 18.4802 18.782 18.908C18.5903 19.2843 18.2843 19.5903 17.908 19.782C17.4802 20 16.9201 20 15.8 20H7.2C6.0799 20 5.51984 20 5.09202 19.782C4.71569 19.5903 4.40973 19.2843 4.21799 18.908C4 18.4802 4 17.9201 4 16.8V8.2C4 7.0799 4 6.51984 4.21799 6.09202C4.40973 5.71569 4.71569 5.40973 5.09202 5.21799C5.51984 5 6.07989 5 7.2 5H11.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
              <div class="text-xs text-muted-foreground mt-1">
                Ingress : <span class="mono">${escHtml(e.ingressName || '')}</span>
                • Service : <span class="mono">${escHtml(e.service || '')}</span>
              </div>
            </div>
            <div class="flex items-center gap-2">${cert}</div>`;
          const link = row.querySelector('a[data-public-url]');
          if (link) {
            link.addEventListener('click', (ev) => {
              ev.preventDefault();
              openPopup(link.href);
            });
          }
          host.appendChild(row);
        }
      } catch (e) {
        host.innerHTML = `<div class="text-red-600"><strong>Erreur :</strong> ${escHtml(e?.message || String(e))}</div>`;
      }
    })();
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       HTACCESS / APACHE CONF EDITOR
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const editor     = document.getElementById('htaccessEditor');
    const statusEl   = document.getElementById('htaccessStatus');
    const configName = document.getElementById('htaccessConfigName');
    const reloadBtn  = document.getElementById('htaccessReloadBtn');
    const saveBtn    = document.getElementById('htaccessSaveBtn');
    const metaEl     = document.getElementById('htaccessMeta');
    const saveMsg    = document.getElementById('htaccessSaveMsg');
    const validEl    = document.getElementById('htaccessValidation');
    if (!editor) return;

    const CONFIG_NAME = DEPLOYMENT_NAME + '-apache-conf';
    let   CONFIG_KEY  = null;

    let originalContent = null;
    let isDirty = false;

    /* ── helpers ── */
    const escHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    const setStatus = (text, kind = 'muted') => {
      statusEl.className = 'text-xs ' + ({ok:'text-emerald-600', warn:'text-amber-600', err:'text-red-600', info:'text-blue-600'}[kind] || 'text-muted-foreground');
      statusEl.textContent = text;
    };

    const setSaveMsg = (text, kind = 'muted') => {
      saveMsg.className = 'text-xs ' + ({ok:'text-emerald-600', warn:'text-amber-600', err:'text-red-600'}[kind] || 'text-muted-foreground');
      saveMsg.textContent = text;
    };

    const showValidation = (lines, kind = 'warn') => {
      if (!lines || lines.length === 0) {
        validEl.className = 'hidden mb-2 rounded-md border px-3 py-2 text-xs';
        validEl.innerHTML = '';
        return;
      }
      const colors = {
        ok:   'border-emerald-300 bg-emerald-50 text-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300',
        warn: 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300',
        err:  'border-red-300 bg-red-50 text-red-800 dark:bg-red-900/20 dark:text-red-300',
      };
      validEl.className = 'mb-2 rounded-md border px-3 py-2 text-xs ' + (colors[kind] || colors.warn);
      validEl.innerHTML = lines.map(l => `<div>${escHtml(l)}</div>`).join('');
    };

    const validateApacheConf = (content) => {
      const warnings = [];
      const lines = content.split('\n');
      const openTags = [];
      lines.forEach((line, i) => {
        const t = line.trim();
        if (!t || t.startsWith('#')) return;
        if (/^Options\s+.*Indexes/i.test(t))
          warnings.push(`Ligne ${i+1} : "Options Indexes" active le listing de répertoires.`);
        if (/^php_value\s+allow_url_include\s+On/i.test(t))
          warnings.push(`Ligne ${i+1} : allow_url_include activé — risque de sécurité.`);
        const openMatch  = t.match(/^<([A-Za-z][A-Za-z0-9]*)(\s|>)/);
        const closeMatch = t.match(/^<\/([A-Za-z][A-Za-z0-9]*)\s*>/);
        if (closeMatch) {
          const tag = closeMatch[1].toLowerCase();
          const idx = openTags.lastIndexOf(tag);
          if (idx === -1) warnings.push(`Ligne ${i+1} : fermeture </${closeMatch[1]}> sans ouverture correspondante.`);
          else openTags.splice(idx, 1);
        } else if (openMatch) {
          openTags.push(openMatch[1].toLowerCase());
        }
      });
      openTags.forEach(tag => warnings.push(`Bloc <${tag}> ouvert sans fermeture détectée.`));
      return warnings;
    };

    const markDirty = () => {
      isDirty = true;
      saveBtn.disabled = false;
      setSaveMsg(<?= json_encode(t('Modifications non enregistrées.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'warn');
      const w = validateApacheConf(editor.value);
      showValidation(w.length ? w : null, 'warn');
    };

    const markClean = (content) => {
      originalContent = content;
      isDirty = false;
      saveBtn.disabled = true;
      setSaveMsg('');
      showValidation(null);
    };

    const updateMeta = (content) => {
      const lines = (content || '').split('\n').length;
      const bytes = new TextEncoder().encode(content || '').length;
      metaEl.textContent = `${lines} lignes · ${bytes} octets`;
    };

    const updateLabel = () => {
      configName.textContent = CONFIG_KEY
        ? `(${CONFIG_NAME} › ${CONFIG_KEY})`
        : `(${CONFIG_NAME})`;
    };

    /* ── load ── */
    const loadConfigMap = async () => {
      setStatus(<?= json_encode(t('Chargement…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'muted');
      editor.value    = '';
      editor.readOnly = true;
      saveBtn.disabled = true;
      showValidation(null);

      try {
        const url = new URL('../data/k8s_api.php', window.location.href);
        url.searchParams.set('action',    'get_configmap');
        url.searchParams.set('name',      CONFIG_NAME);
        url.searchParams.set('namespace', USER_NAMESPACE);

        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        const ct  = (res.headers.get('content-type') || '').toLowerCase();
        const raw = await res.text();
        let data  = null;
        try { data = JSON.parse(raw); } catch (_) {}

        if (!ct.includes('application/json') || !data)
          throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
        if (!res.ok || !data.ok)
          throw new Error(data.error || ('HTTP ' + res.status));

        const dataMap = data.data && typeof data.data === 'object' ? data.data : {};
        const PREFERRED = ['custom.conf', '.htaccess', 'apache.conf', 'httpd.conf'];
        CONFIG_KEY = PREFERRED.find(k => k in dataMap) || Object.keys(dataMap)[0] || 'custom.conf';
        updateLabel();

        const content = dataMap[CONFIG_KEY] != null ? String(dataMap[CONFIG_KEY]) : '';
        editor.value    = content;
        editor.readOnly = false;
        markClean(content);
        updateMeta(content);
        setStatus('Chargé', 'ok');
      } catch (e) {
        const msg = String(e?.message || e);
        if (/not found|404/i.test(msg)) {
          CONFIG_KEY = CONFIG_KEY || 'custom.conf';
          updateLabel();
          editor.value    = '';
          editor.readOnly = false;
          markClean('');
          updateMeta('');
          setStatus(<?= json_encode(t('ConfigMap absent — sera créé'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'warn');
          showValidation([
            `ConfigMap "${CONFIG_NAME}" absent du namespace.`,
            `Il sera créé avec la clé "${CONFIG_KEY}" lors du premier enregistrement.`
          ], 'warn');
        } else {
          editor.value = '';
          setStatus('Erreur : ' + msg, 'err');
          showValidation(['Erreur de chargement : ' + msg], 'err');
        }
      }
    };

    /* ── save ── */
    const saveConfigMap = async () => {
      const content  = editor.value;
      const warnings = validateApacheConf(content);
      if (warnings.length) showValidation(warnings, 'warn');

      saveBtn.disabled = true;
      editor.readOnly  = true;
      setStatus('Enregistrement…', 'muted');
      setSaveMsg(<?= json_encode(t('Enregistrement en cours…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'muted');

      try {
        const url = new URL('../data/k8s_api.php', window.location.href);
        url.searchParams.set('action', 'set_configmap');

        const res = await fetch(url.toString(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRF_TOKEN,
          },
          body: new URLSearchParams({
            name:      CONFIG_NAME,
            namespace: USER_NAMESPACE,
            key:       CONFIG_KEY || 'custom.conf',
            content:   content,
          }),
        });

        const ct  = (res.headers.get('content-type') || '').toLowerCase();
        const raw = await res.text();
        let data  = null;
        try { data = JSON.parse(raw); } catch (_) {}

        if (!ct.includes('application/json') || !data)
          throw new Error(`Réponse non-JSON (${res.status}). ` + raw.slice(0,200).replace(/\s+/g,' '));
        if (!res.ok || !data.ok)
          throw new Error(data.error || ('HTTP ' + res.status));

        markClean(content);
        updateMeta(content);
        setStatus('Enregistré', 'ok');
        setSaveMsg(<?= json_encode(t('Enregistrement réussi.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, 'ok');
        if (!warnings.length) showValidation(null);
      } catch (e) {
        saveBtn.disabled = false;
        const msg = String(e?.message || e);
        setStatus('Erreur : ' + msg, 'err');
        setSaveMsg("Échec de l'enregistrement.", 'err');
        showValidation(['Erreur lors de la sauvegarde : ' + msg], 'err');
      } finally {
        editor.readOnly = false;
        if (isDirty) saveBtn.disabled = false;
      }
    };

    /* ── events ── */
    editor.addEventListener('input', markDirty);
    editor.addEventListener('keydown', (e) => {
      if (e.key === 'Tab') {
        e.preventDefault();
        const s = editor.selectionStart, end = editor.selectionEnd;
        editor.value = editor.value.slice(0, s) + '    ' + editor.value.slice(end);
        editor.selectionStart = editor.selectionEnd = s + 4;
        markDirty();
      }
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (!saveBtn.disabled) saveConfigMap();
      }
    });
    reloadBtn.addEventListener('click', async () => {
      if (isDirty && !confirm(<?= json_encode(t('Des modifications non enregistrées seront perdues. Continuer ?'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>)) return;
      reloadBtn.disabled = true;
      try { await loadConfigMap(); } finally { reloadBtn.disabled = false; }
    });
    saveBtn.addEventListener('click', saveConfigMap);

    loadConfigMap();
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       EXPLORATEUR DE FICHIERS
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const explorerMeta         = document.getElementById('explorerMeta');
    const breadcrumbsEl        = document.getElementById('breadcrumbs');
    const explorerStatus       = document.getElementById('explorerStatus');
    const explorerCardSubtitle = document.getElementById('explorerCardSubtitle');
    const fileListBody         = document.getElementById('fileListBody');
    const selectAllRowsBtn     = document.getElementById('selectAllRows');
    const reloadDirBtn         = document.getElementById('reloadDirBtn');
    const mountTabs            = document.getElementById('mountTabs');
    const explorerSearchInput  = document.getElementById('explorerSearchInput');
    const explorerSort         = document.getElementById('explorerSort');

    if (!explorerMeta || !breadcrumbsEl || !explorerStatus || !fileListBody) return;

    const TABLE_COLSPAN = 5;
    let mounts         = Array.isArray(DETECTED_MOUNTS) ? [...DETECTED_MOUNTS] : [];
    let currentMount   = mounts[0] || null;
    let currentPath    = currentMount ? normalizePath(currentMount.mountPath || '/') : '/';
    let directoryItems = [];
    let currentItems   = [];
    let selectedRows   = new Set();
    let currentSort    = explorerSort?.value || 'name-asc';
    let currentSearch  = '';

    const escHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

    function normalizePath(value, fallback = '/') {
      let p = String(value || '').trim() || String(fallback);
      if (!p.startsWith('/')) p = '/' + p;
      p = p.replace(/\/+/g, '/').replace(/\/$/, '');
      return p === '' ? '/' : p;
    }
    function joinPath(base, name) { return normalizePath(normalizePath(base) + '/' + String(name || '').replace(/^\/+/, '')); }
    function parentPath(path, root) {
      const cur = normalizePath(path, root), base = normalizePath(root, '/');
      if (cur === base) return base;
      const parts = cur.split('/').filter(Boolean), rootParts = base.split('/').filter(Boolean);
      if (parts.length <= rootParts.length) return base;
      parts.pop();
      const up = '/' + parts.join('/');
      return up.startsWith(base) ? up : base;
    }
    const getMountKey   = (m) => m ? [m.claimName||'',m.container||'',m.mountPath||'',m.subPath||''].join('::') : '';
    const getItemType   = (item) => { const t = String(item?.type||'file').toLowerCase(); return (t==='dir'||t==='directory')?'dir':'file'; };
    const getRowKey     = (item) => `${currentMount?.claimName||'mount'}::${String(item?.path||joinPath(currentPath,item?.name||''))}`;
    const formatBytes   = (v) => { const n=Number(v); if(!Number.isFinite(n)||n<0) return '—'; if(n<1024) return `${n} B`; const units=['KB','MB','GB','TB']; let s=n,u='B'; for(const x of units){s/=1024;u=x;if(s<1024)break;} return `${s>=10?s.toFixed(0):s.toFixed(1)} ${u}`; };
    const getApiUrl     = (action) => { const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action',action); return u; };

    const setStatus = (text, kind='muted') => {
      const v = String(text||'').trim();
      if (!v) { explorerStatus.textContent=''; explorerStatus.className='hidden'; return; }
      explorerStatus.className = 'mt-4 text-sm '+({ok:'status-ok',warn:'status-warn',err:'status-err',info:'status-info'}[kind]||'text-muted-foreground');
      explorerStatus.textContent = v;
    };
    const setCheckboxState = (btn, checked) => { if (!btn) return; btn.setAttribute('aria-checked',checked?'true':'false'); btn.setAttribute('data-state',checked?'checked':'unchecked'); };

    const sortItems = (items) => {
      const list = [...items];
      list.sort((a,b) => {
        const nA=String(a?.name||'').toLocaleLowerCase('fr'), nB=String(b?.name||'').toLocaleLowerCase('fr');
        if (currentSort==='name-desc') return nB.localeCompare(nA,'fr',{numeric:true,sensitivity:'base'});
        if (currentSort==='mtime-desc') { const d=(Date.parse(String(b?.mtime||''))||0)-(Date.parse(String(a?.mtime||''))||0); return d!==0?d:nA.localeCompare(nB,'fr',{numeric:true,sensitivity:'base'}); }
        if (currentSort==='size-desc') { const d=Number(b?.size||0)-Number(a?.size||0); return d!==0?d:nA.localeCompare(nB,'fr',{numeric:true,sensitivity:'base'}); }
        if (currentSort==='type-asc') { const tA=getItemType(a),tB=getItemType(b); if(tA!==tB) return tA.localeCompare(tB,'fr'); }
        return nA.localeCompare(nB,'fr',{numeric:true,sensitivity:'base'});
      });
      return list;
    };
    const getVisibleItems = (items) => {
      let list = Array.isArray(items)?[...items]:[];
      if (currentSearch) list=list.filter(item=>String(item?.name||'').toLowerCase().includes(currentSearch)||String(item?.path||'').toLowerCase().includes(currentSearch));
      return sortItems(list);
    };

    const renderSubtitlePath = () => {
      if (!explorerCardSubtitle) return;
      const root=currentMount?normalizePath(currentMount.mountPath||'/'):'/';
      const current=normalizePath(currentPath,root);
      explorerCardSubtitle.innerHTML='';
      const wrapper=document.createElement('div'); wrapper.className='explorer-path';
      const prefix=document.createElement('span'); prefix.className='explorer-path-prefix'; prefix.textContent=<?= json_encode(t('Chemin :'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; wrapper.appendChild(prefix);
      const slash0=document.createElement('span'); slash0.className='explorer-path-sep mono'; slash0.textContent='/'; wrapper.appendChild(slash0);
      const parts=current.split('/').filter(Boolean), rootParts=root.split('/').filter(Boolean);
      const clickableStart=Math.max(rootParts.length-1,0);
      let built='';
      parts.forEach((part,index) => {
        built+='/'+part;
        const segPath=normalizePath(built), isClickable=index>=clickableStart;
        const node=document.createElement(isClickable?'button':'span');
        node.className=(isClickable?'explorer-path-link':'explorer-path-text')+' mono';
        node.textContent=part;
        if (isClickable) { node.type='button'; node.addEventListener('click',()=>navigateToPath(segPath)); }
        wrapper.appendChild(node);
        if (index<parts.length-1) { const sep=document.createElement('span'); sep.className='explorer-path-sep mono'; sep.textContent='/'; wrapper.appendChild(sep); }
      });
      explorerCardSubtitle.appendChild(wrapper);
    };

    const renderBreadcrumbs = () => {
      breadcrumbsEl.innerHTML='';
      if (!currentMount) { renderSubtitlePath(); return; }
      const root=normalizePath(currentMount.mountPath||'/'), current=normalizePath(currentPath,root);
      renderSubtitlePath();
      const rootParts=root.split('/').filter(Boolean), currentParts=current.split('/').filter(Boolean);
      const makeCrumb=(label,path)=>{ const btn=document.createElement('button'); btn.type='button'; btn.className='text-sm hover:underline'; btn.innerHTML=label; btn.addEventListener('click',()=>navigateToPath(path)); return btn; };
      breadcrumbsEl.appendChild(makeCrumb(`<span class="mono text-muted-foreground">${escHtml(currentMount.claimName||'PVC')} ${escHtml(root)}</span>`,root));
      let built='';
      for (let i=rootParts.length;i<currentParts.length;i++) {
        built+='/'+currentParts[i];
        const sep=document.createElement('span'); sep.className='crumb-sep text-muted-foreground'; sep.textContent='/'; breadcrumbsEl.appendChild(sep);
        breadcrumbsEl.appendChild(makeCrumb(escHtml(currentParts[i]),normalizePath(root+built)));
      }
      if (current!==root) {
        const upBtn=document.createElement('button'); upBtn.type='button'; upBtn.title=<?= json_encode(t('Dossier parent'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        upBtn.className='ml-2 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground hover:underline transition-colors';
        upBtn.innerHTML=`<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>..`;
        upBtn.addEventListener('click',()=>navigateToPath(parentPath(currentPath,root)));
        breadcrumbsEl.appendChild(upBtn);
      }
    };

    const renderDirectorySummary = (items) => {
      if (!explorerMeta||!currentMount) return;
      const list=Array.isArray(items)?items:[];
      const dirs=list.filter(i=>getItemType(i)==='dir').length, files=list.length-dirs;
      explorerMeta.innerHTML=`Namespace <span class="mono">${escHtml(USER_NAMESPACE)}</span> • PVC <span class="mono">${escHtml(currentMount.claimName||'')}</span> • Container <span class="mono">${escHtml(currentMount.container||'')}</span> • ${list.length} élément${list.length!==1?'s':''} (${dirs} dossier${dirs!==1?'s':''},  ${files} fichier${files!==1?'s':''})`;
    };

    const renderMountTabs = () => {
      if (!mountTabs) return;
      mountTabs.innerHTML='';
      if (!Array.isArray(mounts)||mounts.length===0) return;
      mounts.forEach(mount => {
        const isActive=currentMount&&getMountKey(currentMount)===getMountKey(mount);
        const btn=document.createElement('button'); btn.type='button'; btn.role='tab';
        btn.setAttribute('aria-selected',isActive?'true':'false'); btn.setAttribute('data-state',isActive?'active':'inactive'); btn.setAttribute('data-slot','tabs-trigger');
        btn.className='data-[state=active]:bg-background dark:data-[state=active]:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 text-foreground dark:text-muted-foreground inline-flex h-[calc(100%-1px)] items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] focus-visible:ring-[3px] disabled:pointer-events-none disabled:opacity-50 data-[state=active]:shadow-sm shrink-0';
        btn.textContent=mount.container||mount.claimName||'Montage';
        btn.addEventListener('click',()=>switchMount(mount));
        mountTabs.appendChild(btn);
      });
    };

    const syncSelectAllState = () => {
      if (!selectAllRowsBtn||currentItems.length===0) { setCheckboxState(selectAllRowsBtn,false); return; }
      setCheckboxState(selectAllRowsBtn,currentItems.map(getRowKey).every(k=>selectedRows.has(k)));
    };

    const renderTableMessage = (msg) => {
      fileListBody.innerHTML=`<tr><td colspan="${TABLE_COLSPAN}" class="border-surface border-b p-4 text-muted-foreground">${escHtml(msg)}</td></tr>`;
      currentItems=[]; selectedRows=new Set(); setCheckboxState(selectAllRowsBtn,false);
    };

    const renderRows = (items) => {
      currentItems=Array.isArray(items)?items:[];
      fileListBody.innerHTML='';
      if (currentItems.length===0) {
        renderTableMessage(directoryItems.length===0?<?= json_encode(t('Ce dossier est vide.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>:<?= json_encode(t('Aucun élément ne correspond aux filtres actifs.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        renderDirectorySummary(directoryItems); return;
      }
      currentItems.forEach((item,index) => {
        const isDir=getItemType(item)==='dir', name=String(item?.name||'');
        const nextPath=normalizePath(item?.path||joinPath(currentPath,name)), rowKey=getRowKey(item), cbId=`file-row-${index}`;
        const tr=document.createElement('tr');
        tr.className=isDir?'cursor-pointer hover:bg-accent/30':'hover:bg-accent/10';
        tr.innerHTML=`
          <td class="border-surface border-b p-4">
            <div class="flex items-center gap-2">
              <button type="button" role="checkbox" aria-checked="${selectedRows.has(rowKey)?'true':'false'}" data-state="${selectedRows.has(rowKey)?'checked':'unchecked'}" value="on" data-slot="checkbox"
                class="row-select peer border-input dark:bg-input/30 data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-primary size-4 shrink-0 rounded-[4px] border shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
                data-row-key="${escHtml(rowKey)}" id="${cbId}"></button>
              <input type="checkbox" aria-hidden="true" tabindex="-1" style="position:absolute;pointer-events:none;opacity:0;margin:0;transform:translateX(-100%)" value="on"/>
              <label for="${cbId}" class="text-foreground block text-sm font-medium">${escHtml(name||'(sans nom)')}</label>
            </div>
          </td>
          <td class="border-surface border-b p-4"><p class="text-foreground block text-sm mono">${escHtml(item?.mtime?String(item.mtime):'—')}</p></td>
          <td class="border-surface border-b p-4">
            <span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 gap-1 overflow-hidden w-max" data-slot="badge">
              ${escHtml(isDir?'Dossier':'Fichier')}
            </span>
          </td>
          <td class="border-surface border-b p-4"><p class="text-foreground block text-sm">${isDir?'—':escHtml(formatBytes(item?.size))}</p></td>
          <td class="border-surface border-b p-4 text-end">
            <button type="button" class="open-row inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 hover:bg-accent hover:text-accent-foreground size-9" ${isDir?'':'disabled'} aria-label="${isDir?'Ouvrir le dossier':'Aucune action'}">
              <svg class="h-5 w-5 stroke-2" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>
              </svg>
            </button>
          </td>`;
        const goToRow=async()=>{ if(isDir) await navigateToPath(nextPath); };
        if (isDir) tr.addEventListener('click',goToRow);
        tr.querySelector('.row-select')?.addEventListener('click',(e)=>{ e.stopPropagation(); selectedRows.has(rowKey)?selectedRows.delete(rowKey):selectedRows.add(rowKey); setCheckboxState(e.currentTarget,selectedRows.has(rowKey)); syncSelectAllState(); });
        tr.querySelector('.open-row')?.addEventListener('click',async(e)=>{ e.stopPropagation(); await goToRow(); });
        fileListBody.appendChild(tr);
      });
      renderDirectorySummary(directoryItems);
      syncSelectAllState();
    };

    const renderVisibleRows = () => renderRows(getVisibleItems(directoryItems));

    const navigateToPath = async (path) => {
      const root=currentMount?normalizePath(currentMount.mountPath||'/'):'/';
      const safe=normalizePath(path,root);
      currentPath=safe.startsWith(root)?safe:root;
      renderBreadcrumbs();
      await loadDirectory(currentPath);
    };

    const switchMount = (mount) => {
      currentMount=mount; currentPath=normalizePath(mount.mountPath||'/'); directoryItems=[]; selectedRows=new Set();
      currentSearch=''; if(explorerSearchInput) explorerSearchInput.value='';
      currentSort='name-asc'; if(explorerSort) explorerSort.value='name-asc';
      renderMountTabs(); renderBreadcrumbs(); renderDirectorySummary([]);
      loadDirectory(currentPath);
    };

    const loadStorageMeta = async () => {
      const url=getApiUrl('get_deployment_storage'); url.searchParams.set('deployment',DEPLOYMENT_NAME);
      try {
        const res=await fetch(url.toString(),{credentials:'same-origin'}); const raw=await res.text(); let data=null; try{data=JSON.parse(raw);}catch(_){}
        if(!res.ok||!data?.ok) throw new Error(data?.error||`HTTP ${res.status}`);
        const nextMounts=Array.isArray(data.mounts)?data.mounts:[];
        if (nextMounts.length>0) {
          mounts=nextMounts;
          const same=currentMount?mounts.find(m=>getMountKey(m)===getMountKey(currentMount)):null;
          if(same){currentMount=same;}else{currentMount=mounts[0];currentPath=normalizePath(currentMount?.mountPath||'/');}
          renderMountTabs(); renderBreadcrumbs(); return true;
        }
        mounts=[]; currentMount=null; currentPath='/'; directoryItems=[];
        renderMountTabs(); renderBreadcrumbs(); renderDirectorySummary([]);
        renderTableMessage(<?= json_encode(t('Aucun montage PVC détecté.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>); setStatus(<?= json_encode(t('Aucun montage PVC détecté.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn'); return false;
      } catch(e) {
        const msg=e?.message||String(e);
        setStatus(/unknown action|not found|404/i.test(msg)?`Le backend n'expose pas encore l'action get_deployment_storage. Utilisation des données locales.`:'Impossible de recharger les montages : '+msg,/unknown action|not found|404/i.test(msg)?'info':'warn');
        return false;
      }
    };

    const loadDirectory = async (path) => {
      if (!currentMount) { directoryItems=[]; renderDirectorySummary([]); renderTableMessage(<?= json_encode(t('Sélectionne un volume pour commencer.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>); setStatus(<?= json_encode(t('Sélectionne un volume pour commencer.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn'); return; }
      const safePath=normalizePath(path,currentMount.mountPath||'/'); currentPath=safePath;
      setStatus(<?= json_encode(t('Chargement du dossier…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted'); renderTableMessage(<?= json_encode(t('Chargement…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
      const url=getApiUrl('list_files');
      url.searchParams.set('deployment',DEPLOYMENT_NAME); url.searchParams.set('container',String(currentMount.container||''));
      url.searchParams.set('claim',String(currentMount.claimName||'')); url.searchParams.set('mountPath',String(currentMount.mountPath||'/'));
      url.searchParams.set('path',safePath);
      try {
        const res=await fetch(url.toString(),{credentials:'same-origin'}); const raw=await res.text(); let data=null; try{data=JSON.parse(raw);}catch(_){}
        if(!res.ok||!data?.ok) throw new Error(data?.error||`HTTP ${res.status}`);
        directoryItems=Array.isArray(data.items)?data.items:[];
        renderBreadcrumbs(); renderVisibleRows(); setStatus('','muted');
      } catch(e) {
        directoryItems=[]; renderDirectorySummary([]); renderTableMessage(<?= json_encode(t('Impossible de charger les éléments de ce dossier.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        const msg=e?.message||String(e);
        setStatus(/unknown action|not found|404/i.test(msg)?`Le backend n'expose pas encore l'action list_files.`:'Impossible de lister ce dossier : '+msg,/unknown action|not found|404/i.test(msg)?'info':'err');
      }
    };

    reloadDirBtn?.addEventListener('click',async()=>{ reloadDirBtn.disabled=true; try{await loadStorageMeta();if(currentMount)await loadDirectory(currentPath);}finally{reloadDirBtn.disabled=false;} });
    selectAllRowsBtn?.addEventListener('click',()=>{ if(currentItems.length===0){setCheckboxState(selectAllRowsBtn,false);return;} const keys=currentItems.map(getRowKey),shouldSelect=!keys.every(k=>selectedRows.has(k)); keys.forEach(k=>shouldSelect?selectedRows.add(k):selectedRows.delete(k)); renderVisibleRows(); });
    explorerSearchInput?.addEventListener('input',()=>{ currentSearch=explorerSearchInput.value.trim().toLowerCase(); renderVisibleRows(); });
    explorerSort?.addEventListener('change',()=>{ currentSort=explorerSort.value||'name-asc'; renderVisibleRows(); });

    renderMountTabs(); renderBreadcrumbs(); renderDirectorySummary([]);
    if (currentMount) loadDirectory(currentPath);
    else { renderTableMessage(<?= json_encode(t('Aucun volume PVC détecté pour ce déploiement.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>); setStatus(<?= json_encode(t('Aucun volume PVC détecté.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn'); }
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       VARIABLES SECRÈTES
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    const host          = document.getElementById('secretTools');
    const createToggle  = document.getElementById('secretCreateToggle');
    const createPanel   = document.getElementById('secretCreatePanel');
    const createEnv     = document.getElementById('secretCreateEnv');
    const createSecret  = document.getElementById('secretCreateSecret');
    const createValue   = document.getElementById('secretCreateValue');
    const createStatus  = document.getElementById('secretCreateStatus');
    const createSubmit  = document.getElementById('secretCreateSubmit');
    if (!host) return;

    const apiUrl = new URL('../data/k8s_api.php', window.location.href);
    apiUrl.searchParams.set('action', 'list_deployment_secret_variables');
    apiUrl.searchParams.set('deployment', DEPLOYMENT_NAME);

    const escHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

    const setMsg = (el, text, kind='muted') => {
      if (!el) return;
      el.className = 'text-xs ' + ({ok:'text-emerald-600',warn:'text-amber-600',err:'text-red-600'}[kind]||'text-muted-foreground');
      el.textContent = text;
    };

    const readJson = async (res, url) => {
      const ct = (res.headers.get('content-type')||'').toLowerCase(), raw = await res.text();
      let data=null; try{data=JSON.parse(raw);}catch(_){}
      if (!ct.includes('application/json')||!data) throw new Error(`Réponse non-JSON (${res.status}). URL: ${url.pathname}. `+raw.slice(0,200).replace(/\s+/g,' '));
      if (!res.ok||!data.ok) throw new Error(data.error||('HTTP '+res.status));
      return data;
    };

    const setCreatePanelOpen = (open) => {
      if (!createPanel) return;
      createPanel.classList.toggle('hidden',!open);
      if (createToggle) createToggle.textContent = open?<?= json_encode(t('Fermer'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>:<?= json_encode(t('Nouvelle variable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    };

    const populateSecretOptions = (secrets) => {
      if (!createSecret) return;
      createSecret.innerHTML='';
      if (!Array.isArray(secrets)||secrets.length===0) {
        const o=document.createElement('option'); o.value=''; o.textContent='Aucun secret disponible'; createSecret.appendChild(o);
        createSecret.disabled=true; if(createSubmit) createSubmit.disabled=true; return;
      }
      createSecret.disabled=false; if(createSubmit) createSubmit.disabled=false;
      for (const name of secrets) { const o=document.createElement('option'); o.value=name; o.textContent=name; createSecret.appendChild(o); }
    };

    /* modal delete var */
    const deleteVarModal       = document.getElementById('deleteVarModal');
    const deleteVarModalForm   = document.getElementById('deleteVarModalForm');
    const deleteVarModalInput  = document.getElementById('deleteVarModalInput');
    const deleteVarModalText   = document.getElementById('deleteVarModalText');
    const deleteVarModalStatus = document.getElementById('deleteVarModalStatus');
    const deleteVarModalClose  = document.getElementById('deleteVarModalClose');
    const deleteVarModalCancel = document.getElementById('deleteVarModalCancel');
    let deleteVarModalResolver=null, deleteVarExpectedName='';

    const openDeleteVarModal = (entry) => new Promise((resolve) => {
      deleteVarModalResolver=resolve; deleteVarExpectedName=String(entry?.envName||'').trim();
      if(deleteVarModalText) deleteVarModalText.textContent=`Saisissez le nom de la variable (${deleteVarExpectedName}) pour confirmer sa suppression irréversible.`;
      if(deleteVarModalInput) deleteVarModalInput.value='';
      if(deleteVarModalStatus){deleteVarModalStatus.className='text-xs text-muted-foreground';deleteVarModalStatus.textContent='';}
      deleteVarModal?.classList.remove('hidden'); deleteVarModal?.classList.add('flex');
      requestAnimationFrame(()=>deleteVarModalInput?.focus());
    });

    const closeDeleteVarModal = (confirmed=false) => {
      deleteVarModal?.classList.remove('flex'); deleteVarModal?.classList.add('hidden');
      if(deleteVarModalInput) deleteVarModalInput.value='';
      if(deleteVarModalStatus){deleteVarModalStatus.className='text-xs text-muted-foreground';deleteVarModalStatus.textContent='';}
      const res=deleteVarModalResolver; deleteVarModalResolver=null; deleteVarExpectedName=''; if(res) res(confirmed);
    };

    deleteVarModalClose?.addEventListener('click',()=>closeDeleteVarModal(false));
    deleteVarModalCancel?.addEventListener('click',()=>closeDeleteVarModal(false));
    deleteVarModal?.addEventListener('click',(e)=>{ if(e.target===deleteVarModal) closeDeleteVarModal(false); });
    document.addEventListener('keydown',(e)=>{ if(e.key==='Escape'&&deleteVarModal?.classList.contains('flex')) closeDeleteVarModal(false); });
    deleteVarModalForm?.addEventListener('submit',(e)=>{
      e.preventDefault();
      const v=String(deleteVarModalInput?.value||'').trim();
      if(!v){if(deleteVarModalStatus){deleteVarModalStatus.className='text-xs text-amber-600';deleteVarModalStatus.textContent=<?= json_encode(t('Saisis le nom de la variable pour confirmer.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;}deleteVarModalInput?.focus();return;}
      if(v!==deleteVarExpectedName){if(deleteVarModalStatus){deleteVarModalStatus.className='text-xs text-red-600';deleteVarModalStatus.textContent=<?= json_encode(t('Le nom saisi ne correspond pas à la variable à supprimer.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;}deleteVarModalInput?.focus();deleteVarModalInput?.select();return;}
      closeDeleteVarModal(true);
    });

    const buildRow = (entry) => {
      const id='secret_'+[entry.container,entry.envName,entry.secretName,entry.secretKey].join('_').replace(/[^a-z0-9_-]/gi,'_');
      const wrap=document.createElement('div'); wrap.className='bg-background rounded-lg border p-4 mt-4';
      wrap.innerHTML=`
        <div class="secret-env-row">
          <div class="secret-env-meta">
            <div class="text-sm font-medium">Variable d'environnement : <span class="mono">${escHtml(entry.envName||'')}</span></div>
            <div class="text-xs text-muted-foreground mt-1">• Secret : <span class="mono">${escHtml(entry.secretName||'')}</span></div>
          </div>
          <div class="secret-env-controls">
            <label class="sr-only" for="${id}_value">Nouvelle valeur pour ${escHtml(entry.envName||'')}</label>
            <div class="secret-env-form">
              <input id="${id}_value" type="password" class="secret-env-input h-10 rounded-md border bg-background px-3 text-sm"
                placeholder=<?= json_encode(t('Valeur actuelle masquée — saisir une nouvelle valeur'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> autocomplete="new-password"/>
              <button type="button" data-action="save" class="secret-env-button h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Enregistrer</button>
              <button type="button" data-action="delete" class="secret-env-button h-10 rounded-md border px-3 text-sm hover:bg-secondary transition-colors">Supprimer</button>
            </div>
            <div class="mt-2 text-xs text-muted-foreground" id="${id}_status"></div>
          </div>
        </div>`;

      const input=wrap.querySelector('#'+id+'_value'), saveBtn=wrap.querySelector('[data-action="save"]'), deleteBtn=wrap.querySelector('[data-action="delete"]'), status=wrap.querySelector('#'+id+'_status');
      const canDelete=entry.source==='secretRef';
      if(deleteBtn&&!canDelete){deleteBtn.disabled=true;deleteBtn.title=<?= json_encode(t('Suppression indisponible pour les variables définies directement dans le deployment.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;}

      const submit=async()=>{
        if(!input||!saveBtn) return;
        const value=input.value;
        if(!value){setMsg(status,"Saisis une nouvelle valeur avant d'enregistrer.",'warn');return;}
        saveBtn.disabled=true; if(deleteBtn) deleteBtn.disabled=true; input.disabled=true; setMsg(status,<?= json_encode(t('Mise à jour du secret…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted');
        try {
          const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','update_deployment_secret_variable');
          const res=await fetch(u.toString(),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams({name:DEPLOYMENT_NAME,container:entry.container||'',env:entry.envName||'',secret:entry.secretName||'',key:entry.secretKey||'',value})});
          await readJson(res,u); input.value=''; setMsg(status,'Valeur enregistrée. La valeur existante reste masquée dans le portail.','ok');
        } catch(e){setMsg(status,'Erreur : '+(e?.message||String(e)),'err');}
        finally{saveBtn.disabled=false;if(deleteBtn) deleteBtn.disabled=false;input.disabled=false;}
      };

      const removeVariable=async()=>{
        if(!deleteBtn||!saveBtn||!input||!canDelete){setMsg(status,<?= json_encode(t('Suppression indisponible pour cette variable.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn');return;}
        const confirmed=await openDeleteVarModal(entry); if(!confirmed) return;
        deleteBtn.disabled=true; saveBtn.disabled=true; input.disabled=true; setMsg(status,<?= json_encode(t('Suppression de la variable…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted');
        try {
          const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','delete_deployment_secret_variable');
          const res=await fetch(u.toString(),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams({name:DEPLOYMENT_NAME,container:entry.container||'',env:entry.envName||'',secret:entry.secretName||'',key:entry.secretKey||''})});
          await readJson(res,u); setMsg(status,<?= json_encode(t('Variable supprimée.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'ok'); await loadSecretVariables();
        } catch(e){setMsg(status,'Erreur : '+(e?.message||String(e)),'err');deleteBtn.disabled=false;saveBtn.disabled=false;input.disabled=false;}
      };

      saveBtn?.addEventListener('click',submit);
      deleteBtn?.addEventListener('click',removeVariable);
      input?.addEventListener('keydown',(e)=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();submit();}});
      return wrap;
    };

    const syncSecretMetaWidth=()=>{
      const metas=Array.from(host.querySelectorAll('.secret-env-meta'));
      if(!metas.length){host.style.removeProperty('--secret-meta-width');return;}
      const widths=metas.map(el=>Math.ceil(el.getBoundingClientRect().width)), maxWidth=Math.max(...widths,260), clamped=Math.min(maxWidth,520);
      host.style.setProperty('--secret-meta-width',`${clamped}px`);
    };

    const renderList=(entries,secretErrors)=>{
      host.innerHTML='';
      if(!Array.isArray(entries)||entries.length===0) {host.innerHTML='<div class="text-sm text-muted-foreground">Aucune variable de secret détectée pour ce deployment.</div>';}
      else{for(const entry of entries) host.appendChild(buildRow(entry));syncSecretMetaWidth();}
      const errors=secretErrors&&typeof secretErrors==='object'?Object.entries(secretErrors):[];
      if(errors.length>0){
        const alert=document.createElement('div'); alert.className='rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800';
        alert.innerHTML=`<div class="font-medium">Certains secrets n'ont pas pu être inspectés.</div><ul class="mt-2 list-disc pl-5">${errors.map(([name,error])=>`<li><span class="mono">${escHtml(name)}</span> : ${escHtml(error)}</li>`).join('')}</ul>`;
        host.appendChild(alert);
      }
    };
    window.addEventListener('resize',syncSecretMetaWidth);

    const loadSecretVariables=async()=>{
      const res=await fetch(apiUrl.toString(),{credentials:'same-origin'}); const data=await readJson(res,apiUrl);
      populateSecretOptions(Array.isArray(data.secrets)?data.secrets:[]);
      renderList(Array.isArray(data.entries)?data.entries:[],data.secretErrors);
    };

    const resetCreateForm=()=>{ if(createEnv) createEnv.value=''; if(createSecret) createSecret.value=''; if(createValue) createValue.value=''; };

    const createVariable=async()=>{
      const payload={name:DEPLOYMENT_NAME,env:createEnv?createEnv.value.trim():'',secret:createSecret?createSecret.value.trim():'',value:createValue?createValue.value:''};
      if(!payload.env||!payload.secret){setMsg(createStatus,<?= json_encode(t('Renseigne la variable / clé et le secret.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn');return;}
      if(createSubmit) createSubmit.disabled=true; setMsg(createStatus,<?= json_encode(t('Création de la variable dans le secret existant…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted');
      try {
        const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','create_deployment_secret_variable');
        const res=await fetch(u.toString(),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams(payload)});
        const data=await readJson(res,u); resetCreateForm();
        setMsg(createStatus,data?.deploymentRestarted?<?= json_encode(t('Variable créée. Le déploiement redémarre automatiquement.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>:<?= json_encode(t('Variable créée dans le secret.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'ok');
        await loadSecretVariables();
      } catch(e){setMsg(createStatus,'Erreur : '+(e?.message||String(e)),'err');}
      finally{if(createSubmit) createSubmit.disabled=false;}
    };

    createToggle?.addEventListener('click',()=>{const open=createPanel?createPanel.classList.contains('hidden'):false;setCreatePanelOpen(open);});
    createSubmit?.addEventListener('click',createVariable);

    (async()=>{ try{await loadSecretVariables();}catch(e){host.innerHTML=`<div class="text-sm text-red-600"><strong>Erreur :</strong> ${escHtml(e?.message||String(e))}</div>`;populateSecretOptions([]);} })();
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       IMAGES / VERSION UPDATER
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    const host=document.getElementById('imageTools'); if(!host) return;
    const apiUrl=new URL('../data/k8s_api.php',window.location.href); apiUrl.searchParams.set('action','list_deployment_images'); apiUrl.searchParams.set('deployment',DEPLOYMENT_NAME);
    const escHtml=(s)=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    const setMsg=(el,text,kind='muted')=>{ el.className='text-xs '+({ok:'text-emerald-600',warn:'text-amber-600',err:'text-red-600'}[kind]||'text-muted-foreground'); el.textContent=text; };

    const buildRow=(c)=>{
      const id='c_'+c.name.replace(/[^a-z0-9_-]/gi,'_'), current=c.currentTag||'(sans tag)', latest=c.latestTag;
      const wrap=document.createElement('div'); wrap.className='bg-background rounded-lg border px-3 py-2 h-full';
      wrap.innerHTML=`
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div class="min-w-0 flex-1">
            <div class="text-sm font-medium">Version Updater : <span class="mono">${escHtml(c.name)}</span></div>
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
              <div class="text-xs text-muted-foreground mono" id="${id}_current">Actuel : ${escHtml(current)}</div>
              <div id="${id}_info" class="text-xs text-muted-foreground"></div>
            </div>
            <div id="${id}_status" class="mt-2 text-xs text-muted-foreground"></div>
          </div>
          <div class="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:flex-nowrap lg:justify-end">
            <select id="${id}_sel" class="h-9 min-w-[12rem] flex-1 rounded-md border bg-background px-3 text-sm lg:flex-none">
              <option value="">Chargement…</option>
            </select>
          </div>
        </div>`;

      const sel=wrap.querySelector('#'+id+'_sel'), currentEl=wrap.querySelector('#'+id+'_current'), info=wrap.querySelector('#'+id+'_info'), status=wrap.querySelector('#'+id+'_status');
      sel.innerHTML='';
      const tags=Array.isArray(c.availableTags)?c.availableTags:[];
      if(tags.length===0){sel.innerHTML='<option value="">Indisponible</option>';sel.disabled=true;setMsg(info,c.note||<?= json_encode(t('Pas de liste de versions pour cette image.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn');}
      else{
        for(const t of tags){const opt=document.createElement('option');opt.value=t;opt.textContent=t;if(c.currentTag&&t===c.currentTag) opt.selected=true;sel.appendChild(opt);}
        if(c.note) setMsg(info,c.note,'warn');
        else if(c.hasUpdate&&latest&&c.currentTag&&latest!==c.currentTag) setMsg(info,`Nouvelle version disponible : ${latest}`,'ok');
        else setMsg(info,<?= json_encode(t('À jour.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted');
      }

      const postUpdate=async()=>{
        const tag=sel.value, previousTag=c.currentTag||'';
        if(!tag){setMsg(status,<?= json_encode(t('Choisis un tag.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'warn');return;}
        sel.disabled=true; setMsg(status,<?= json_encode(t('Mise à jour en cours…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted');
        try {
          const u=new URL('../data/k8s_api.php',window.location.href); u.searchParams.set('action','set_deployment_image_tag');
          const res=await fetch(u.toString(),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF_TOKEN},body:new URLSearchParams({name:DEPLOYMENT_NAME,container:c.name,tag})});
          const ct=(res.headers.get('content-type')||'').toLowerCase(), raw=await res.text(); let data=null; try{data=JSON.parse(raw);}catch(_){}
          if(!ct.includes('application/json')||!data) throw new Error(`Réponse non-JSON (${res.status}). `+raw.slice(0,200).replace(/\s+/g,' '));
          if(!res.ok||!data.ok) throw new Error(data.error||('HTTP '+res.status));
          c.currentTag=tag; c.currentImage=data.newImage||c.currentImage;
          if(currentEl) currentEl.textContent=`Actuel : ${tag}`;
          if(c.latestTag&&c.latestTag!==tag) setMsg(info,`Nouvelle version disponible : ${c.latestTag}`,'ok');
          else setMsg(info,<?= json_encode(t('À jour.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'muted');
          setMsg(status,<?= json_encode(t('Ok. Image mise à jour. Kubernetes va lancer un rollout.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,'ok');
        } catch(e){sel.value=previousTag;setMsg(status,'Erreur : '+(e?.message||String(e)),'err');}
        finally{sel.disabled=false;}
      };

      sel.addEventListener('change',()=>{
        if(!sel.value||sel.value===c.currentTag){setMsg(status,sel.value===c.currentTag?<?= json_encode(t('Cette version est déjà appliquée.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>:<?= json_encode(t('Choisis un tag.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,sel.value===c.currentTag?'muted':'warn');return;}
        void postUpdate();
      });
      return wrap;
    };

    (async()=>{
      try {
        const res=await fetch(apiUrl.toString(),{credentials:'same-origin'}); const ct=(res.headers.get('content-type')||'').toLowerCase(), raw=await res.text(); let data=null; try{data=JSON.parse(raw);}catch(_){}
        if(!ct.includes('application/json')||!data) throw new Error(`Réponse non-JSON (${res.status}). `+raw.slice(0,200).replace(/\s+/g,' '));
        if(!res.ok||!data.ok) throw new Error(data.error||('HTTP '+res.status));
        const containers=Array.isArray(data.containers)?data.containers:[];
        host.innerHTML='';
        if(containers.length===0){host.innerHTML='<div class="text-sm text-muted-foreground">Aucun container trouvé dans ce deployment.</div>';return;}
        for(const c of containers) host.appendChild(buildRow(c));
      } catch(e){host.innerHTML=`<div class="text-sm text-red-600"><strong>Erreur :</strong> ${escHtml(e?.message||String(e))}</div>`;}
    })();
  })();
  </script>

  <!-- ══════════════════════════════════════════════════════════════
       COLLAPSIBLES
  ══════════════════════════════════════════════════════════════ -->
  <script>
  (function(){
    function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
    ready(function(){
      document.querySelectorAll('[data-slot="collapsible-trigger"]').forEach(function(btn){
        btn.classList.add('collapsible-trigger');
        const targetId=btn.getAttribute('aria-controls');
        let content=targetId?document.getElementById(targetId):null;
        if(!content){const p=btn.closest('[data-slot="collapsible"]');if(p) content=p.querySelector('[data-slot="collapsible-content"]');}
        if(!content) return;
        content.classList.add('collapsible-content');
        const chev=btn.querySelector('.lucide-chevron-right'); if(chev) chev.classList.add('collapsible-chevron');
        const expanded=btn.getAttribute('aria-expanded')==='true';
        if(expanded){content.hidden=false;content.classList.add('is-open');content.style.height='auto';}
        else{content.hidden=true;content.classList.remove('is-open');content.style.height='0px';}
        btn.addEventListener('click',function(e){
          e.preventDefault();
          const isOpen=btn.getAttribute('aria-expanded')==='true';
          if(!isOpen){
            btn.setAttribute('aria-expanded','true');btn.setAttribute('data-state','open');
            content.hidden=false;content.classList.add('is-open');content.setAttribute('data-state','open');
            content.style.height='0px';const h=content.scrollHeight;requestAnimationFrame(()=>{content.style.height=h+'px';});
            const onEnd=(ev)=>{if(ev.propertyName!=='height') return;content.style.height='auto';content.removeEventListener('transitionend',onEnd);};
            content.addEventListener('transitionend',onEnd);
          } else {
            btn.setAttribute('aria-expanded','false');btn.setAttribute('data-state','closed');
            content.classList.remove('is-open');content.setAttribute('data-state','closed');
            const cur=content.scrollHeight;content.style.height=cur+'px';requestAnimationFrame(()=>{content.style.height='0px';});
            const onEndClose=(ev)=>{if(ev.propertyName!=='height') return;content.hidden=true;content.removeEventListener('transitionend',onEndClose);};
            content.addEventListener('transitionend',onEndClose);
          }
        },{passive:false});
      });
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>