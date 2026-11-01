/* =====================================================================
   assets/js/gnl-staff.js — utilitaires communs du Portail Gestion
   ---------------------------------------------------------------------
   GS.get(action, params)  → GET  data/portail_api.php?action=…
   GS.post(action, data)   → POST (x-www-form-urlencoded + X-CSRF-Token)
   GS.esc / GS.money / GS.date / GS.ago / GS.badge / GS.toast
   Modales : [data-gs-open="<id>"] ouvre, [data-gs-close] ferme, Échap.
   Modale « Planifier une maintenance » (include/maintenance_modal.php).
   ===================================================================== */
(function () {
  'use strict';
  var API = '/data/portail_api.php';

  function qs(params) {
    var out = [];
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v === undefined || v === null || v === '') return;
      out.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(v)));
    });
    return out.join('&');
  }

  function parse(r) {
    return r.text().then(function (t) {
      var d;
      try { d = t ? JSON.parse(t) : {}; } catch (e) { d = { ok: false, error: 'Réponse illisible (HTTP ' + r.status + ').' }; }
      if (!r.ok && d.ok !== false) d.ok = false;
      if (d.ok === false && !d.error) d.error = 'Erreur HTTP ' + r.status;
      return d;
    });
  }

  function orgParam() {
    try { return new URL(window.location.href).searchParams.get('org') || ''; } catch (e) { return ''; }
  }

  var GS = {
    org: orgParam(),
    get: function (action, params) {
      var p = Object.assign({ action: action }, params || {});
      if (GS.org && !p.org) p.org = GS.org;
      return fetch(API + '?' + qs(p), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(parse)
        .catch(function () { return { ok: false, error: 'Réseau indisponible.' }; });
    },
    post: function (action, data) {
      var body = new URLSearchParams();
      Object.keys(data || {}).forEach(function (k) {
        var v = data[k];
        if (v === undefined || v === null) return;
        if (typeof v === 'object') v = JSON.stringify(v);
        if (typeof v === 'boolean') v = v ? '1' : '0';
        body.append(k, String(v));
      });
      return fetch(API + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': window.GNL_CSRF || window.NOTIF_CSRF || '' },
        body: body
      }).then(parse).catch(function () { return { ok: false, error: 'Réseau indisponible.' }; });
    },
    esc: function (s) {
      return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },
    pick: function (o, keys, dflt) {
      for (var i = 0; i < keys.length; i++) {
        var v = o ? o[keys[i]] : undefined;
        if (v !== undefined && v !== null && v !== '') return v;
      }
      return dflt === undefined ? '' : dflt;
    },
    money: function (v) {
      var n = typeof v === 'number' ? v : parseFloat(String(v || '').replace(',', '.'));
      if (!isFinite(n)) return '—';
      return n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
    },
    date: function (v, withTime) {
      if (!v) return '—';
      var d = new Date(v);
      if (isNaN(d)) return GS.esc(v);
      var o = { day: '2-digit', month: 'short', year: 'numeric' };
      if (withTime) { o.hour = '2-digit'; o.minute = '2-digit'; }
      return d.toLocaleDateString('fr-FR', o);
    },
    ago: function (v) {
      if (!v) return '—';
      var d = new Date(v);
      if (isNaN(d)) return '—';
      var m = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
      if (m < 60) return m + ' min';
      var h = Math.round(m / 60);
      if (h < 24) return h + ' h';
      return Math.round(h / 24) + ' j';
    },
    /** Pastille d'état : tone = ok | warn | danger | off */
    badge: function (label, tone) {
      var cls = tone === 'ok' ? ' ec-badge--ok' : tone === 'warn' ? ' ec-badge--warn' : tone === 'danger' ? ' ec-badge--danger' : '';
      return '<span class="ec-badge' + cls + '">' + GS.esc(label) + '</span>';
    },
    /** Ton d'un état technique quelconque (running, up, down, degraded…). */
    tone: function (state) {
      var s = String(state || '').toLowerCase();
      if (/^(up|ok|running|ready|online|healthy|en ligne|actif|active|available|resolu|résolu|paid|payee|payée)$/.test(s)) return 'ok';
      if (/(down|error|fail|crash|offline|ko|incident|urgent|unpaid|impay|retard|expired)/.test(s)) return 'danger';
      if (/(warn|degrad|pending|progress|deploy|attente|en_cours|haute|restart|partial)/.test(s)) return 'warn';
      return 'off';
    },
    toast: function (msg, isError) {
      var el = document.getElementById('gsToast');
      if (!el) { if (isError) console.error(msg); return; }
      el.textContent = msg;
      el.className = 'gs-toast' + (isError ? ' gs-toast--error' : '');
      el.hidden = false;
      clearTimeout(GS._tt);
      GS._tt = setTimeout(function () { el.hidden = true; }, 4200);
    },
    state: function (tbody, cols, text, isError) {
      if (!tbody) return;
      tbody.innerHTML = '<tr class="ec-table__state' + (isError ? ' ec-table__state--error' : '') + '"><td colspan="' + cols + '">' + GS.esc(text) + '</td></tr>';
    },
    openModal: function (id) {
      var m = document.getElementById(id);
      if (!m) return;
      m.hidden = false;
      m.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      var f = m.querySelector('input:not([type=hidden]),select,textarea');
      if (f) setTimeout(function () { f.focus(); }, 30);
      m.dispatchEvent(new CustomEvent('gs:open'));
    },
    closeModal: function (m) {
      if (typeof m === 'string') m = document.getElementById(m);
      if (!m) return;
      m.classList.remove('is-open');
      m.hidden = true;
      document.body.style.overflow = '';
    },
    /** Lit un <form> en objet (cases à cocher → booléens, multiples → tableaux). */
    formData: function (form) {
      var out = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.disabled) return;
        if (el.type === 'checkbox') {
          if (form.querySelectorAll('[name="' + el.name + '"]').length > 1 || el.hasAttribute('data-multi')) {
            out[el.name] = out[el.name] || [];
            if (el.checked) out[el.name].push(el.value);
          } else out[el.name] = el.checked;
        } else if (el.type === 'radio') {
          if (el.checked) out[el.name] = el.value;
        } else out[el.name] = el.value;
      });
      return out;
    }
  };

  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-gs-open]');
    if (o) { e.preventDefault(); GS.openModal(o.getAttribute('data-gs-open')); return; }
    var c = e.target.closest('[data-gs-close]');
    if (c) { e.preventDefault(); GS.closeModal(c.closest('.gs-modal')); return; }
    if (e.target.classList && e.target.classList.contains('gs-modal')) GS.closeModal(e.target);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.gs-modal.is-open');
    if (open) GS.closeModal(open);
  });

  // ── Modale « Planifier une maintenance » ──────────────────────────────
  // → maintenance.create { title, type, services[], starts_at, ends_at,
  //   impact, message, notify }
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('maintForm');
    if (!form) return;
    var modal = document.getElementById('maintModal');
    var list = document.getElementById('maintServices');
    var preview = document.getElementById('maintPreview');
    var reach = document.getElementById('maintReach');
    var loaded = false;

    function day(d) { return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }); }
    function hour(d) { return d.getHours() + ' h' + (d.getMinutes() ? String(d.getMinutes()).padStart(2, '0') : ''); }
    function refresh() {
      var f = GS.formData(form);
      var s = new Date(f.starts_at), e = new Date(f.ends_at), when = '';
      if (!isNaN(s)) {
        if (isNaN(e)) when = ' le ' + day(s) + ' à ' + hour(s);
        else if (day(s) === day(e)) when = ' le ' + day(s) + ', de ' + hour(s) + ' à ' + hour(e);
        else when = ' du ' + day(s) + ', ' + hour(s) + ' au ' + day(e) + ', ' + hour(e);
      }
      preview.textContent = (f.type === 'urgente' ? 'Maintenance urgente' : 'Maintenance planifiée') + when + '. ' + (f.message || '');
      var n = (f.services || []).length;
      reach.textContent = n ? (n + ' service' + (n > 1 ? 's' : '') + ' sélectionné' + (n > 1 ? 's' : '') + '.') : 'Aucun service sélectionné : la maintenance concerne toute la plateforme.';
    }
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);

    modal.addEventListener('gs:open', function () {
      refresh();
      if (loaded) return;
      loaded = true;
      GS.get('deployment.list').then(function (d) {
        var rows = (d && d.ok && Array.isArray(d.deployments)) ? d.deployments : [];
        if (!rows.length) { list.innerHTML = '<p class="ec-muted ec-small">Aucun service listé : la maintenance s’appliquera à toute la plateforme.</p>'; return; }
        list.innerHTML = rows.map(function (r) {
          var id = GS.pick(r, ['deployment_name', 'name']);
          var label = GS.pick(r, ['display_name', 'deployment_name', 'name']);
          return '<label class="gs-check"><input type="checkbox" name="services" data-multi value="' + GS.esc(id) + '"> <span>' + GS.esc(label) + '</span></label>';
        }).join('');
      });
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var f = GS.formData(form);
      if (!String(f.title || '').trim() || !f.starts_at || !f.ends_at) { GS.toast('Titre, début et fin sont obligatoires.', true); return; }
      if (new Date(f.ends_at) <= new Date(f.starts_at)) { GS.toast('La fin doit être après le début.', true); return; }
      // datetime-local n'a pas de fuseau : on envoie l'instant exact (ISO UTC).
      f.starts_at = new Date(f.starts_at).toISOString();
      f.ends_at = new Date(f.ends_at).toISOString();
      var btn = form.querySelector('[type=submit]');
      btn.disabled = true;
      GS.post('maintenance.create', f).then(function (d) {
        btn.disabled = false;
        if (!d.ok) { GS.toast(d.error || 'Planification impossible.', true); return; }
        GS.closeModal(modal);
        form.reset();
        GS.toast('Maintenance planifiée' + (f.notify ? ' — clients prévenus.' : '.'));
        document.dispatchEvent(new CustomEvent('gs:maintenance-created', { detail: d }));
      });
    });
  });

  window.GS = GS;
})();
