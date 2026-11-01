<?php
/* =====================================================================
   include/staff_shell.php — coque commune des pages du Portail Gestion
   ---------------------------------------------------------------------
   gnl_staff_open($title, $opts) : contrôle de session, <head>, header,
   barre latérale, ouverture de <main class="ec-page">.
   gnl_staff_close($scripts)     : fermeture + scripts communs.
   Utilisée par les pages créées d'après la maquette (dashboard, services,
   services-gnl, serveurs-dns, boutique, documentation). Les données
   passent toutes par data/portail_api.php → webhook n8n.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once __DIR__ . '/../config_loader.php';
require_once __DIR__ . '/account_sessions.php';
require_once __DIR__ . '/page_head.php';

if (isset($pdo) && accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}
if (isset($pdo)) {
    accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);
}

// Jeton CSRF (même clé que header.php et data/portail_api.php → csrf_check()).
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * @param array{search?:string,searchId?:string,css?:string} $opts
 */
function gnl_staff_open(string $title, array $opts = []): void
{
    global $showSearch, $searchInputId, $searchPlaceholder;
    $showSearch        = isset($opts['search']);
    $searchInputId     = (string) ($opts['searchId'] ?? 'pageSearchInput');
    $searchPlaceholder = (string) ($opts['search'] ?? '');
    $root = dirname(__DIR__);
    $v = static fn (string $rel): int => (int) @filemtime($root . $rel);
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= h($title) ?> — Portail Gestion GNL</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="theme-color" content="#141a33"/>
  <link rel="stylesheet" href="/assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN"/>
  <link rel="stylesheet" href="/assets/styles/gnl-portail.css?v=<?= $v('/assets/styles/gnl-portail.css') ?>"/>
  <link rel="stylesheet" href="/assets/styles/gnl-gestion.css?v=<?= $v('/assets/styles/gnl-gestion.css') ?>"/>
  <link rel="preload" href="/assets/gnl/fonts/Manrope-latin.woff2" as="font" type="font/woff2" crossorigin/>
  <link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32"/>
  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media (max-width:1024px){.dashboard-layout{flex-direction:column;}.dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}}
    <?= $opts['css'] ?? '' ?>
  </style>
  <script>
    window.GNL_CSRF = <?= json_encode((string) $_SESSION['csrf'], JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="/assets/js/gnl-staff.js?v=<?= $v('/assets/js/gnl-staff.js') ?>"></script>
  <?php require_once __DIR__ . '/org_filter.php'; ?>
</head>
<body class="bg-background text-foreground">
  <?php include __DIR__ . '/header.php'; ?>
  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include __DIR__ . '/menu.php'; ?>
    </aside>
    <main class="dashboard-main">
      <div class="app-shell-offset-min-height ec-page gs-page">
<?php
}

/** @param string[] $scripts chemins /assets/js/… propres à la page */
function gnl_staff_close(array $scripts = []): void
{
    $root = dirname(__DIR__);
    echo "      </div>\n    </main>\n  </div>\n";
    include __DIR__ . '/maintenance_modal.php';
    echo '  <div class="gs-toast" id="gsToast" role="status" aria-live="polite" hidden></div>' . "\n";
    foreach ($scripts as $src) {
        echo '  <script src="' . h($src) . '?v=' . (int) @filemtime($root . $src) . '"></script>' . "\n";
    }
    echo '  <script src="/assets/js/collapsible.js"></script>' . "\n";
    echo "  <script>window.K8S_API_URL = '../data/k8s_api.php'; window.K8S_UI_BASE = './pages/';</script>\n";
    echo '  <script src="/assets/js/services_menu.js" defer></script>' . "\n";
    echo "</body>\n</html>\n";
}
