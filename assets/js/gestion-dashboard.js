/* assets/js/gestion-dashboard.js — tableau de bord (pages/dashboard.php)
   Sources : support.ticket.list, org.list, invoice.list, order.list
   (data/portail_api.php) et data/services_menu_api.php. */
(function () {
  'use strict';
  var GS = window.GS;
  var $ = function (s) { return document.querySelector(s); };
  var OPEN = ['ouvert', 'open', 'en_cours', 'in_progress', 'en_attente', ''];
  var PRIO = { urgente: 0, haute: 1, normale: 2, basse: 3 };
  var tickets = [];

  function kpi(key, value, note, tone) {
    var v = document.querySelector('[data-kpi="' + key + '"]');
    var n = document.querySelector('[data-kpi-note="' + key + '"]');
    if (v) v.textContent = value;
    if (n) {
      n.textContent = note;
      n.className = 'ec-kpi__note' + (tone ? ' ec-kpi__note--' + tone : '');
    }
  }

  function prioTone(p) { return p === 'urgente' ? 'danger' : p === 'haute' ? 'warn' : 'off'; }
  function statusTone(s) { return s === 'ouvert' ? 'danger' : (s === 'en_cours' || s === 'en_attente') ? 'warn' : s === 'resolu' ? 'ok' : 'off'; }

  function renderTickets() {
    var body = $('#dashTickets');
    var q = (($('#dashSearch') || {}).value || '').trim().toLowerCase();
    var rows = tickets.filter(function (t) {
      if (!q) return true;
      return (t.ref + ' ' + t.subject + ' ' + (t.structure || '')).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 6);
    if (!rows.length) { GS.state(body, 5, q ? 'Aucun ticket ne correspond à la recherche.' : 'Aucun ticket en attente. Bravo !'); return; }
    body.innerHTML = rows.map(function (t) {
      var urgent = t.priority === 'urgente';
      return '<tr>'
        + '<td><a class="gs-title" href="/gestion-ticket?ticket=' + encodeURIComponent(t.id) + '">' + GS.esc(t.subject) + '</a>'
        + '<span class="gs-sub gs-mono">' + GS.esc(t.ref) + '</span></td>'
        + '<td>' + GS.esc(t.structure || '—') + '</td>'
        + '<td>' + GS.badge(t.priority_label || t.priority || 'Normale', prioTone(t.priority)) + '</td>'
        + '<td>' + GS.badge(t.status_label || t.status, statusTone(t.status)) + '</td>'
        + '<td class="ec-num' + (urgent ? ' gs-urgent' : '') + '">' + GS.ago(t.created_ts ? t.created_ts * 1000 : t.created_at) + '</td>'
        + '</tr>';
    }).join('');
  }

  function loadTickets() {
    GS.get('support.ticket.list').then(function (d) {
      if (!d.ok) {
        GS.state($('#dashTickets'), 5, d.error || 'Tickets indisponibles.', true);
        kpi('tickets', '—', 'Indisponible', 'warn');
        return;
      }
      tickets = (d.tickets || []).filter(function (t) { return OPEN.indexOf(String(t.status || '').toLowerCase()) !== -1; });
      tickets.sort(function (a, b) {
        var pa = PRIO[a.priority] !== undefined ? PRIO[a.priority] : 2;
        var pb = PRIO[b.priority] !== undefined ? PRIO[b.priority] : 2;
        return pa - pb || (a.created_ts || 0) - (b.created_ts || 0);
      });
      var urgent = tickets.filter(function (t) { return t.priority === 'urgente'; }).length;
      kpi('tickets', String(tickets.length), urgent ? urgent + ' urgent' + (urgent > 1 ? 's' : '') : 'Aucun urgent', urgent ? 'warn' : 'ok');
      renderTickets();
    });
  }

  function loadOrgs() {
    GS.get('org.list').then(function (d) {
      if (!d.ok) { kpi('orgs', '—', 'Keycloak indisponible', 'warn'); return; }
      kpi('orgs', String(d.count || (d.orgs || []).length), (d.truncated ? 'Liste tronquée · ' : '') + 'Organisations de l\'Espace Client');
    });
  }

  function loadInvoices() {
    GS.get('invoice.list').then(function (d) {
      if (!d.ok) { kpi('unpaid', '—', 'Indisponible', 'warn'); return; }
      var unpaid = (d.invoices || []).filter(function (i) {
        var r = typeof i.remaining_raw === 'number' ? i.remaining_raw : parseFloat(String(i.remaining || '0').replace(/[^\d,.-]/g, '').replace(',', '.'));
        return r > 0;
      });
      var sum = unpaid.reduce(function (s, i) { return s + (typeof i.remaining_raw === 'number' ? i.remaining_raw : 0); }, 0);
      var late = unpaid.filter(function (i) { return i.due_ts && i.due_ts * 1000 < Date.now(); }).length;
      kpi('unpaid', String(unpaid.length), unpaid.length ? GS.money(sum) + (late ? ' · ' + late + ' en retard' : '') : 'Tout est réglé', late ? 'warn' : (unpaid.length ? '' : 'ok'));
    });
  }

  function loadOrders() {
    GS.get('order.list').then(function (d) {
      var ul = $('#dashOrders');
      if (!d.ok) { ul.innerHTML = '<li class="ec-muted ec-small">' + GS.esc(d.error || 'Commandes indisponibles.') + '</li>'; return; }
      var rows = (d.orders || []).slice(0, 4);
      if (!rows.length) { ul.innerHTML = '<li class="ec-muted ec-small">Aucune commande récente.</li>'; return; }
      ul.innerHTML = rows.map(function (o) {
        return '<li class="gs-alert"><div class="gs-alert__txt"><strong>' + GS.esc(o.description || o.ref) + '</strong>'
          + '<span>' + GS.esc(o.date || '') + (o.frequency_label ? ' · ' + GS.esc(o.frequency_label) : '') + '</span></div>'
          + '<span class="gs-opt__price">' + GS.esc(o.amount || '') + '</span>'
          + GS.badge(o.status_label || o.status, GS.tone(o.status)) + '</li>';
      }).join('');
    });
  }

  function loadServices() {
    var url = '/data/services_menu_api.php' + (GS.org ? '?org=' + encodeURIComponent(GS.org) : '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false }; })
      .then(function (d) {
        var ul = $('#dashAlerts');
        if (!d || !d.ok) { ul.innerHTML = '<li class="ec-muted ec-small">État des services indisponible.</li>'; kpi('services', '—', 'Indisponible', 'warn'); return; }
        var all = [];
        Object.keys(d.menus || {}).forEach(function (k) { (d.menus[k] || []).forEach(function (s) { all.push(s); }); });
        var up = all.filter(function (s) { return /^(active|actif|running|up|ok)$/i.test(String(s.status || '')); });
        var bad = all.filter(function (s) { return up.indexOf(s) === -1; });
        kpi('services', up.length + ' / ' + all.length, bad.length ? bad.length + ' à surveiller' : 'Tout est en ligne', bad.length ? 'warn' : 'ok');
        if (!bad.length) { ul.innerHTML = '<li class="ec-muted ec-small">Aucun service en alerte.</li>'; return; }
        var label = { deployment: 'Déploiement', suspended: 'Suspendu', failed: 'En échec', pending: 'En attente', stopped: 'Arrêté' };
        ul.innerHTML = bad.slice(0, 5).map(function (s) {
          var st = String(s.status || '').toLowerCase();
          return '<li class="gs-alert"><div class="gs-alert__txt"><strong>' + GS.esc(s.display_name || s.name) + '</strong>'
            + '<span>' + GS.esc(s.product_name || '') + '</span></div>'
            + GS.badge(label[st] || s.status || 'Inconnu', st === 'deployment' || st === 'pending' ? 'warn' : 'danger')
            + (s.href ? ' <a class="gs-link" href="' + GS.esc(s.href) + '">Ouvrir</a>' : '') + '</li>';
        }).join('');
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var search = $('#dashSearch');
    if (search) search.addEventListener('input', renderTickets);
    loadTickets(); loadOrgs(); loadInvoices(); loadOrders(); loadServices();
  });
})();
