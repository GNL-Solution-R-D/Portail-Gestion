<?php
/* =====================================================================
   pages/serveurs-dns.php — Serveurs DNS (maquette « DNS »)
   ---------------------------------------------------------------------
   • dns_server.list / dns_server.delete (n8n via data/portail_api.php)
   • Ajout : modale #addDnsServerModal de include/menu.php
     (bouton [data-add-dns-server-open]) → dns_server.add
   • Zones hébergées : domain.list → lien vers /zdns?domain=…
   ===================================================================== */
require_once __DIR__ . '/../include/staff_shell.php';

gnl_staff_open('Serveurs DNS', ['search' => 'Rechercher une zone', 'searchId' => 'dnsSearch']);
gnl_page_head(
    'TECHNIQUE',
    'Serveurs DNS',
    'Serveurs faisant autorité pour les zones des clients. Le primaire pousse les zones vers les secondaires.',
    '<button type="button" class="ec-btn ec-btn--primary" data-add-dns-server-open>Ajouter un serveur DNS</button>'
);
?>
        <section class="ec-card" aria-labelledby="dnsSrvTitle">
          <div class="ec-card__head"><h2 class="ec-h2" id="dnsSrvTitle">Serveurs</h2><span class="ec-muted ec-small" id="dnsSrvCount"></span></div>
          <div class="ec-table-wrap">
            <table class="ec-table">
              <thead><tr><th>Serveur</th><th>Rôle</th><th>IPv4</th><th>IPv6</th><th class="ec-num">Zones</th><th>État</th><th class="ec-num"><span class="ec-sr">Actions</span></th></tr></thead>
              <tbody id="dnsServers"><tr class="ec-table__state"><td colspan="7">Chargement des serveurs…</td></tr></tbody>
            </table>
          </div>
        </section>

        <section class="ec-card" aria-labelledby="dnsZonesTitle">
          <div class="ec-card__head">
            <h2 class="ec-h2" id="dnsZonesTitle">Zones hébergées · <span id="dnsZoneCount">—</span></h2>
            <div class="gs-toolbar" id="dnsZoneFilters">
              <button type="button" class="ec-filter" data-zf="all" aria-pressed="true">Toutes</button>
              <button type="button" class="ec-filter" data-zf="wait" aria-pressed="false">Délégation en attente</button>
              <button type="button" class="ec-filter" data-zf="ext" aria-pressed="false">Domaines externes</button>
            </div>
          </div>
          <div class="ec-table-wrap">
            <table class="ec-table">
              <thead><tr><th>Zone</th><th>Origine</th><th>Délégation</th><th>État</th><th class="ec-num"><span class="ec-sr">Actions</span></th></tr></thead>
              <tbody id="dnsZones"><tr class="ec-table__state"><td colspan="5">Chargement des zones…</td></tr></tbody>
            </table>
          </div>
        </section>

  <script>
  (function () {
    'use strict';
    var zones = [], zf = 'all';
    var ROLE = { primary: 'Primaire', primaire: 'Primaire', master: 'Primaire', secondary: 'Secondaire', secondaire: 'Secondaire', slave: 'Secondaire' };

    function loadServers() {
      GS.get('dns_server.list').then(function (d) {
        var tb = document.getElementById('dnsServers');
        if (!d.ok) { GS.state(tb, 7, d.error || 'Serveurs DNS indisponibles.', true); return; }
        var rows = d.servers || [];
        document.getElementById('dnsSrvCount').textContent = rows.length + ' serveur' + (rows.length > 1 ? 's' : '');
        if (!rows.length) { GS.state(tb, 7, 'Aucun serveur DNS enregistré.'); return; }
        tb.innerHTML = rows.map(function (s) {
          var name = GS.pick(s, ['name', 'hostname', 'server_name', 'fqdn', 'ns']);
          var role = String(GS.pick(s, ['role'], '')).toLowerCase();
          var st = String(GS.pick(s, ['status', 'state'], '')).toLowerCase();
          var tone = GS.tone(st);
          var stLabel = tone === 'ok' ? 'En ligne' : tone === 'warn' ? 'Désynchronisé' : tone === 'danger' ? 'Hors ligne' : (st || 'Inconnu');
          return '<tr><td><span class="gs-title gs-mono">' + GS.esc(name) + '</span>' + (s.serial ? '<span class="gs-sub">Série ' + GS.esc(s.serial) + '</span>' : '') + '</td>'
            + '<td>' + GS.esc(ROLE[role] || role || '—') + '</td>'
            + '<td class="gs-mono">' + GS.esc(GS.pick(s, ['ip', 'ip_address', 'address', 'ipv4'], '—')) + '</td>'
            + '<td class="gs-mono">' + GS.esc(s.ipv6 || '—') + '</td>'
            + '<td class="ec-num">' + GS.esc(s.zones !== undefined ? s.zones : '—') + '</td>'
            + '<td>' + GS.badge(stLabel, tone) + '</td>'
            + '<td class="gs-actions"><button type="button" class="gs-link gs-link--danger" data-del="' + GS.esc(name) + '" data-id="' + GS.esc(s.id || '') + '">Supprimer</button></td></tr>';
        }).join('');
      });
    }

    function renderZones() {
      var q = ((document.getElementById('dnsSearch') || {}).value || '').trim().toLowerCase();
      var tb = document.getElementById('dnsZones');
      var rows = zones.filter(function (z) {
        if (zf === 'wait' && z.verified) return false;
        if (zf === 'ext' && z.gnl) return false;
        return !q || z.name.indexOf(q) !== -1;
      });
      if (!rows.length) { GS.state(tb, 5, 'Aucune zone ne correspond.'); return; }
      tb.innerHTML = rows.map(function (z) {
        return '<tr><td class="gs-title gs-mono">' + GS.esc(z.name) + '</td>'
          + '<td>' + (z.gnl ? 'Acheté chez GNL' : 'Externe') + '</td>'
          + '<td>' + GS.badge(z.verified ? 'Vérifiée' : 'En attente', z.verified ? 'ok' : 'warn') + '</td>'
          + '<td>' + GS.badge(z.off ? 'Désactivée' : z.active ? 'Active' : 'Inactive', z.off ? 'danger' : z.active ? 'ok' : 'off') + '</td>'
          + '<td class="gs-actions"><a class="gs-link" href="/zdns?domain=' + encodeURIComponent(z.name) + '">Ouvrir la zone</a></td></tr>';
      }).join('');
    }

    function loadZones() {
      GS.get('domain.list').then(function (d) {
        if (!d.ok) { GS.state(document.getElementById('dnsZones'), 5, d.error || 'Zones indisponibles.', true); return; }
        zones = (d.domains || []).map(function (x) {
          return { name: String(GS.pick(x, ['domain_buy_name', 'domain', 'name'])).toLowerCase(), verified: !!x.verified, gnl: !!x.gnl_domain, active: x.domain_active !== false, off: !!x.domain_off };
        }).filter(function (z) { return z.name; });
        document.getElementById('dnsZoneCount').textContent = String(zones.length);
        renderZones();
      });
    }

    document.getElementById('dnsZoneFilters').addEventListener('click', function (e) {
      var b = e.target.closest('[data-zf]');
      if (!b) return;
      zf = b.getAttribute('data-zf');
      Array.prototype.forEach.call(this.querySelectorAll('[data-zf]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      renderZones();
    });
    document.getElementById('dnsServers').addEventListener('click', function (e) {
      var b = e.target.closest('[data-del]');
      if (!b) return;
      var name = b.getAttribute('data-del');
      if (!window.confirm('Supprimer le serveur ' + name + ' ? Les zones ne lui seront plus poussées.')) return;
      GS.post('dns_server.delete', { name: name, id: b.getAttribute('data-id') }).then(function (r) {
        GS.toast(r.ok ? 'Serveur supprimé.' : (r.error || 'Suppression impossible.'), !r.ok);
        if (r.ok) loadServers();
      });
    });
    // La modale d'ajout (include/menu.php) relit la barre latérale ; on relit la page aussi.
    document.addEventListener('submit', function (e) {
      if (e.target.matches && e.target.matches('[data-dns-server-form]')) setTimeout(loadServers, 1500);
    });
    var s = document.getElementById('dnsSearch');
    if (s) s.addEventListener('input', renderZones);

    loadServers(); loadZones();
  })();
  </script>
<?php
gnl_staff_close();
