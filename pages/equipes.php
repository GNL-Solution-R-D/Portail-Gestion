<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

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
// (L'ancienne version masquait la recherche ; on l'utilise désormais pour
//  filtrer la liste des membres alimentée par data/portail_api.php → n8n.)
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

    /* Modale d'édition d'un membre */
    .member-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:60;}
    .member-modal{background:var(--background, #fff);color:inherit;width:100%;max-width:520px;border-radius:.9rem;border:1px solid rgba(148,163,184,.3);box-shadow:0 20px 50px rgba(2,6,23,.35);padding:1.5rem;}
    .member-modal h2{font-size:1.05rem;font-weight:700;margin:0;}
    .member-modal .modal-sub{font-size:.85rem;color:var(--muted-foreground,#64748b);margin:.25rem 0 1rem;}
    .member-modal label{display:block;margin-bottom:.9rem;}
    .member-modal label > span{display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;}
    .member-modal input,.member-modal select{height:2.5rem;width:100%;border-radius:.5rem;border:1px solid rgba(148,163,184,.45);background:transparent;padding:0 .75rem;font-size:.9rem;}
    .member-modal .modal-actions{display:flex;justify-content:flex-end;gap:.6rem;margin-top:.5rem;}
    .member-modal .btn{height:2.5rem;padding:0 1rem;border-radius:.5rem;font-size:.88rem;font-weight:600;border:1px solid rgba(148,163,184,.45);background:transparent;cursor:pointer;}
    .member-modal .btn-primary{background:var(--primary,#0f172a);color:var(--primary-foreground,#fff);border-color:transparent;}
    .member-modal .modal-error{display:none;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:.5rem;padding:.5rem .75rem;font-size:.82rem;margin-bottom:.9rem;}

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
              <p class="text-sm text-muted-foreground"><?= t('Gestion des Acces') ?></p>
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
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Action') ?></p></th>
                </tr>
              </thead>
              <tbody id="membersTableBody">
                <tr class="members-state">
                  <td colspan="5"><?= t('Chargement des membres…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>

  <!-- Modale d'édition (remplie et soumise en JS via data/portail_api.php) -->
  <div id="memberEditOverlay" class="member-modal-overlay" hidden>
    <div class="member-modal" role="dialog" aria-modal="true" aria-labelledby="memberEditTitle">
      <h2 id="memberEditTitle"><?= t('Modifier le membre') ?></h2>
      <p id="memberEditSubtitle" class="modal-sub"></p>
      <div id="memberEditError" class="modal-error"></div>

      <label>
        <span><?= t('E-mail') ?></span>
        <input id="memberEditEmail" type="email" autocomplete="email">
      </label>
      <label>
        <span><?= t('Fonction') ?></span>
        <input id="memberEditFonction" type="text">
      </label>
      <label>
        <span><?= t('Statut') ?></span>
        <select id="memberEditStatut">
          <option value="actif">Actif</option>
          <option value="inactif">Inactif</option>
        </select>
      </label>

      <input type="hidden" id="memberEditId" value="">
      <div class="modal-actions">
        <button type="button" class="btn" id="memberEditCancel">Annuler</button>
        <button type="button" class="btn btn-primary" id="memberEditSave"><?= t('Enregistrer') ?></button>
      </div>
    </div>
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

  <!-- Données des membres via data/portail_api.php (→ n8n) + recherche du header -->
  <script>
    window.TEAM_API_URL = window.TEAM_API_URL || "../data/portail_api.php";
    window.TEAM_CSRF = window.NOTIF_CSRF || <?= json_encode($_SESSION['csrf'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.TEAM_I18N = {
      loading:   <?= json_encode(t('Chargement des membres…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:     <?= json_encode(t('Aucun membre trouvé pour cette structure.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults: <?= json_encode(t('Aucun membre ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:     <?= json_encode(t('Impossible de charger les membres.'), JSON_UNESCAPED_UNICODE) ?>,
      edit:      <?= json_encode('Modifier', JSON_UNESCAPED_UNICODE) ?>,
      notAllowed:<?= json_encode(t('Non autorisé'), JSON_UNESCAPED_UNICODE) ?>,
      editAllowed:<?= json_encode(t('Édition autorisée'), JSON_UNESCAPED_UNICODE) ?>,
      readOnly:  <?= json_encode(t('Lecture seule'), JSON_UNESCAPED_UNICODE) ?>,
      updated:   <?= json_encode(t('Le contact a été mis à jour.'), JSON_UNESCAPED_UNICODE) ?>,
      noFunction:<?= json_encode('Aucune fonction définie', JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    var I18N = window.TEAM_I18N || {};
    var API  = window.TEAM_API_URL || "../data/portail_api.php";

    function norm(s){ return String(s==null?'':s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

    ready(function () {
      var input    = document.getElementById('membersSearchInput');
      var tbody    = document.getElementById('membersTableBody');
      var counter  = document.getElementById('membersCount');
      var editBadge= document.getElementById('editModeBadge');
      var structEl = document.getElementById('structureName');
      var alerts   = document.getElementById('teamAlerts');
      if (!tbody) return;

      var state = { members: [], byId: {}, structure: '', canEdit: false };
      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      // ---- Modale ----
      var overlay   = document.getElementById('memberEditOverlay');
      var fId       = document.getElementById('memberEditId');
      var fEmail    = document.getElementById('memberEditEmail');
      var fFonction = document.getElementById('memberEditFonction');
      var fStatut   = document.getElementById('memberEditStatut');
      var fSub      = document.getElementById('memberEditSubtitle');
      var fErr      = document.getElementById('memberEditError');
      var btnSave   = document.getElementById('memberEditSave');
      var btnCancel = document.getElementById('memberEditCancel');

      function setCounter(n){ if (counter) counter.textContent = (n==null?'…':n) + (suffix ? ' ' + suffix : ''); }

      function showAlert(message, isError){
        if (!alerts) return;
        var ok = !isError;
        var div = document.createElement('div');
        div.className = 'rounded-xl border px-6 py-4 text-sm ' + (ok
          ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900/30 dark:bg-green-950/30 dark:text-green-300'
          : 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-300');
        div.textContent = message;
        alerts.innerHTML = '';
        alerts.appendChild(div);
      }

      function stateRow(text, isError){
        return '<tr class="members-state' + (isError ? ' members-state--error' : '') + '"><td colspan="5">' + esc(text) + '</td></tr>';
      }

      function rowHtml(m){
        var structure = state.structure || m.structure || '—';
        var hay = [m.name, m.secondary, m.function, m.status_label, m.permission, structure].join(' ').toLowerCase();
        var action = state.canEdit
          ? '<button type="button" class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-background shadow-xs hover:bg-accent h-9 px-3 py-2" data-edit-id="' + m.id + '">' + esc(I18N.edit || 'Modifier') + '</button>'
          : '<span class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border bg-slate-50 text-slate-500 h-9 px-3 py-2 cursor-not-allowed">' + esc(I18N.notAllowed || 'Non autorisé') + '</span>';

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
            '<p class="text-foreground block text-sm">' + esc(m.function) + '</p>' +
          '</div></td>' +
          '<td class="border-surface border-b p-4 align-top"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 ' + esc(m.status_class) + '">' + esc(m.status_label) + '</span></td>' +
          '<td class="border-surface border-b p-4 align-top"><div><p class="text-foreground block text-sm">' + esc(m.permission) + '</p></div></td>' +
          '<td class="border-surface border-b p-4 align-top">' + action + '</td>' +
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
          '<tr id="membersNoResults" class="members-state" hidden><td colspan="5">' + esc(I18N.noResults || '') + '</td></tr>';
        setCounter(list.length);
        applyFilter();
      }

      function updateHeader(){
        if (structEl) structEl.textContent = state.structure ? (' : ' + state.structure) : '';
        if (editBadge) {
          editBadge.textContent = state.canEdit ? (I18N.editAllowed || 'Édition autorisée') : (I18N.readOnly || 'Lecture seule');
          editBadge.className = 'inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium ' + (state.canEdit
            ? 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300'
            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300');
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
            tbody.innerHTML = stateRow((I18N.error || 'Erreur') + ' ' + msg, true);
            setCounter(null);
            return;
          }
          state.members   = Array.isArray(data.members) ? data.members : [];
          state.structure = data.structure || '';
          state.canEdit   = !!data.can_edit;
          state.byId = {};
          state.members.forEach(function (m){ state.byId[String(m.id)] = m; });
          updateHeader();
          renderRows();
        })
        .catch(function (){ tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les membres.', true); setCounter(null); });
      }

      // ---- Édition ----
      function openEdit(id){
        var m = state.byId[String(id)];
        if (!m || !state.canEdit) return;
        fErr.style.display = 'none'; fErr.textContent = '';
        fId.value = m.id;
        fEmail.value = m.email || '';
        fFonction.value = m.fonction || '';
        fStatut.value = (m.active === 1 || m.active === '1') ? 'actif' : 'inactif';
        fSub.textContent = m.name + ' · ' + m.secondary;
        overlay.hidden = false;
      }
      function closeEdit(){ overlay.hidden = true; }

      function saveEdit(){
        var body = new URLSearchParams();
        body.set('action', 'team.update');
        body.set('member_id', fId.value);
        body.set('email', fEmail.value.trim());
        body.set('fonction', fFonction.value.trim());
        body.set('statut', fStatut.value);

        btnSave.disabled = true;
        fetch(API, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': window.TEAM_CSRF || ''
          },
          credentials: 'same-origin',
          body: body.toString()
        })
        .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
        .then(function (r){
          btnSave.disabled = false;
          var data = r.data;
          if (!r.ok || !data || !data.ok) {
            fErr.textContent = (data && data.error) ? data.error : 'La mise à jour a échoué.';
            fErr.style.display = 'block';
            return;
          }
          closeEdit();
          showAlert((data && data.message) ? data.message : (I18N.updated || 'Mis à jour.'), false);
          load();
        })
        .catch(function (){ btnSave.disabled = false; fErr.textContent = 'Connexion impossible.'; fErr.style.display = 'block'; });
      }

      // Délégation : bouton "Modifier" (les lignes sont rendues dynamiquement).
      tbody.addEventListener('click', function (e){
        var btn = e.target.closest('[data-edit-id]');
        if (btn) openEdit(btn.getAttribute('data-edit-id'));
      });
      if (btnCancel) btnCancel.addEventListener('click', closeEdit);
      if (btnSave) btnSave.addEventListener('click', saveEdit);
      if (overlay) overlay.addEventListener('click', function (e){ if (e.target === overlay) closeEdit(); });
      document.addEventListener('keydown', function (e){ if (e.key === 'Escape' && overlay && !overlay.hidden) closeEdit(); });

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