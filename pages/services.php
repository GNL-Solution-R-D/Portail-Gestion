<?php
/* =====================================================================
   pages/services.php — Services clients (maquette « ServicesClients »)
   ---------------------------------------------------------------------
   Tous les services achetés par les clients, par catégorie, avec le
   filtre entreprise (?org=). Sources :
     • data/services_menu_api.php → menus { web, cloud, other, vm, bm }
     • deployment.list (n8n)      → onglet « Services GNL » (webintern)
   ===================================================================== */
require_once __DIR__ . '/../include/staff_shell.php';

gnl_staff_open('Services clients', ['search' => 'Rechercher un service', 'searchId' => 'svcSearch']);
gnl_page_head(
    'CLIENTS',
    'Services clients',
    'Tous les déploiements, toutes entreprises confondues. Les services internes de GNL sont dans le dernier onglet.',
    '<span class="ec-muted ec-small" id="svcSummary"></span>'
);
?>
        <div class="gs-toolbar" role="toolbar" aria-label="Catégories" id="svcTabs">
          <button type="button" class="ec-filter" data-tab="all" aria-pressed="true">Tous<span class="gs-count" data-c="all">0</span></button>
          <button type="button" class="ec-filter" data-tab="web" aria-pressed="false">Sites web<span class="gs-count" data-c="web">0</span></button>
          <button type="button" class="ec-filter" data-tab="cloud" aria-pressed="false">Cloud<span class="gs-count" data-c="cloud">0</span></button>
          <button type="button" class="ec-filter" data-tab="other" aria-pressed="false">Spécifiques<span class="gs-count" data-c="other">0</span></button>
          <button type="button" class="ec-filter" data-tab="vm" aria-pressed="false">Serveurs virtuels<span class="gs-count" data-c="vm">0</span></button>
          <button type="button" class="ec-filter" data-tab="bm" aria-pressed="false">Serveurs dédiés<span class="gs-count" data-c="bm">0</span></button>
          <button type="button" class="ec-filter" data-tab="gnl" aria-pressed="false">Services GNL<span class="gs-count" data-c="gnl">0</span></button>
        </div>

        <section class="ec-card">
          <div class="ec-table-wrap">
            <table class="ec-table">
              <thead><tr><th>Service</th><th>Entreprise</th><th>Offre</th><th>Référence</th><th>État</th><th class="ec-num"><span class="ec-sr">Actions</span></th></tr></thead>
              <tbody id="svcBody"><tr class="ec-table__state"><td colspan="6">Chargement des services…</td></tr></tbody>
            </table>
          </div>
        </section>

  <script>
  (function () {
    'use strict';
    var CAT = { web: 'Site web', cloud: 'Cloud', other: 'Spécifique', vm: 'Serveur virtuel', bm: 'Serveur dédié', gnl: 'Interne' };
    var STATE = {
      active: ['En ligne', 'ok'], running: ['En ligne', 'ok'], up: ['En ligne', 'ok'],
      deployment: ['Déploiement', 'warn'], pending: ['En attente', 'warn'], degraded: ['Dégradé', 'warn'],
      suspended: ['Suspendu', 'danger'], failed: ['En échec', 'danger'], stopped: ['Arrêté', 'off'], down: ['Hors ligne', 'danger']
    };
    var rows = [], tab = 'all';
    var body = document.getElementById('svcBody');

    function state(s) { var k = String(s || '').toLowerCase(); return STATE[k] || [s || 'Inconnu', GS.tone(k)]; }

    function render() {
      var q = ((document.getElementById('svcSearch') || {}).value || '').trim().toLowerCase();
      var list = rows.filter(function (r) {
        if (tab === 'all' ? r.cat === 'gnl' : r.cat !== tab) return false;
        return !q || (r.name + ' ' + r.offer + ' ' + r.org + ' ' + r.ref).toLowerCase().indexOf(q) !== -1;
      });
      if (!list.length) { GS.state(body, 6, q ? 'Aucun service ne correspond à la recherche.' : 'Aucun service dans cette catégorie.'); return; }
      body.innerHTML = list.map(function (r) {
        var st = state(r.status);
        var manage = r.href ? '<a class="gs-link" href="' + GS.esc(r.href) + '">Gérer</a>' : '';
        var logs = r.deploy ? '<a class="gs-link" href="/log?deployment=' + encodeURIComponent(r.deploy) + '">Journaux</a>' : '';
        return '<tr><td><span class="gs-title">' + GS.esc(r.name) + '</span><span class="gs-sub">' + GS.esc(CAT[r.cat] || '') + '</span></td>'
          + '<td>' + GS.esc(r.org || '—') + '</td><td>' + GS.esc(r.offer || '—') + '</td>'
          + '<td class="gs-mono">' + GS.esc(r.ref || '—') + '</td>'
          + '<td>' + GS.badge(st[0], st[1]) + '</td>'
          + '<td class="gs-actions">' + [manage, logs].filter(Boolean).join(' · ') + '</td></tr>';
      }).join('');
    }

    function counts() {
      var c = { all: 0, gnl: 0 };
      rows.forEach(function (r) { c[r.cat] = (c[r.cat] || 0) + 1; if (r.cat !== 'gnl') c.all++; });
      Array.prototype.forEach.call(document.querySelectorAll('[data-c]'), function (el) { el.textContent = String(c[el.getAttribute('data-c')] || 0); });
      var bad = rows.filter(function (r) { return r.cat !== 'gnl' && state(r.status)[1] !== 'ok'; }).length;
      document.getElementById('svcSummary').textContent = c.all + ' services clients · ' + bad + ' à surveiller';
    }

    document.getElementById('svcTabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]');
      if (!b) return;
      tab = b.getAttribute('data-tab');
      Array.prototype.forEach.call(this.querySelectorAll('[data-tab]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      render();
    });
    var s = document.getElementById('svcSearch');
    if (s) s.addEventListener('input', render);

    var url = '/data/services_menu_api.php' + (GS.org ? '?org=' + encodeURIComponent(GS.org) : '');
    Promise.all([
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; }),
      fetch('/data/k8s_api.php?action=list_deployments', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; }),
      GS.get('deployment.list')
    ]).then(function (res) {
      var m = res[0], k = res[1], names = {};
      ((res[2] && res[2].deployments) || []).forEach(function (x) { if (x.display_name) names[x.deployment_name] = x.display_name; });
      var d = { ok: !!(k && k.ok), deployments: ((k && k.deployments) || []).map(function (x) {
        var st = !x.replicas ? 'stopped' : x.ready >= x.replicas ? 'running' : x.ready > 0 ? 'degraded' : 'down';
        return { deployment_name: x.name, display_name: names[x.name] || '', status: st };
      }) };
      if (m && m.ok) {
        Object.keys(m.menus || {}).forEach(function (cat) {
          (m.menus[cat] || []).forEach(function (x) {
            rows.push({ cat: cat, name: x.display_name || x.name, offer: x.product_name || '', org: x.organization || x.structure || x.org_name || '', ref: x.ref || x.slug || '', status: x.status, href: x.href || '', deploy: x.provider_service_slug || x.slug || '' });
          });
        });
      }
      if (d && d.ok) {
        (d.deployments || []).forEach(function (x) {
          if (x.namespace && x.namespace !== 'webintern') return;
          rows.push({ cat: 'gnl', name: x.display_name || x.deployment_name, offer: 'Interne', org: 'GNL Solution', ref: x.deployment_name, status: x.status, href: '/services-gnl#' + encodeURIComponent(x.deployment_name), deploy: x.deployment_name });
        });
      }
      if (!rows.length && !(m && m.ok)) { GS.state(body, 6, 'Services indisponibles (catalogue n8n injoignable).', true); return; }
      counts();
      render();
    });
  })();
  </script>
<?php
gnl_staff_close();
