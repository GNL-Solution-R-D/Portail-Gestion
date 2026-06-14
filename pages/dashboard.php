<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/session_bootstrap.php';
require_once __DIR__ . '/../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once __DIR__ . '/../include/session_user.php';

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// Client API portail (stats dashboard). Inclusion BEST-EFFORT : une absence ou
// un décalage de déploiement (ancien client sans portailFetchDashboardStats())
// ne doit JAMAIS empêcher le rendu de la page → pas de require « dur » ici.
$portailClientPath = __DIR__ . '/../include/portail_api_client.php';
if (is_readable($portailClientPath)) {
    require_once $portailClientPath;
}

require_once '../data/zabbix_api.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, sessionUserId())) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}

accountSessionsTouchCurrent($pdo, sessionUserId());

$name         = sessionUserField('nom') ?: sessionUserField('name');
$siret        = sessionUserField('siret');
$perm_id      = sessionUserField('perm_id');
$user_account = sessionUserId();

$k8s_namespace = sessionUserNamespace();

// ── Domaines PowerDNS ────────────────────────────────────────────────────────
$domains = [];
if (isset($pdo_powerdns) && $pdo_powerdns instanceof PDO) {
    try {
        $query_domains = $pdo_powerdns->prepare(
            'SELECT id, name FROM domains WHERE account = ?'
        );
        $query_domains->execute([$user_account]);
        $domains = $query_domains->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($domains)) $domains = [];
    } catch (Throwable $exception) {
        error_log('[dashboard] PowerDNS domains: ' . $exception->getMessage());
        $domains = [];
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

if (!function_exists('dashboardExtractErrorCode')) {
    function dashboardExtractErrorCode(Throwable $e): ?string
    {
        $code = $e->getCode();
        if ((is_int($code) || preg_match('/^-?\d+$/', (string)$code)) && (int)$code !== 0) {
            return (string)(int)$code;
        }
        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $e->getMessage(), $m)) return $m[1];
        if (preg_match('/\bstatus(?:\s+code)?\s*[:=]?\s*(\d{3})\b/i', $e->getMessage(), $m)) return $m[1];
        return null;
    }
}

if (!function_exists('dashboardRenderWidgetErrorBadge')) {
    function dashboardRenderWidgetErrorBadge(?string $errorCode): string
    {
        if ($errorCode === null || $errorCode === '') return '';
        $safeCode = htmlspecialchars($errorCode, ENT_QUOTES, 'UTF-8');
        return '<span data-slot="badge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 transition-[color,box-shadow] overflow-hidden border-transparent gap-1 bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-400">Erreur ' . $safeCode . '</span>';
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// Amélioration #5 — deploymentBaseDomainFromHost() définie ici (idem deployment.php)
//   Avant : closure $k8sBaseDomain locale, logique dupliquée
//   → nommée en fonction globale pour être réutilisable via include futur
// ══════════════════════════════════════════════════════════════════════════════
if (!function_exists('dashboardBaseDomainFromHost')) {
    function dashboardBaseDomainFromHost(string $host): string
    {
        $host = strtolower(trim(rtrim($host, '.')));
        if (str_starts_with($host, '*.')) $host = substr($host, 2);
        $host = (string)preg_replace('/:\d+$/', '', $host);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return $host;
        $parts = array_values(array_filter(explode('.', $host), static fn ($p): bool => $p !== ''));
        $n = count($parts);
        if ($n <= 2) return $host;
        $last2     = $parts[$n - 2] . '.' . $parts[$n - 1];
        $twoLevel  = ['co.uk','org.uk','gov.uk','ac.uk','net.uk','com.au','net.au','org.au','co.nz','org.nz','com.br','com.mx','co.jp'];
        return (in_array($last2, $twoLevel, true) && $n >= 3) ? $parts[$n - 3] . '.' . $last2 : $last2;
    }
}

// ── Kubernetes : deployments + ingress ───────────────────────────────────────
$k8s_deployments_count      = 0;
$k8s_deployments_error_code = null;
$k8s_ingress_domains_count  = 0;
$k8s_ingress_error_code     = null;
$k8s_ingress_base_domains   = [];
$k8s_deployments_names      = [];

// ── Zabbix SLA ───────────────────────────────────────────────────────────────
$zabbixAvailability = zabbixApiGetAnnualAvailabilityDisplay(sessionUserArray());
$annual_availability_display = (string)($zabbixAvailability['display'] ?? '---');
$availability_error_code     = (isset($zabbixAvailability['error_code']) && $zabbixAvailability['error_code'] !== '')
    ? (string)$zabbixAvailability['error_code'] : null;

if ($k8s_namespace !== '') {
    $k8sClientPath = dirname(__DIR__) . '/data/KubernetesClient.php';
    if (!is_readable($k8sClientPath)) {
        $k8sClientPath = dirname(__DIR__) . '/KubernetesClient.php';
    }

    if (is_readable($k8sClientPath)) {
        require_once $k8sClientPath;

        try {
            $k8s = new KubernetesClient(null, null, null, 3);

            // Deployments
            try {
                $list  = $k8s->listDeployments($k8s_namespace);
                $items = is_array($list['items'] ?? null) ? $list['items'] : [];
                foreach ($items as $item) {
                    $depName = (string)($item['metadata']['name'] ?? '');
                    if ($depName !== '') $k8s_deployments_names[] = $depName;
                }
                sort($k8s_deployments_names, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_deployments_names = array_values(array_unique($k8s_deployments_names));
                $k8s_deployments_count = count($k8s_deployments_names);
            } catch (Throwable $e) {
                $k8s_deployments_count      = 0;
                $k8s_deployments_names      = [];
                $k8s_deployments_error_code = dashboardExtractErrorCode($e);
                error_log('[dashboard] K8s deployments: ' . $e->getMessage());
            }

            // Ingress
            try {
                $ns       = rawurlencode($k8s_namespace);
                $ingresses = null;
                try {
                    $ingresses = $k8s->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses?limit=200");
                } catch (Throwable $e) {
                    if (str_contains($e->getMessage(), 'HTTP 404')) {
                        $ingresses = $k8s->get("/apis/extensions/v1beta1/namespaces/{$ns}/ingresses?limit=200");
                    } else {
                        throw $e;
                    }
                }
                $hosts = [];
                foreach (($ingresses['items'] ?? []) as $ing) {
                    if (!is_array($ing)) continue;
                    foreach (($ing['spec']['rules'] ?? []) as $r) {
                        $h = (string)($r['host'] ?? '');
                        if ($h !== '') $hosts[] = $h;
                    }
                    foreach (($ing['spec']['tls'] ?? []) as $t) {
                        foreach (($t['hosts'] ?? []) as $h) {
                            $h = (string)$h;
                            if ($h !== '') $hosts[] = $h;
                        }
                    }
                }
                $baseDomains = [];
                foreach ($hosts as $h) {
                    $bd = dashboardBaseDomainFromHost($h);
                    if ($bd !== '') $baseDomains[$bd] = true;
                }
                $k8s_ingress_base_domains  = array_keys($baseDomains);
                sort($k8s_ingress_base_domains, SORT_NATURAL | SORT_FLAG_CASE);
                $k8s_ingress_domains_count = count($k8s_ingress_base_domains);
            } catch (Throwable $e) {
                $k8s_ingress_domains_count = 0;
                $k8s_ingress_base_domains  = [];
                $k8s_ingress_error_code    = dashboardExtractErrorCode($e);
                error_log('[dashboard] K8s ingress: ' . $e->getMessage());
            }

        } catch (Throwable $e) {
            // Échec de construction du client (token/CA manquants)
            $shared = dashboardExtractErrorCode($e);
            if ($k8s_deployments_error_code === null) $k8s_deployments_error_code = $shared;
            if ($k8s_ingress_error_code === null)     $k8s_ingress_error_code     = $shared;
            error_log('[dashboard] KubernetesClient init: ' . $e->getMessage());
        }
    }
}

// ── Stats de visites par deployment ──────────────────────────────────────────
// Source PRIMAIRE : l'API portail (pipeline n8n « data-portail », action
// "stats.dashboard"). Cohérent avec le reste du portail (UN SEUL webhook ;
// client_id injecté serveur, non falsifiable).
//
// Repli (best-effort) : anciens sidecars
//   <deployment>-stats.<namespace>.svc.cluster.local:9090/stats
// uniquement si l'API ne renvoie rien ET que STATS_SECRET est configuré.
//
// Quelle que soit la source, les données sont normalisées vers la même forme
// ({current_month_hits, previous_month_hits, by_month}) puis agrégées plus bas,
// de sorte que les cartes et le graphique restent inchangés.
$visit_stats_by_deployment = [];
$visitors_error_code       = null;
$current_month_hits        = 0;
$previous_month_hits       = 0;
$by_month_raw              = [];

if ($k8s_namespace !== '' && $k8s_deployments_names !== []) {
    // 1) Source primaire : API portail (n8n) — uniquement si le client est dispo.
    if (function_exists('portailFetchDashboardStats')) {
        try {
            $apiStats = portailFetchDashboardStats(sessionUserArray(), $k8s_deployments_names);
            $visit_stats_by_deployment = is_array($apiStats['by_deployment'] ?? null)
                ? $apiStats['by_deployment']
                : [];

            // Badge d'erreur sur la carte si n8n répond hors 2xx sans donnée.
            $apiStatus = (int)($apiStats['status'] ?? 0);
            if ($visit_stats_by_deployment === [] && $apiStatus !== 0 && ($apiStatus < 200 || $apiStatus >= 300)) {
                $visitors_error_code = (string)$apiStatus;
            }
        } catch (Throwable $e) {
            $visit_stats_by_deployment = [];
            $visitors_error_code       = dashboardExtractErrorCode($e);
            error_log('[dashboard] stats API: ' . $e->getMessage());
        }
    }

    // 2) Repli sidecar (best-effort) si l'API n'a rien renvoyé.
    if ($visit_stats_by_deployment === []) {
        $stats_secret = (string)(getenv('STATS_SECRET') ?: '');
        foreach ($k8s_deployments_names as $depName) {
            $stats_url = "http://{$depName}-stats.{$k8s_namespace}.svc.cluster.local:9090/stats";
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'header'  => $stats_secret !== '' ? "X-Stats-Secret: {$stats_secret}\r\n" : '',
                ],
            ]);
            $raw = @file_get_contents($stats_url, false, $ctx);
            if ($raw !== false) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    $visit_stats_by_deployment[$depName] = $decoded;
                }
            }
            // Sidecar absent → ignoré silencieusement (comportement voulu)
        }
    }

    // 3) Agrégats (cartes + tendance), source unifiée (API ou sidecar).
    foreach ($visit_stats_by_deployment as $decoded) {
        if (!is_array($decoded)) {
            continue;
        }
        $current_month_hits  += (int)($decoded['current_month_hits']  ?? 0);
        $previous_month_hits += (int)($decoded['previous_month_hits'] ?? 0);
        foreach (($decoded['by_month'] ?? []) as $month => $count) {
            $by_month_raw[$month] = ($by_month_raw[$month] ?? 0) + (int)$count;
        }
    }
}

// ── Graphique : 12 derniers mois ──────────────────────────────────────────────
$chart_month_labels = [];
$chart_month_keys   = [];
$monthNames = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
for ($i = 11; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
    $key = date('Y-m', $ts);
    $chart_month_keys[]   = $key;
    $chart_month_labels[] = $monthNames[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

$chart_datasets = [];
foreach ($visit_stats_by_deployment as $depName => $stats) {
    $series = [];
    foreach ($chart_month_keys as $key) {
        $series[] = (int)($stats['by_month'][$key] ?? 0);
    }
    $chart_datasets[$depName] = $series;
}

// ══════════════════════════════════════════════════════════════════════════════
// Amélioration : évolution mensuelle en % — calcul centralisé côté PHP
//   Avant : calcul inline dans le HTML avec risque de division par zéro non capturé
// ══════════════════════════════════════════════════════════════════════════════
$hits_pct_vs_prev = null;
if ($previous_month_hits > 0 && $current_month_hits > 0) {
    $hits_pct_vs_prev = (int)round(
        (($current_month_hits - $previous_month_hits) / $previous_month_hits) * 100
    );
}

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="theme-color" content="#ffffff"/>

  <!-- ══════════════════════════════════════════════════════════════════════
       Amélioration : titre contextualisé avec le nom de l'utilisateur
       Avant : <title><?= t('Dashboard - GNL Solution') ?></title>
  ══════════════════════════════════════════════════════════════════════ -->
  <title><?= t('Dashboard') ?><?= $name !== '' ? ' · ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '' ?> — GNL Solution</title>

  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>

  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    /* Layout */
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media(max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }

    /* Animations */
    @keyframes fadeUp{from{opacity:0;transform:translate3d(0,10px,0);}to{opacity:1;transform:translate3d(0,0,0);}}
    .chart-reveal{opacity:0;transform:translate3d(0,10px,0);}
    .chart-reveal.is-visible{animation:fadeUp .6s ease-out both;}
    .metric-card{transition:transform .2s ease,box-shadow .2s ease;}
    .metric-card:hover{transform:translate3d(0,-2px,0);}
    @media(prefers-reduced-motion:reduce){
      .chart-reveal,.chart-reveal.is-visible{opacity:1;transform:none;animation:none;}
      .metric-card{transition:none;}
    }

    /* Collapsibles */
    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease,opacity 220ms ease;will-change:height,opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}
    @media(prefers-reduced-motion:reduce){
      .collapsible-content,.collapsible-trigger .collapsible-chevron{transition:none !important;}
    }

    /* ══════════════════════════════════════════════════════════════════════
       Amélioration : cards métriques — indicateur de tendance visuel
    ══════════════════════════════════════════════════════════════════════ */
    .metric-trend-up   { color: #16a34a; }
    .metric-trend-down { color: #dc2626; }
    .metric-trend-neutral { color: #64748b; }

  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">

        <!-- ════════════════════════════════════════════════════════════════
             MÉTRIQUES (4 cards)
        ════════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">

          <!-- Requêtes ce mois-ci -->
          <div data-slot="card" class="metric-card bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div class="px-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg shrink-0">
                    <p class="text-base font-bold tracking-tight tabular-nums">
                      <?= $current_month_hits > 0
                          ? number_format($current_month_hits, 0, ',', ' ')
                          : '---' ?>
                    </p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <!-- ══════════════════════════════════════════════════
                         Amélioration : faute de frappe corrigée
                         Avant : "Requettes" → "Requêtes"
                    ══════════════════════════════════════════════════ -->
                    <p class="font-bold tracking-tight text-sm"><?= t('Requêtes ce mois-ci') ?></p>
                    <?php if ($hits_pct_vs_prev !== null): ?>
                      <p class="text-sm <?= $hits_pct_vs_prev >= 0 ? 'metric-trend-up' : 'metric-trend-down' ?>">
                        <?= ($hits_pct_vs_prev >= 0 ? '↑ +' : '↓ ') . $hits_pct_vs_prev ?><?= t('% vs mois dernier') ?>
                      </p>
                    <?php else: ?>
                      <p class="text-sm text-muted-foreground"><?= t('toutes applications') ?></p>
                    <?php endif; ?>
                  </div>
                </div>
                <?= dashboardRenderWidgetErrorBadge($visitors_error_code) ?>
              </div>
            </div>
          </div>

          <!-- Nombre d'applications -->
          <div data-slot="card" class="metric-card bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div class="px-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg shrink-0">
                    <p class="text-base font-bold tracking-tight tabular-nums"><?= (int)$k8s_deployments_count ?></p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <!-- Faute corrigée : "application" → "applications" -->
                    <p class="font-bold tracking-tight text-sm"><?= t('Applications') ?></p>
                    <p class="text-sm text-muted-foreground">
                      <?= $k8s_namespace !== ''
                          ? 'ns : <span class="font-mono text-xs">' . htmlspecialchars($k8s_namespace, ENT_QUOTES, 'UTF-8') . '</span>'
                          : t('namespace non configuré') ?>
                    </p>
                  </div>
                </div>
                <?= dashboardRenderWidgetErrorBadge($k8s_deployments_error_code) ?>
              </div>
            </div>
          </div>

          <!-- Domaines -->
          <div data-slot="card" class="metric-card bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div class="px-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-16 items-center justify-center rounded-lg shrink-0">
                    <p class="text-base font-bold tracking-tight tabular-nums"><?= (int)$k8s_ingress_domains_count ?></p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <p class="font-bold tracking-tight text-sm"><?= t('Domaines') ?></p>
                    <!-- ══════════════════════════════════════════════════
                         Amélioration : afficher les domaines réels dans le sous-titre
                         Avant : texte figé ".fr, .com, .org,..."
                    ══════════════════════════════════════════════════ -->
                    <p class="text-sm text-muted-foreground truncate" title="<?= htmlspecialchars(implode(', ', $k8s_ingress_base_domains), ENT_QUOTES, 'UTF-8') ?>">
                      <?php if ($k8s_ingress_base_domains !== []): ?>
                        <?= htmlspecialchars(implode(', ', array_slice($k8s_ingress_base_domains, 0, 3)), ENT_QUOTES, 'UTF-8') ?>
                        <?php if (count($k8s_ingress_base_domains) > 3): ?>
                          <span class="text-xs">+<?= count($k8s_ingress_base_domains) - 3 ?></span>
                        <?php endif ?>
                      <?php else: ?>
                        .fr, .com, .org…
                      <?php endif ?>
                    </p>
                  </div>
                </div>
                <?= dashboardRenderWidgetErrorBadge($k8s_ingress_error_code) ?>
              </div>
            </div>
          </div>

          <!-- Disponibilité annuelle -->
          <div data-slot="card" class="metric-card bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-6 shadow-sm transition-shadow hover:shadow-lg">
            <div class="px-6">
              <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                  <div class="bg-muted flex h-10 w-20 items-center justify-center rounded-lg shrink-0">
                    <p class="text-base font-bold tracking-tight tabular-nums">
                      <?= htmlspecialchars($annual_availability_display, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                  </div>
                  <div class="min-w-0 space-y-1">
                    <p class="font-bold tracking-tight text-sm"><?= t('Disponibilité annuelle') ?></p>
                    <!-- Faute corrigée : "tout services" → "tous services" -->
                    <p class="text-sm text-muted-foreground"><?= t('tous services · année en cours') ?></p>
                  </div>
                </div>
                <?= dashboardRenderWidgetErrorBadge($availability_error_code) ?>
              </div>
            </div>
          </div>

        </div>

        <!-- ════════════════════════════════════════════════════════════════
             GRAPHIQUE VISITEURS PAR APPLICATION
        ════════════════════════════════════════════════════════════════ -->
        <div class="mt-6 chart-reveal" data-chart="visitors">
          <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-6 rounded-xl border py-3 shadow-sm">
            <div class="flex flex-row items-center justify-between space-y-0 px-6 pb-3 border-b">
              <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" class="text-blue-600" aria-hidden="true">
                  <path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>
                </svg>
                <!-- Faute corrigée : "Requettes" → "Requêtes" -->
                <h3 class="text-sm font-bold"><?= t('Requêtes par application') ?></h3>
              </div>
              <div class="flex items-center gap-3">
                <span class="text-xs text-muted-foreground"><?= t('12 derniers mois') ?></span>
                <?php if (!empty($visit_stats_by_deployment)): ?>
                  <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium border-transparent bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400">
                    <?= t('Données réelles') ?>
                  </span>
                <?php endif ?>
              </div>
            </div>

            <div class="px-6 pb-4">
              <div class="h-[320px]">
                <canvas id="visitorsChart" aria-label="<?= t('Graphique des requêtes par application') ?>" role="img"></canvas>
              </div>
              <div id="visitorsChartEmpty"
                   class="mt-4 hidden rounded-lg border border-dashed px-4 py-6 text-sm text-muted-foreground">
                <?= t('Aucune donnée de requêtes disponible pour le moment.') ?>
              </div>
              <div id="visitorsChartLegend" class="mt-4 flex flex-wrap items-center gap-4 text-sm text-muted-foreground"></div>
            </div>
          </div>
        </div>


      </div>
    </main>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════════
       GRAPHIQUE — données injectées proprement par PHP
  ════════════════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    const chartLabels   = <?= json_encode($chart_month_labels, JSON_UNESCAPED_UNICODE) ?>;
    const chartDatasets = <?= json_encode($chart_datasets,     JSON_UNESCAPED_UNICODE) ?>;

    function prefersReducedMotion() {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function palette(index) {
      const colors = [
        [34,197,94],[59,130,246],[168,85,247],[249,115,22],[236,72,153],
        [20,184,166],[245,158,11],[99,102,241],[132,204,22],[239,68,68],
        [6,182,212],[217,70,239],
      ];
      return colors[index % colors.length];
    }

    function rgba(rgb, alpha) {
      return `rgba(${rgb[0]},${rgb[1]},${rgb[2]},${alpha})`;
    }

    function toggleEmptyState(hasData) {
      const empty  = document.getElementById('visitorsChartEmpty');
      const legend = document.getElementById('visitorsChartLegend');
      const canvas = document.getElementById('visitorsChart');
      if (empty)  empty.classList.toggle('hidden', hasData);
      if (legend) legend.classList.toggle('hidden', !hasData);
      if (canvas?.parentElement) canvas.parentElement.classList.toggle('hidden', !hasData);
    }

    function renderLegend(names) {
      const legend = document.getElementById('visitorsChartLegend');
      if (!legend) return;
      legend.innerHTML = '';
      names.forEach((name, i) => {
        const rgb  = palette(i);
        const item = document.createElement('div');
        item.className = 'flex items-center gap-2';
        const dot  = document.createElement('span');
        dot.className = 'h-2.5 w-2.5 rounded-full shrink-0';
        dot.style.backgroundColor = rgba(rgb, 1);
        const label = document.createElement('span');
        label.textContent = name;
        item.appendChild(dot);
        item.appendChild(label);
        legend.appendChild(item);
      });
    }

    function buildVisitorsChart() {
      const canvas = document.getElementById('visitorsChart');
      if (!canvas || !window.Chart) return null;

      const names = Object.keys(chartDatasets);

      if (names.length === 0) { toggleEmptyState(false); return null; }

      toggleEmptyState(true);
      renderLegend(names);

      const ctx         = canvas.getContext('2d');
      const h           = 320;
      const shouldFill  = names.length <= 4;
      const fillOpacity = names.length <= 4 ? 0.18 : 0.08;
      const labels      = chartLabels;

      const datasets = names.map((name, index) => {
        const rgb      = palette(index);
        const gradient = ctx.createLinearGradient(0, 0, 0, h);
        gradient.addColorStop(0, rgba(rgb, fillOpacity));
        gradient.addColorStop(1, rgba(rgb, 0));
        const data = chartDatasets[name];
        return {
          label: name,
          data,
          borderColor:               rgba(rgb, 1),
          backgroundColor:           gradient,
          pointBackgroundColor:      rgba(rgb, 1),
          pointBorderColor:          rgba(rgb, 1),
          pointHoverBackgroundColor: rgba(rgb, 1),
          pointHoverBorderColor:     rgba(rgb, 1),
          fill: shouldFill,
        };
      });

      return new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: false },
            tooltip: {
              padding: 10,
              displayColors: true,
              callbacks: {
                label: (c) => ' ' + c.dataset.label + ': ' + c.parsed.y.toLocaleString('fr-FR'),
              },
            },
          },
          elements: {
            point: { radius: 0, hoverRadius: 4, hitRadius: 12 },
            line:  { tension: 0.35, borderWidth: 2 },
          },
          scales: {
            x: {
              grid:  { display: false },
              ticks: { color: 'rgba(148,163,184,0.9)', maxRotation: 45 },
            },
            y: {
              grid:  { color: 'rgba(148,163,184,0.15)' },
              beginAtZero: true,
              ticks: {
                color: 'rgba(148,163,184,0.9)',
                callback: (v) => v.toLocaleString('fr-FR'),
              },
            },
          },
          animation: prefersReducedMotion() ? false : { duration: 900, easing: 'easeOutQuart' },
        },
      });
    }

    function init() {
      const section = document.querySelector('[data-chart="visitors"]');
      if (!section) return;
      let chartInstance = null;
      const run = () => {
        section.classList.add('is-visible');
        if (!chartInstance) chartInstance = buildVisitorsChart();
      };
      if ('IntersectionObserver' in window && !prefersReducedMotion()) {
        const io = new IntersectionObserver(
          (entries) => { if (entries.some(e => e.isIntersecting)) { run(); io.disconnect(); } },
          { threshold: 0.2 }
        );
        io.observe(section);
      } else {
        run();
      }
    }

    document.addEventListener('DOMContentLoaded', init);
  })();
  </script>

  <!-- ════════════════════════════════════════════════════════════════════════
       Amélioration #6 — collapsible JS (même logique que les autres pages)
       À terme : extraire dans assets/js/collapsible.js et charger avec defer
  ════════════════════════════════════════════════════════════════════════ -->
  <script>
  (function () {
    function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    ready(function () {
      document.querySelectorAll('[data-slot="collapsible-trigger"]').forEach(function (btn) {
        btn.classList.add('collapsible-trigger');
        var targetId = btn.getAttribute('aria-controls');
        var content  = targetId ? document.getElementById(targetId) : null;
        if (!content) {
          var p = btn.closest('[data-slot="collapsible"]');
          if (p) content = p.querySelector('[data-slot="collapsible-content"]');
        }
        if (!content) return;
        content.classList.add('collapsible-content');
        var chev = btn.querySelector('.lucide-chevron-right');
        if (chev) chev.classList.add('collapsible-chevron');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) { content.hidden = false; content.classList.add('is-open'); content.style.height = 'auto'; }
        else          { content.hidden = true;  content.classList.remove('is-open'); content.style.height = '0px'; }
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var isOpen = btn.getAttribute('aria-expanded') === 'true';
          if (!isOpen) {
            btn.setAttribute('aria-expanded', 'true'); btn.setAttribute('data-state', 'open');
            content.hidden = false; content.classList.add('is-open'); content.setAttribute('data-state', 'open');
            content.style.height = '0px';
            requestAnimationFrame(function () { content.style.height = content.scrollHeight + 'px'; });
            content.addEventListener('transitionend', function onEnd(ev) {
              if (ev.propertyName !== 'height') return;
              content.style.height = 'auto';
              content.removeEventListener('transitionend', onEnd);
            });
          } else {
            btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('data-state', 'closed');
            content.classList.remove('is-open'); content.setAttribute('data-state', 'closed');
            content.style.height = content.scrollHeight + 'px';
            requestAnimationFrame(function () { content.style.height = '0px'; });
            content.addEventListener('transitionend', function onEndClose(ev) {
              if (ev.propertyName !== 'height') return;
              content.hidden = true;
              content.removeEventListener('transitionend', onEndClose);
            });
          }
        }, { passive: false });
      });
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = '../data/k8s_api.php';
    window.K8S_UI_BASE = './pages/';
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>