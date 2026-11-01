<?php
/* =====================================================================
   pages/services-gnl.php — Services internes GNL (maquette « ServicesGNL »)
   ---------------------------------------------------------------------
   Déploiements du namespace interne (webintern) :
     • data/k8s_api.php?action=list_deployments          → état (ready/replicas)
     • data/k8s_api.php?action=list_pods_for_deployment  → pods
     • data/k8s_api.php?action=restart_deployment (POST) → redémarrage
     • portail_api deployment.list / deployment.rename   → nom d'affichage
   Bouton « Planifier une maintenance » → include/maintenance_modal.php.
   ===================================================================== */
require_once __DIR__ . '/../include/staff_shell.php';

gnl_staff_open('Services GNL', ['search' => 'Rechercher un service', 'searchId' => 'gnlSearch']);
gnl_page_head(
    'TECHNIQUE',
    'Services GNL',
    'Les services internes de GNL Solution, déployés dans le namespace webintern.',
    '<span class="ec-muted ec-small" id="gnlSummary"></span>'
    . '<button type="button" class="ec-btn ec-btn--primary" data-gs-open="maintModal">Planifier une maintenance</button>',
    ''
);
?>
        <div class="gs-split">
          <section class="ec-card" aria-label="Services">
            <div class="ec-table-wrap">
              <table class="ec-table">
                <thead><tr><th>Service</th><th>État</th><th class="ec-num">Pods</th></tr></thead>
                <tbody id="gnlList"><tr class="ec-table__state"><td colspan="3">Chargement…</td></tr></tbody>
              </table>
            </div>
          </section>

          <div style="display:flex;flex-direction:column;gap:24px;min-width:0">
            <section class="ec-card" id="gnlDetail" aria-live="polite">
              <div class="ec-empty"><strong>Sélectionnez un service</strong>Son état, ses pods et les actions apparaissent ici.</div>
            </section>

            <section class="ec-card" aria-labelledby="gnlDepsTitle">
              <div class="ec-card__head">
                <div>
                  <h2 class="ec-h2" id="gnlDepsTitle">Briques dont dépendent les portails</h2>
                  <p class="ec-muted ec-small" style="margin:4px 0 0">Si l'une d'elles tombe, l'Espace Client affiche une erreur sur la fonction concernée.</p>
                </div>
              </div>
              <ul class="ec-list" id="gnlDeps"><li class="ec-muted ec-small">Chargement…</li></ul>
            </section>
          </div>
        </div>

  <script>
  (function () {
    'use strict';
    var K8S = '/data/k8s_api.php';
    // Briques critiques : nom (ou début de nom) du deployment → rôle affiché.
    var DEPS = [
      ['keycloak', 'Authentification', 'Connexion aux deux portails'],
      ['n8n', 'Automatisations', 'Factures, tickets, notifications, DNS'],
      ['postgres', 'Base de données', 'Comptes, sessions et journaux'],
      ['mailer', 'Envoi des e-mails', 'Invitations, alertes et factures'],
      ['b2b-portal', 'Espace Client', 'Portail des clients']
    ];
    var list = [], names = {}, sel = '';

    function k8s(action, params, post) {
      var q = new URLSearchParams(Object.assign({ action: action }, params || {}));
      var opt = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
      if (post) {
        opt.method = 'POST';
        opt.headers['X-CSRF-Token'] = window.GNL_CSRF || '';
        opt.body = new URLSearchParams(post);
      }
      return fetch(K8S + '?' + q.toString(), opt).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Réseau indisponible.' }; });
    }

    function stateOf(d) {
      if (!d.replicas) return ['Arrêté', 'off'];
      if (d.ready >= d.replicas) return ['En ligne', 'ok'];
      if (d.ready > 0) return ['Dégradé', 'warn'];
      return ['Hors ligne', 'danger'];
    }
    function label(d) { return names[d.name] || d.name; }

    function renderList() {
      var q = ((document.getElementById('gnlSearch') || {}).value || '').trim().toLowerCase();
      var body = document.getElementById('gnlList');
      var rows = list.filter(function (d) { return !q || (d.name + ' ' + label(d)).toLowerCase().indexOf(q) !== -1; });
      if (!rows.length) { GS.state(body, 3, q ? 'Aucun service ne correspond.' : 'Aucun déploiement dans le namespace.'); return; }
      body.innerHTML = rows.map(function (d) {
        var st = stateOf(d);
        return '<tr data-sel="' + GS.esc(d.name) + '"' + (d.name === sel ? ' class="is-selected" aria-selected="true"' : '') + ' tabindex="0">'
          + '<td><span class="gs-title">' + GS.esc(label(d)) + '</span><span class="gs-sub gs-mono">' + GS.esc(d.name) + '</span></td>'
          + '<td>' + GS.badge(st[0], st[1]) + '</td><td class="ec-num">' + d.ready + '/' + d.replicas + '</td></tr>';
      }).join('');
    }

    function renderDeps() {
      var ul = document.getElementById('gnlDeps');
      var html = DEPS.map(function (x) {
        var d = list.filter(function (r) { return r.name === x[0] || r.name.indexOf(x[0] + '-') === 0; })[0];
        var st = d ? stateOf(d) : ['Non déployé ici', 'off'];
        return '<li class="gs-alert"><div class="gs-alert__txt"><strong>' + GS.esc(x[1]) + '</strong><span>' + GS.esc(x[2]) + '</span></div>' + GS.badge(st[0], st[1]) + '</li>';
      }).join('');
      ul.innerHTML = html;
    }

    function summary() {
      var ok = list.filter(function (d) { return stateOf(d)[1] === 'ok'; }).length;
      var bad = list.length - ok;
      document.getElementById('gnlSummary').textContent = ok + ' en ligne' + (bad ? ' · ' + bad + ' à surveiller' : '');
    }

    function detail(name) {
      sel = name;
      renderList();
      var d = list.filter(function (x) { return x.name === name; })[0];
      var box = document.getElementById('gnlDetail');
      if (!d) return;
      var st = stateOf(d);
      if (history.replaceState) history.replaceState(null, '', '#' + encodeURIComponent(name));
      box.innerHTML = '<div class="ec-card__head"><div><h2 class="ec-h2">' + GS.esc(label(d)) + '</h2>'
        + '<span class="gs-sub gs-mono">' + GS.esc(d.name) + ' · webintern</span></div>' + GS.badge(st[0], st[1]) + '</div>'
        + '<div class="ec-card__body">'
        + '<div class="gs-toolbar">'
        + '<a class="ec-btn ec-btn--ghost ec-btn--sm" href="/log?deployment=' + encodeURIComponent(d.name) + '">Journaux</a>'
        + '<button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" data-act="rename">Renommer</button>'
        + '<button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" data-act="restart">Redémarrer</button>'
        + '</div>'
        + '<form class="gs-optform" data-form="rename" hidden><label class="gs-field">Nom d\'affichage'
        + '<input type="text" name="display_name" maxlength="80" value="' + GS.esc(names[d.name] || '') + '" placeholder="' + GS.esc(d.name) + '"></label>'
        + '<div class="gs-actions" style="margin-top:12px"><button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" data-act="rename-cancel">Annuler</button>'
        + '<button type="submit" class="ec-btn ec-btn--primary ec-btn--sm">Enregistrer</button></div></form>'
        + '<dl class="gs-kv" style="margin-top:16px"><dt>Réplicas prêts</dt><dd>' + d.ready + ' / ' + d.replicas + '</dd>'
        + '<dt>À jour</dt><dd>' + d.updated + '</dd><dt>Créé le</dt><dd>' + GS.date(d.createdAt) + '</dd></dl>'
        + '</div>'
        + '<p class="ec-kicker" style="padding:0 20px">PODS</p>'
        + '<div class="ec-table-wrap"><table class="ec-table"><thead><tr><th>Pod</th><th>État</th><th>Conteneurs</th></tr></thead>'
        + '<tbody data-pods><tr class="ec-table__state"><td colspan="3">Chargement des pods…</td></tr></tbody></table></div>';

      k8s('list_pods_for_deployment', { deployment: d.name }).then(function (r) {
        var tb = box.querySelector('[data-pods]');
        if (!tb || sel !== d.name) return;
        if (!r.ok) { GS.state(tb, 3, r.error || 'Pods indisponibles.', true); return; }
        if (!(r.pods || []).length) { GS.state(tb, 3, 'Aucun pod.'); return; }
        tb.innerHTML = r.pods.map(function (p) {
          var ph = String(p.phase || 'Unknown');
          var tone = ph === 'Running' || ph === 'Succeeded' ? 'ok' : ph === 'Pending' ? 'warn' : 'danger';
          return '<tr><td class="gs-mono">' + GS.esc(p.name) + '</td><td>' + GS.badge(ph, tone) + '</td><td>'
            + GS.esc((p.containers || []).map(function (c) { return c.name; }).join(', ') || '—') + '</td></tr>';
        }).join('');
      });
    }

    document.getElementById('gnlList').addEventListener('click', function (e) {
      var tr = e.target.closest('[data-sel]');
      if (tr) detail(tr.getAttribute('data-sel'));
    });
    document.getElementById('gnlList').addEventListener('keydown', function (e) {
      var tr = e.target.closest('[data-sel]');
      if (tr && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); detail(tr.getAttribute('data-sel')); }
    });

    var box = document.getElementById('gnlDetail');
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]');
      if (!b) return;
      var form = box.querySelector('[data-form="rename"]');
      var act = b.getAttribute('data-act');
      if (act === 'rename') { form.hidden = false; form.querySelector('input').focus(); }
      if (act === 'rename-cancel') form.hidden = true;
      if (act === 'restart') {
        if (!window.confirm('Redémarrer ' + label({ name: sel }) + ' ? Les pods sont recréés un par un.')) return;
        b.disabled = true;
        k8s('restart_deployment', {}, { name: sel }).then(function (r) {
          b.disabled = false;
          GS.toast(r.ok ? 'Redémarrage lancé.' : (r.error || 'Redémarrage impossible.'), !r.ok);
          if (r.ok) setTimeout(function () { detail(sel); }, 2500);
        });
      }
    });
    box.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = e.target.querySelector('input').value.trim();
      GS.post('deployment.rename', { deployment_name: sel, display_name: v }).then(function (r) {
        if (!r.ok) { GS.toast(r.error || 'Renommage impossible.', true); return; }
        if (v) names[sel] = v; else delete names[sel];
        GS.toast('Nom enregistré.');
        detail(sel);
      });
    });
    var s = document.getElementById('gnlSearch');
    if (s) s.addEventListener('input', renderList);

    Promise.all([k8s('list_deployments'), GS.get('deployment.list')]).then(function (res) {
      var k = res[0], n = res[1];
      (n && n.ok ? n.deployments || [] : []).forEach(function (x) { if (x.display_name) names[x.deployment_name] = x.display_name; });
      if (!k.ok) { GS.state(document.getElementById('gnlList'), 3, k.error || 'API Kubernetes indisponible.', true); document.getElementById('gnlDeps').innerHTML = '<li class="ec-muted ec-small">État inconnu.</li>'; return; }
      list = (k.deployments || []).map(function (d) {
        return { name: d.name, replicas: +d.replicas || 0, ready: +d.ready || 0, updated: +d.updated || 0, createdAt: d.createdAt };
      });
      summary(); renderList(); renderDeps();
      var want = decodeURIComponent((location.hash || '').slice(1));
      var first = list.filter(function (d) { return d.name === want; })[0] || list.filter(function (d) { return stateOf(d)[1] !== 'ok'; })[0] || list[0];
      if (first) detail(first.name);
    });
  })();
  </script>
<?php
gnl_staff_close();
