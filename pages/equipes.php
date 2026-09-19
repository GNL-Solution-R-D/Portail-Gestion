<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// user_account_sessions a une clé INT : on utilise 'account_id' quand il est
// présent (posé par gnl_apply_identity()), sinon 'id' — qui reste l'entier
// dérivé de sha1(sub) construit par keycloakBuildSessionUser().
$equipesAccountId = (int) ($_SESSION['user']['account_id'] ?? 0);
if ($equipesAccountId <= 0 && ctype_digit((string) ($_SESSION['user']['id'] ?? ''))) {
    $equipesAccountId = (int) $_SESSION['user']['id'];
}

if ($equipesAccountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $equipesAccountId)) {
        accountSessionsDestroyPhpSession();
        header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
        exit();
    }

    accountSessionsTouchCurrent($pdo, $equipesAccountId);
}

// Jeton CSRF (même clé que header.php et que data/portail_api.php).
if (empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf'] = bin2hex((string) mt_rand());
    }
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Barre de recherche du header (include/header.php) : ACTIVÉE pour cette page.
// Elle filtre la liste des membres, désormais alimentée par les COMPTES du
// realm Keycloak (data/portail_api.php ?action=team.list →
// include/keycloak_directory.php → Admin REST). Keycloak est la source de
// vérité : la page est en LECTURE SEULE, aucune écriture n'est proposée ici.
$showSearch        = true;
$searchInputId     = 'membersSearchInput';
$searchPlaceholder = t('Rechercher un membre…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Équipes - GNL Solution') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <style>
    .dashboard-layout {display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar {flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main {flex:1 1 auto;min-width:0;}
    .table-wrap {overflow-x:auto;}
    .members-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);}
    .members-state--error td{color:#b91c1c;}

    .collapsible-content {overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open {opacity:1;}
    .collapsible-trigger .collapsible-chevron {transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {transform:rotate(90deg);}
    @media (prefers-reduced-motion: reduce) {.collapsible-content,.collapsible-trigger .collapsible-chevron {transition:none !important;}}

    @media (max-width: 1024px) {
      .dashboard-layout { flex-direction: column; }
      .dashboard-sidebar {width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main { padding: 1rem; }
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>
  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>
    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">
        <div class="bg-background text-card-foreground flex flex-col gap-3 rounded-xl border py-6 shadow-sm">
          <div class="px-6">
            <h1 class="text-lg font-semibold"><?= t('Membres de la structure') ?></h1>
            <p class="text-sm text-muted-foreground">
              <?= t('Membres rattachés à votre structure') ?><span id="structureName"></span>.
            </p>
          </div>
          <div class="px-6 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
            <span id="membersCount" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                  data-suffix="<?php echo h(t('membre(s)')); ?>">…</span>
            <span id="editModeBadge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"></span>
          </div>
        </div>

        <div id="teamAlerts" class="space-y-3"></div>

        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold"><?= t('Liste des membres') ?></h2>
              <p class="text-sm text-muted-foreground"><?= t('Annuaire Keycloak') ?></p>
            </div>
          </div>

          <div class="table-wrap" data-slot="card-content">
            <table class="w-full min-w-max table-auto text-left">
              <thead>
                <tr>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Membre') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Fonction') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Statut') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Permission') ?></p></th>
                </tr>
              </thead>
              <tbody id="membersTableBody">
                <tr class="members-state">
                  <td colspan="4"><?= t('Chargement des membres…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
    (function () {
      function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
      ready(function () {
        var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
        triggers.forEach(function (btn) {
          btn.classList.add('collapsible-trigger');
          var targetId = btn.getAttribute('aria-controls');
          var content = targetId ? document.getElementById(targetId) : null;
          if (!content) {
            var parent = btn.closest('[data-slot="collapsible"]');
            if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
          }
          if (!content) return;
          content.classList.add('collapsible-content');
          var chev = btn.querySelector('.lucide-chevron-right');
          if (chev) chev.classList.add('collapsible-chevron');

          var expanded = btn.getAttribute('aria-expanded') === 'true';
          if (expanded) { content.hidden = false; content.classList.add('is-open'); content.style.height = 'auto'; }
          else { content.hidden = true; content.classList.remove('is-open'); content.style.height = '0px'; }

          btn.addEventListener('click', function (e) {
            e.preventDefault();
            var isOpen = btn.getAttribute('aria-expanded') === 'true';
            if (!isOpen) {
              btn.setAttribute('aria-expanded', 'true');
              content.hidden = false; content.classList.add('is-open'); content.style.height = '0px';
              var h = content.scrollHeight;
              requestAnimationFrame(function () { content.style.height = h + 'px'; });
              content.addEventListener('transitionend', function onEnd(ev) {
                if (ev.propertyName !== 'height') return;
                content.style.height = 'auto'; content.removeEventListener('transitionend', onEnd);
              });
            } else {
              btn.setAttribute('aria-expanded', 'false');
              content.classList.remove('is-open');
              var current = content.scrollHeight; content.style.height = current + 'px';
              requestAnimationFrame(function () { content.style.height = '0px'; });
              content.addEventListener('transitionend', function onEndClose(ev) {
                if (ev.propertyName !== 'height') return;
                content.hidden = true; content.removeEventListener('transitionend', onEndClose);
              });
            }
          }, { passive: false });
        });
      });
    })();
  </script>

  <!-- Membres : comptes du realm Keycloak via data/portail_api.php ?action=team.list
       (include/keycloak_directory.php → Admin REST). Lecture seule.
       La recherche du header filtre la liste côté client. -->
  <script>
    window.TEAM_API_URL = window.TEAM_API_URL || "../data/portail_api.php";
    window.TEAM_I18N = {
      loading:   <?= json_encode(t('Chargement des membres…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:     <?= json_encode(t('Aucun membre trouvé pour cette structure.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults: <?= json_encode(t('Aucun membre ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:     <?= json_encode(t('Impossible de charger les membres.'), JSON_UNESCAPED_UNICODE) ?>,
      readOnly:  <?= json_encode(t('Lecture seule'), JSON_UNESCAPED_UNICODE) ?>,
      truncated: <?= json_encode(t('Liste tronquée : seuls les premiers membres sont affichés.'), JSON_UNESCAPED_UNICODE) ?>,
      noFunction:<?= json_encode(t('Aucune fonction définie'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    var I18N = window.TEAM_I18N || {};
    var API  = window.TEAM_API_URL || "../data/portail_api.php";

    function norm(s){ return String(s==null?'':s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,''); }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

    ready(function () {
      var input    = document.getElementById('membersSearchInput');
      var tbody    = document.getElementById('membersTableBody');
      var counter  = document.getElementById('membersCount');
      var editBadge= document.getElementById('editModeBadge');
      var structEl = document.getElementById('structureName');
      var alerts   = document.getElementById('teamAlerts');
      if (!tbody) return;

      var COLS  = 4;
      var state = { members: [], structure: '', truncated: false };
      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function setCounter(n){ if (counter) counter.textContent = (n==null?'…':n) + (suffix ? ' ' + suffix : ''); }

      function showAlert(message, isError){
        if (!alerts) return;
        alerts.innerHTML = '';
        if (!message) return;
        var div = document.createElement('div');
        div.className = 'rounded-xl border px-6 py-4 text-sm ' + (isError
          ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-300'
          : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/30 dark:bg-amber-950/30 dark:text-amber-300');
        div.textContent = message;
        alerts.appendChild(div);
      }

      function stateRow(text, isError){
        return '<tr class="members-state' + (isError ? ' members-state--error' : '') + '"><td colspan="' + COLS + '">' + esc(text) + '</td></tr>';
      }

      function rowHtml(m){
        var structure = m.structure || state.structure || '—';
        var hay = [m.name, m.secondary, m.function, m.status_label, m.permission, structure].join(' ').toLowerCase();

        return '<tr data-search="' + esc(hay) + '">' +
          '<td class="border-surface border-b p-4 align-top">' +
            '<div class="flex items-center gap-3">' +
              '<span class="relative flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted text-xs font-semibold">' + esc(m.initials) + '</span>' +
              '<div><p class="text-default block text-sm font-semibold">' + esc(m.name) + '</p>' +
              '<p class="text-foreground block text-sm">' + esc(m.secondary) + '</p></div>' +
            '</div>' +
          '</td>' +
          '<td class="border-surface border-b p-4 align-top"><div>' +
            '<p class="text-default block text-sm font-semibold">' + esc(structure) + '</p>' +
            '<p class="text-foreground block text-sm">' + esc(m.function || I18N.noFunction || '') + '</p>' +
          '</div></td>' +
          '<td class="border-surface border-b p-4 align-top"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 ' + esc(m.status_class) + '">' + esc(m.status_label) + '</span></td>' +
          '<td class="border-surface border-b p-4 align-top"><div><p class="text-foreground block text-sm">' + esc(m.permission) + '</p></div></td>' +
        '</tr>';
      }

      function dataRows(){ return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]')); }

      function applyFilter(){
        var rows = dataRows();
        if (!rows.length) return;
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var visible = 0;
        rows.forEach(function (row){
          var hay = norm(row.getAttribute('data-search') || '');
          var match = tokens.every(function (t){ return hay.indexOf(t) !== -1; });
          row.hidden = !match;
          if (match) visible++;
        });
        var noRes = document.getElementById('membersNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows(){
        var list = state.members;
        if (!list.length) {
          tbody.innerHTML = stateRow(I18N.empty || 'Aucun membre.', false);
          setCounter(0);
          return;
        }
        tbody.innerHTML = list.map(rowHtml).join('') +
          '<tr id="membersNoResults" class="members-state" hidden><td colspan="' + COLS + '">' + esc(I18N.noResults || '') + '</td></tr>';
        setCounter(list.length);
        applyFilter();
      }

      function updateHeader(){
        if (structEl) structEl.textContent = state.structure ? (' : ' + state.structure) : '';

        // Keycloak est la source de vérité : la page reste en lecture seule.
        if (editBadge) {
          editBadge.textContent = I18N.readOnly || 'Lecture seule';
          editBadge.className = 'inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
        }
      }

      function load(){
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        fetch(API + '?action=team.list', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
        .then(function (r){
          var data = r.data;
          if (!r.ok || !data || !data.ok) {
            var msg = (data && data.error) ? data.error : (I18N.error || 'Erreur.');
            tbody.innerHTML = stateRow(msg, true);
            setCounter(null);
            updateHeader();
            return;
          }
          state.members   = Array.isArray(data.members) ? data.members : [];
          state.structure = data.structure || '';
          state.truncated = !!data.truncated;
          updateHeader();
          renderRows();
          showAlert(state.truncated ? (I18N.truncated || '') : '', false);
        })
        .catch(function (){ tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les membres.', true); setCounter(null); });
      }

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter);
      }

      load();
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>
