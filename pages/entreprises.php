<?php
/* =====================================================================
   GNL Solution — ENTREPRISES  (/entreprises)
   ---------------------------------------------------------------------
   Liste les ORGANISATIONS Keycloak de l'ESPACE CLIENT, lues via l'Admin
   REST API (data/portail_api.php ?action=org.list →
   include/keycloak_esp_client.php). Même principe que /equipes, mais sur
   un AUTRE realm, avec le client KEYCLOAK_ESP-CLI_*.

   Les membres d'une entreprise ne sont PAS chargés au démarrage : un
   appel par organisation coûterait N requêtes Admin REST au chargement.
   On déplie une ligne → on charge ses membres une seule fois
   (?action=org.members&org_id=…), puis on garde le résultat en mémoire.

   LECTURE SEULE : Keycloak est la source de vérité, la page ne propose
   aucune écriture.
   ===================================================================== */

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// user_account_sessions a une clé INT : 'account_id' s'il est posé, sinon
// 'id' — l'entier dérivé de sha1(sub) construit par keycloakBuildSessionUser().
$entreprisesAccountId = (int) ($_SESSION['user']['account_id'] ?? 0);
if ($entreprisesAccountId <= 0 && ctype_digit((string) ($_SESSION['user']['id'] ?? ''))) {
    $entreprisesAccountId = (int) $_SESSION['user']['id'];
}

if ($entreprisesAccountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $entreprisesAccountId)) {
        accountSessionsDestroyPhpSession();
        header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
        exit();
    }

    accountSessionsTouchCurrent($pdo, $entreprisesAccountId);
}

// Jeton CSRF (même clé que header.php et que data/portail_api.php).
if (empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf'] = bin2hex((string) mt_rand());
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Barre de recherche du header : filtre la liste des entreprises côté client.
$showSearch        = true;
$searchInputId     = 'orgsSearchInput';
$searchPlaceholder = t('Rechercher une entreprise…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Entreprises - GNL Solution') ?></title>
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
    .orgs-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);}
    .orgs-state--error td{color:#b91c1c;}

    .org-toggle{display:inline-flex;align-items:center;gap:.4rem;border:none;background:none;padding:0;font:inherit;color:inherit;cursor:pointer;text-align:left;}
    .org-toggle .chev{transition:transform 180ms ease;flex:none;}
    .org-toggle[aria-expanded="true"] .chev{transform:rotate(90deg);}
    @media (prefers-reduced-motion: reduce){ .org-toggle .chev{transition:none;} }

    .org-members{background:color-mix(in srgb, currentColor 4%, transparent);}
    .org-members td{padding:0 !important;}
    .org-members-inner{padding:1rem 1.25rem 1.25rem 3.25rem;}
    .member-line{display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid color-mix(in srgb, currentColor 10%, transparent);}
    .member-line:last-child{border-bottom:none;}
    .member-av{display:grid;place-items:center;width:2rem;height:2rem;border-radius:999px;background:color-mix(in srgb, currentColor 10%, transparent);font-size:.72rem;font-weight:600;flex:none;}
    .member-main{min-width:0;flex:1 1 auto;}
    .member-main b{display:block;font-size:.875rem;font-weight:600;}
    .member-main span{display:block;font-size:.8125rem;color:var(--muted-foreground, #64748b);}
    .member-meta{font-size:.8125rem;color:var(--muted-foreground, #64748b);text-align:right;flex:none;}

    .org-links{display:flex;align-items:center;gap:.35rem;}
    .org-link{display:grid;place-items:center;width:2rem;height:2rem;border-radius:.5rem;border:1px solid rgba(148,163,184,.35);color:inherit;opacity:.7;transition:opacity 120ms ease, background-color 120ms ease;}
    .org-link:hover{opacity:1;background:color-mix(in srgb, currentColor 8%, transparent);}
    @media (prefers-reduced-motion: reduce){ .org-link{transition:none;} }

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
            <h1 class="text-lg font-semibold"><?= t('Entreprises') ?></h1>
            <p class="text-sm text-muted-foreground">
              <?= t('Organisations enregistrées dans l’espace client.') ?>
            </p>
          </div>
          <div class="px-6 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
            <span id="orgsCount" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                  data-suffix="<?php echo h(t('entreprise(s)')); ?>">…</span>
            <span id="readOnlyBadge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"></span>
            <span id="realmBadge" class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300" hidden></span>
          </div>
        </div>

        <div id="orgsAlerts" class="space-y-3"></div>

        <section class="bg-background text-card-foreground rounded-xl border py-6 shadow-sm">
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold"><?= t('Liste des entreprises') ?></h2>
              <p class="text-sm text-muted-foreground"><?= t('Organisations Keycloak de l’espace client') ?></p>
            </div>
          </div>

          <div class="table-wrap" data-slot="card-content">
            <table class="w-full min-w-max table-auto text-left">
              <thead>
                <tr>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Entreprise') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Identifiants') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Localisation') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Domaines') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Statut') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Raccourcis') ?></p></th>
                </tr>
              </thead>
              <tbody id="orgsTableBody">
                <tr class="orgs-state">
                  <td colspan="6"><?= t('Chargement des entreprises…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <!-- Entreprises : organisations Keycloak de l'espace client via
       data/portail_api.php ?action=org.list (include/keycloak_esp_client.php
       → Admin REST du realm ESP-CLI). Lecture seule.
       Les membres sont chargés à la demande (?action=org.members). -->
  <script>
    window.ORG_API_URL = window.ORG_API_URL || "../data/portail_api.php";
    window.ORG_I18N = {
      loading:     <?= json_encode(t('Chargement des entreprises…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:       <?= json_encode(t('Aucune entreprise enregistrée.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults:   <?= json_encode(t('Aucune entreprise ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:       <?= json_encode(t('Impossible de charger les entreprises.'), JSON_UNESCAPED_UNICODE) ?>,
      readOnly:    <?= json_encode(t('Lecture seule'), JSON_UNESCAPED_UNICODE) ?>,
      truncated:   <?= json_encode(t('Liste tronquée : seules les premières entreprises sont affichées.'), JSON_UNESCAPED_UNICODE) ?>,
      active:      <?= json_encode(t('Activée'), JSON_UNESCAPED_UNICODE) ?>,
      inactive:    <?= json_encode(t('Désactivée'), JSON_UNESCAPED_UNICODE) ?>,
      noDomain:    <?= json_encode(t('Aucun domaine'), JSON_UNESCAPED_UNICODE) ?>,
      membersLoad: <?= json_encode(t('Chargement des membres…'), JSON_UNESCAPED_UNICODE) ?>,
      membersNone: <?= json_encode(t('Aucun membre rattaché à cette entreprise.'), JSON_UNESCAPED_UNICODE) ?>,
      membersErr:  <?= json_encode(t('Impossible de charger les membres.'), JSON_UNESCAPED_UNICODE) ?>,
      membersMore: <?= json_encode(t('Liste tronquée : seuls les premiers membres sont affichés.'), JSON_UNESCAPED_UNICODE) ?>,
      noFunction:  <?= json_encode(t('Aucune fonction définie'), JSON_UNESCAPED_UNICODE) ?>,
      goInvoices:  <?= json_encode(t('Factures de cette entreprise'), JSON_UNESCAPED_UNICODE) ?>,
      goOrders:    <?= json_encode(t('Commandes de cette entreprise'), JSON_UNESCAPED_UNICODE) ?>,
      goSubs:      <?= json_encode(t('Abonnements de cette entreprise'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    var I18N = window.ORG_I18N || {};
    var API  = window.ORG_API_URL || "../data/portail_api.php";

    function norm(s){ return String(s==null?'':s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function sel(id){ return (window.CSS && CSS.escape) ? CSS.escape(id) : String(id).replace(/["\\]/g, '\\$&'); }

    var BADGE_OK  = 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    var BADGE_OFF = 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    var COLS = 6;

    ready(function () {
      var input    = document.getElementById('orgsSearchInput');
      var tbody    = document.getElementById('orgsTableBody');
      var counter  = document.getElementById('orgsCount');
      var roBadge  = document.getElementById('readOnlyBadge');
      var realmEl  = document.getElementById('realmBadge');
      var alerts   = document.getElementById('orgsAlerts');
      if (!tbody) return;

      // membersCache : org_id -> 'loading' | html. Un dépliage ne rappelle
      // jamais Keycloak pour une organisation déjà chargée.
      var state  = { orgs: [], truncated: false, issuer: '', membersCache: {} };
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
        return '<tr class="orgs-state' + (isError ? ' orgs-state--error' : '') + '"><td colspan="' + COLS + '">' + esc(text) + '</td></tr>';
      }

      function chevron(){
        return '<svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
      }

      // Raccourcis de fin de ligne : mêmes pages que le menu, mais filtrées
      // sur l'UID Keycloak de l'organisation (?org=). C'est data/portail_api.php
      // qui relaie cet UID à n8n sous la clé « organization_uid ».
      var ICONS = {
        invoice: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>',
        order:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
        sub:     '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>'
      };

      function shortcut(page, orgId, icon, title){
        return '<a class="org-link" href="./' + page + '?org=' + encodeURIComponent(orgId) + '"' +
               ' title="' + esc(title) + '" aria-label="' + esc(title) + '">' + icon + '</a>';
      }

      function rowHtml(o){
        var ids = [];
        if (o.siret)        ids.push('SIRET ' + o.siret);
        else if (o.siren)   ids.push('SIREN ' + o.siren);
        if (o.entite_legal) ids.push(o.entite_legal);
        if (o.tva)          ids.push('TVA ' + o.tva);

        var domains = (o.domains && o.domains.length) ? o.domains.join(', ') : (I18N.noDomain || '—');
        var statusLabel = o.enabled ? (I18N.active || 'Activée') : (I18N.inactive || 'Désactivée');
        var statusClass = o.enabled ? BADGE_OK : BADGE_OFF;

        var hay = [o.label, o.name, o.alias, o.raison, o.siret, o.siren, o.namespace,
                   o.localite, o.entite_legal, domains, statusLabel].join(' ').toLowerCase();

        // Sous-titre : le nom technique, seulement s'il diffère du libellé.
        var technical = (o.name && o.name !== o.label) ? o.name : (o.alias && o.alias !== o.label ? o.alias : '');

        return '<tr data-search="' + esc(hay) + '" data-org-row="' + esc(o.id) + '">' +
          '<td class="border-surface border-b p-4 align-top">' +
            '<button type="button" class="org-toggle" aria-expanded="false" data-org-id="' + esc(o.id) + '">' +
              chevron() +
              '<span><b class="text-default block text-sm font-semibold">' + esc(o.label) + '</b>' +
              (technical ? '<span class="text-foreground block text-sm">' + esc(technical) + '</span>' : '') +
              '</span>' +
            '</button>' +
          '</td>' +
          '<td class="border-surface border-b p-4 align-top"><div>' +
            '<p class="text-default block text-sm">' + esc(ids.length ? ids.join(' · ') : '—') + '</p>' +
            (o.namespace ? '<p class="text-foreground block text-sm">ns ' + esc(o.namespace) + '</p>' : '') +
          '</div></td>' +
          '<td class="border-surface border-b p-4 align-top"><div>' +
            '<p class="text-default block text-sm">' + esc(o.localite || '—') + '</p>' +
            (o.pays ? '<p class="text-foreground block text-sm">' + esc(o.pays) + '</p>' : '') +
          '</div></td>' +
          '<td class="border-surface border-b p-4 align-top"><p class="text-foreground block text-sm">' + esc(domains) + '</p></td>' +
          '<td class="border-surface border-b p-4 align-top"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 ' + statusClass + '">' + esc(statusLabel) + '</span></td>' +
          '<td class="border-surface border-b p-4 align-top"><div class="org-links">' +
            shortcut('facture',     o.id, ICONS.invoice, I18N.goInvoices || 'Factures') +
            shortcut('commande',    o.id, ICONS.order,   I18N.goOrders   || 'Commandes') +
            shortcut('abonnements', o.id, ICONS.sub,     I18N.goSubs     || 'Abonnements') +
          '</div></td>' +
        '</tr>' +
        '<tr class="org-members" data-org-members="' + esc(o.id) + '" hidden>' +
          '<td colspan="' + COLS + '"><div class="org-members-inner"></div></td>' +
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

          // Le panneau des membres suit sa ligne : masqué si la ligne l'est,
          // ou si le dépliage a été refermé.
          var id    = row.getAttribute('data-org-row');
          var panel = tbody.querySelector('tr[data-org-members="' + sel(id) + '"]');
          var btn   = row.querySelector('.org-toggle');
          if (panel) {
            panel.hidden = !match || !btn || btn.getAttribute('aria-expanded') !== 'true';
          }
        });
        var noRes = document.getElementById('orgsNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows(){
        var list = state.orgs;
        if (!list.length) {
          tbody.innerHTML = stateRow(I18N.empty || 'Aucune entreprise.', false);
          setCounter(0);
          return;
        }
        tbody.innerHTML = list.map(rowHtml).join('') +
          '<tr id="orgsNoResults" class="orgs-state" hidden><td colspan="' + COLS + '">' + esc(I18N.noResults || '') + '</td></tr>';
        setCounter(list.length);
        applyFilter();
      }

      function updateHeader(){
        if (roBadge) roBadge.textContent = I18N.readOnly || 'Lecture seule';
        if (realmEl) {
          // Realm réellement interrogé : utile quand on doute de la source.
          var realm = '';
          if (state.issuer) {
            var m = String(state.issuer).match(/\/realms\/([^\/]+)/);
            realm = m ? m[1] : '';
          }
          if (realm) { realmEl.textContent = realm; realmEl.hidden = false; }
          else       { realmEl.textContent = ''; realmEl.hidden = true; }
        }
      }

      function memberHtml(m){
        return '<div class="member-line">' +
          '<span class="member-av">' + esc(m.initials) + '</span>' +
          '<span class="member-main"><b>' + esc(m.name) + '</b><span>' + esc(m.secondary) + '</span></span>' +
          '<span class="member-meta">' + esc(m.function || I18N.noFunction || '') +
            '<br>' + esc(m.status_label) + ' · ' + esc(m.membership) +
          '</span>' +
        '</div>';
      }

      function loadMembers(orgId, panel){
        var inner = panel.querySelector('.org-members-inner');
        if (!inner) return;

        var cached = state.membersCache[orgId];
        if (cached === 'loading') return;
        if (cached) { inner.innerHTML = cached; return; }

        state.membersCache[orgId] = 'loading';
        inner.innerHTML = '<p class="text-sm text-muted-foreground">' + esc(I18N.membersLoad || 'Chargement…') + '</p>';

        fetch(API + '?action=org.members&org_id=' + encodeURIComponent(orgId),
              { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
        .then(function (r){
          var data = r.data, html;
          if (!r.ok || !data || !data.ok) {
            var msg = (data && data.error) ? data.error : (I18N.membersErr || 'Erreur.');
            html = '<p class="text-sm" style="color:#b91c1c">' + esc(msg) + '</p>';
            // Un échec n'est pas mis en cache : le prochain dépliage réessaie.
            delete state.membersCache[orgId];
          } else {
            var list = Array.isArray(data.members) ? data.members : [];
            html = list.length
              ? list.map(memberHtml).join('') +
                (data.truncated ? '<p class="text-sm text-muted-foreground" style="margin-top:.6rem">' + esc(I18N.membersMore || '') + '</p>' : '')
              : '<p class="text-sm text-muted-foreground">' + esc(I18N.membersNone || '') + '</p>';
            state.membersCache[orgId] = html;
          }
          inner.innerHTML = html;
        })
        .catch(function (){
          delete state.membersCache[orgId];
          inner.innerHTML = '<p class="text-sm" style="color:#b91c1c">' + esc(I18N.membersErr || 'Erreur.') + '</p>';
        });
      }

      function load(){
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        fetch(API + '?action=org.list', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
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
          state.orgs         = Array.isArray(data.orgs) ? data.orgs : [];
          state.truncated    = !!data.truncated;
          state.issuer       = data.issuer || '';
          state.membersCache = {};
          updateHeader();
          renderRows();
          showAlert(state.truncated ? (I18N.truncated || '') : '', false);
        })
        .catch(function (){ tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les entreprises.', true); setCounter(null); });
      }

      // Délégation : les lignes sont rendues dynamiquement.
      tbody.addEventListener('click', function (e){
        var btn = e.target.closest ? e.target.closest('.org-toggle') : null;
        if (!btn) return;
        e.preventDefault();

        var orgId = btn.getAttribute('data-org-id');
        var panel = tbody.querySelector('tr[data-org-members="' + sel(orgId) + '"]');
        if (!panel) return;

        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        panel.hidden = open;
        if (!open) loadMembers(orgId, panel);
      });

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
  <!-- Dépliants de la barre latérale (dont « Client ») : cette page n'avait aucun contrôleur. -->
  <script src="../assets/js/collapsible.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/collapsible.js') ?>" defer></script>
</body>
</html>
