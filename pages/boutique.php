<?php
/* =====================================================================
   pages/boutique.php — Articles de la boutique (maquette « Boutique »)
   ---------------------------------------------------------------------
   Actions n8n (data/portail_api.php) :
     product.list            liste des articles (+ options)
     product.visibility      interrupteur « En boutique » du tableau
     product.save            création / modification d'un article
     product.stock           si seul le stock a changé
     product.option.add      option ajoutée à un article existant
     product.option.delete   option retirée d'un article existant
   Un article masqué reste actif pour les clients qui l'ont déjà.
   ===================================================================== */
require_once __DIR__ . '/../include/staff_shell.php';

gnl_staff_open('Boutique', ['search' => 'Rechercher un article', 'searchId' => 'shopSearch']);
gnl_page_head(
    'ADMINISTRATION',
    'Boutique',
    'Articles proposés sur gnl-solution.com et dans l\'Espace Client. Un article masqué reste actif pour les clients qui l\'ont déjà.',
    '<a class="ec-btn ec-btn--ghost" href="https://gnl-solution.com/boutique" target="_blank" rel="noopener">Voir la boutique en ligne</a>'
    . '<button type="button" class="ec-btn ec-btn--primary" id="shopNew">Nouvel article</button>'
);
?>
        <div class="gs-toolbar" id="shopTabs" role="toolbar" aria-label="Filtrer les articles">
          <button type="button" class="ec-filter" data-tab="all" aria-pressed="true">Tous<span class="gs-count" data-c="all">0</span></button>
          <button type="button" class="ec-filter" data-tab="on" aria-pressed="false">En boutique<span class="gs-count" data-c="on">0</span></button>
          <button type="button" class="ec-filter" data-tab="off" aria-pressed="false">Masqués<span class="gs-count" data-c="off">0</span></button>
          <button type="button" class="ec-filter" data-tab="low" aria-pressed="false">Stock bas<span class="gs-count" data-c="low">0</span></button>
        </div>

        <div class="gs-split gs-split--even">
          <section class="ec-card">
            <div class="ec-table-wrap">
              <table class="ec-table">
                <thead><tr><th>Article</th><th class="ec-num">Prix</th><th>Stock</th><th>En boutique</th></tr></thead>
                <tbody id="shopBody"><tr class="ec-table__state"><td colspan="4">Chargement des articles…</td></tr></tbody>
              </table>
            </div>
          </section>

          <form class="ec-card" id="shopForm" novalidate hidden>
            <div class="ec-card__head"><h2 class="ec-h2" id="shopFormTitle">Article</h2><span id="shopFormBadge"></span></div>
            <div class="ec-card__body gs-grid">
              <label class="gs-field gs-span2">Nom *<input name="name" required maxlength="160"></label>
              <label class="gs-field gs-span2">Sous-titre<input name="subtitle" maxlength="200"></label>
              <label class="gs-field">Slug<input name="slug" maxlength="80" pattern="[a-z0-9][a-z0-9-]*" class="gs-mono"></label>
              <label class="gs-field">Catégorie
                <select name="category">
                  <option>Sites web</option><option>Cloud</option><option>Serveurs</option><option>Domaines et DNS</option><option>Le Laboratoire</option><option>Services</option>
                </select>
              </label>
              <label class="gs-field">Prix (€ HT)<input name="price" inputmode="decimal" maxlength="12"></label>
              <label class="gs-field">Facturation
                <select name="billing"><option value="mensuel">Mensuelle</option><option value="annuel">Annuelle</option><option value="unique">Unique</option></select>
              </label>
              <label class="gs-field gs-span2">Description<textarea name="description" rows="3" maxlength="2000"></textarea></label>

              <fieldset class="gs-field gs-span2"><legend>Stock</legend>
                <label class="gs-check"><input type="checkbox" name="stock_limited"> <span>Stock limité</span></label>
                <div class="gs-grid" data-stock style="margin-top:10px">
                  <div class="gs-field">Places restantes
                    <div class="gs-stepper"><button type="button" data-step="-1" aria-label="Retirer une place">−</button><input name="stock" inputmode="numeric" aria-label="Places restantes"><button type="button" data-step="1" aria-label="Ajouter une place">+</button></div>
                  </div>
                  <label class="gs-field">Alerte sous<input name="stock_alert" inputmode="numeric" maxlength="5"></label>
                  <p class="gs-help gs-span2" data-stock-help></p>
                </div>
              </fieldset>

              <fieldset class="gs-field gs-span2"><legend>Options</legend>
                <ul class="gs-opts" id="shopOpts"></ul>
                <button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" id="shopOptOpen" style="align-self:flex-start;margin-top:8px">Ajouter une option</button>
                <div class="gs-optform" id="shopOptForm" hidden>
                  <p class="ec-kicker" style="margin:0 0 10px">NOUVELLE OPTION</p>
                  <div class="gs-grid">
                    <label class="gs-field gs-span2">Nom *<input data-o="name" maxlength="120"></label>
                    <label class="gs-field">Prix (€)<input data-o="price" inputmode="decimal" maxlength="10" value="0"></label>
                    <label class="gs-field">Facturation
                      <select data-o="billing"><option value="mensuel">Mensuelle</option><option value="unique">Unique</option><option value="inclus">Incluse</option></select>
                    </label>
                    <p class="gs-help gs-span2" data-o-help></p>
                    <label class="gs-field gs-span2">Description<input data-o="description" maxlength="400"></label>
                    <label class="gs-check gs-span2"><input type="checkbox" data-o="default_checked"> <span>Cochée par défaut dans le panier</span></label>
                  </div>
                  <div class="gs-actions" style="margin-top:12px">
                    <button type="button" class="ec-btn ec-btn--ghost ec-btn--sm" id="shopOptCancel">Annuler</button>
                    <button type="button" class="ec-btn ec-btn--primary ec-btn--sm" id="shopOptAdd">Ajouter l'option</button>
                  </div>
                </div>
              </fieldset>

              <label class="gs-switch gs-span2"><input type="checkbox" name="store_showable"><span class="gs-switch__track"></span><span>Visible en boutique</span></label>
            </div>
            <div class="gs-dialog__foot">
              <button type="button" class="ec-btn ec-btn--ghost" id="shopCancel">Annuler</button>
              <button type="submit" class="ec-btn ec-btn--primary">Enregistrer</button>
            </div>
          </form>
        </div>

  <script>
  (function () {
    'use strict';
    var BILL = { mensuel: '/ mois', annuel: '/ an', unique: '' };
    var OPT_HELP = {
      mensuel: 'Ajoutée au montant de l’abonnement à chaque échéance.',
      unique: 'Facturée une seule fois, à la première commande.',
      inclus: 'Comprise dans le prix de l’article, affichée à titre d’information.'
    };
    var products = [], tab = 'all', cur = null, opts = [], removed = [], snapshot = '';
    var form = document.getElementById('shopForm');
    var F = function (n) { return form.elements[n]; };

    function low(p) { return p.stock_limited && p.stock !== null && p.stock <= p.stock_alert; }
    function stockCell(p) {
      if (!p.stock_limited) return '<span class="ec-muted">Illimité</span>';
      var max = Math.max(p.stock, p.stock_alert * 3, 1);
      var tone = p.stock === 0 ? 'danger' : low(p) ? 'warn' : '';
      return '<strong>' + (p.stock === 0 ? 'Complet' : p.stock + ' place' + (p.stock > 1 ? 's' : '')) + '</strong>'
        + '<div class="gs-bar' + (tone ? ' gs-bar--' + tone : '') + '"><i style="width:' + Math.round(100 * p.stock / max) + '%"></i></div>';
    }

    function counts() {
      var c = { all: products.length, on: 0, off: 0, low: 0 };
      products.forEach(function (p) { c[p.store_showable ? 'on' : 'off']++; if (low(p)) c.low++; });
      Array.prototype.forEach.call(document.querySelectorAll('[data-c]'), function (el) { el.textContent = String(c[el.getAttribute('data-c')]); });
    }

    function render() {
      var q = ((document.getElementById('shopSearch') || {}).value || '').trim().toLowerCase();
      var tb = document.getElementById('shopBody');
      var rows = products.filter(function (p) {
        if (tab === 'on' && !p.store_showable) return false;
        if (tab === 'off' && p.store_showable) return false;
        if (tab === 'low' && !low(p)) return false;
        return !q || (p.name + ' ' + p.slug + ' ' + p.category).toLowerCase().indexOf(q) !== -1;
      });
      if (!rows.length) { GS.state(tb, 4, q ? 'Aucun article ne correspond.' : 'Aucun article dans ce filtre.'); return; }
      tb.innerHTML = rows.map(function (p) {
        return '<tr data-sel="' + GS.esc(p.id) + '"' + (cur && cur.id === p.id ? ' class="is-selected"' : '') + '>'
          + '<td><button type="button" class="gs-link" style="color:inherit;text-align:left" data-edit="' + GS.esc(p.id) + '">' + GS.esc(p.name) + '</button>'
          + '<span class="gs-sub">' + GS.esc(p.category || '—') + (p.options.length ? ' · ' + p.options.length + ' option' + (p.options.length > 1 ? 's' : '') : '') + '</span></td>'
          + '<td class="ec-num"><strong>' + GS.money(p.price) + '</strong> <span class="ec-muted ec-small">' + (BILL[p.billing] || '') + '</span></td>'
          + '<td>' + stockCell(p) + '</td>'
          + '<td><label class="gs-switch"><input type="checkbox" data-vis="' + GS.esc(p.id) + '"' + (p.store_showable ? ' checked' : '') + '><span class="gs-switch__track"></span>'
          + '<span class="ec-small">' + (p.store_showable ? 'Visible' : 'Masqué') + '</span></label></td></tr>';
      }).join('');
    }

    function renderOpts() {
      var ul = document.getElementById('shopOpts');
      var list = opts.filter(function (o) { return removed.indexOf(o) === -1; });
      if (!list.length) { ul.innerHTML = '<li class="ec-muted ec-small">Aucune option pour cet article.</li>'; return; }
      ul.innerHTML = list.map(function (o, i) {
        var price = o.billing === 'inclus' ? 'Incluse' : '+ ' + GS.money(o.price) + (o.billing === 'mensuel' ? ' / mois' : '');
        return '<li class="gs-opt' + (o.isNew ? ' is-new' : '') + '"><div class="gs-opt__txt"><strong>' + GS.esc(o.name) + (o.default_checked ? ' · cochée par défaut' : '') + '</strong>'
          + '<span>' + GS.esc(o.description || (o.isNew ? 'Ajoutée — sera enregistrée avec l’article' : OPT_HELP[o.billing] || '')) + '</span></div>'
          + '<span class="gs-opt__price">' + GS.esc(price) + '</span>'
          + '<button type="button" class="gs-link gs-link--danger" data-optdel="' + opts.indexOf(o) + '">Retirer</button></li>';
      }).join('');
    }

    function stockUi() {
      var on = F('stock_limited').checked;
      form.querySelector('[data-stock]').hidden = !on;
      var a = parseInt(F('stock_alert').value, 10) || 0;
      form.querySelector('[data-stock-help]').textContent = 'À 0, l’article affiche « Complet » et ne peut plus être commandé. Sous ' + a + ' place' + (a > 1 ? 's' : '') + ', la boutique affiche « Plus que N places ».';
    }

    function serialize(fields) { return JSON.stringify(fields.map(function (n) { var e = F(n); return e.type === 'checkbox' ? e.checked : e.value; })); }
    var MAIN = ['name', 'subtitle', 'slug', 'category', 'price', 'billing', 'description', 'store_showable'];
    var STOCK = ['stock_limited', 'stock', 'stock_alert'];

    function edit(p) {
      cur = p;
      form.hidden = false;
      document.getElementById('shopFormTitle').textContent = p.id ? p.name : 'Nouvel article';
      document.getElementById('shopFormBadge').innerHTML = p.id ? GS.badge(p.store_showable ? 'En boutique' : 'Masqué', p.store_showable ? 'ok' : 'off') : GS.badge('Brouillon', 'warn');
      F('name').value = p.name || ''; F('subtitle').value = p.subtitle || ''; F('slug').value = p.slug || '';
      F('category').value = p.category || 'Sites web';
      if (F('category').value !== (p.category || 'Sites web') && p.category) { var o = new Option(p.category, p.category, true, true); F('category').add(o); }
      F('price').value = p.id ? String(p.price).replace('.', ',') : '';
      F('billing').value = p.billing || 'mensuel'; F('description').value = p.description || '';
      F('stock_limited').checked = !!p.stock_limited; F('stock').value = p.stock === null || p.stock === undefined ? 0 : p.stock;
      F('stock_alert').value = p.stock_alert === undefined ? 5 : p.stock_alert;
      F('store_showable').checked = !!p.store_showable;
      opts = (p.options || []).map(function (o) { return Object.assign({}, o); });
      removed = [];
      document.getElementById('shopOptForm').hidden = true;
      snapshot = serialize(MAIN) + '|' + serialize(STOCK);
      stockUi(); renderOpts(); render();
      if (window.innerWidth < 1180) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function load(selectId) {
      GS.get('product.list').then(function (d) {
        if (!d.ok) { GS.state(document.getElementById('shopBody'), 4, d.error || 'Catalogue indisponible.', true); return; }
        products = d.products || [];
        counts(); render();
        var p = products.filter(function (x) { return x.id === selectId; })[0] || (cur && products.filter(function (x) { return x.id === cur.id; })[0]) || products[0];
        if (p) edit(p);
      });
    }

    // Tableau : filtres, sélection, interrupteur de visibilité.
    document.getElementById('shopTabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]');
      if (!b) return;
      tab = b.getAttribute('data-tab');
      Array.prototype.forEach.call(this.querySelectorAll('[data-tab]'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      render();
    });
    document.getElementById('shopBody').addEventListener('click', function (e) {
      if (e.target.closest('.gs-switch')) return;
      var tr = e.target.closest('[data-sel]');
      if (!tr) return;
      var p = products.filter(function (x) { return x.id === tr.getAttribute('data-sel'); })[0];
      if (p) edit(p);
    });
    document.getElementById('shopBody').addEventListener('change', function (e) {
      var id = e.target.getAttribute('data-vis');
      if (!id) return;
      var p = products.filter(function (x) { return x.id === id; })[0];
      var on = e.target.checked;
      e.target.disabled = true;
      GS.post('product.visibility', { id: id, store_showable: on }).then(function (r) {
        e.target.disabled = false;
        if (!r.ok) { e.target.checked = !on; GS.toast(r.error || 'Modification impossible.', true); return; }
        p.store_showable = on;
        if (cur && cur.id === id) { F('store_showable').checked = on; snapshot = serialize(MAIN) + '|' + serialize(STOCK); }
        counts(); render();
        GS.toast(on ? p.name + ' est visible en boutique.' : p.name + ' est masqué de la boutique.');
      });
    });

    // Formulaire.
    document.getElementById('shopNew').addEventListener('click', function () {
      edit({ id: '', name: '', billing: 'mensuel', stock_limited: false, stock: null, stock_alert: 5, store_showable: false, options: [] });
      F('name').focus();
    });
    document.getElementById('shopCancel').addEventListener('click', function () { if (cur && cur.id) edit(cur); else { form.hidden = true; cur = null; render(); } });
    F('name').addEventListener('input', function () {
      if (!cur || !cur.id) F('slug').value = F('name').value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
    });
    form.addEventListener('change', function (e) { if (STOCK.indexOf(e.target.name) !== -1) stockUi(); });
    F('stock_alert').addEventListener('input', stockUi);
    form.addEventListener('click', function (e) {
      var s = e.target.closest('[data-step]');
      if (s) { F('stock').value = Math.max(0, (parseInt(F('stock').value, 10) || 0) + parseInt(s.getAttribute('data-step'), 10)); return; }
      var d = e.target.closest('[data-optdel]');
      if (d) {
        var o = opts[+d.getAttribute('data-optdel')];
        if (o.isNew) opts.splice(opts.indexOf(o), 1); else removed.push(o);
        renderOpts();
      }
    });

    // Options.
    var of = document.getElementById('shopOptForm');
    var O = function (n) { return of.querySelector('[data-o="' + n + '"]'); };
    function optHelp() {
      of.querySelector('[data-o-help]').textContent = OPT_HELP[O('billing').value];
      O('price').disabled = O('billing').value === 'inclus';
    }
    O('billing').addEventListener('change', optHelp);
    document.getElementById('shopOptOpen').addEventListener('click', function () {
      of.hidden = false;
      ['name', 'description'].forEach(function (n) { O(n).value = ''; });
      O('price').value = '0'; O('billing').value = 'mensuel'; O('default_checked').checked = false;
      optHelp(); O('name').focus();
    });
    document.getElementById('shopOptCancel').addEventListener('click', function () { of.hidden = true; });
    document.getElementById('shopOptAdd').addEventListener('click', function () {
      var name = O('name').value.trim();
      if (!name) { GS.toast('Donnez un nom à l’option.', true); O('name').focus(); return; }
      var billing = O('billing').value;
      opts.push({
        id: '', isNew: true, name: name,
        slug: name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''),
        price: billing === 'inclus' ? 0 : parseFloat(O('price').value.replace(',', '.')) || 0,
        billing: billing, description: O('description').value.trim(), default_checked: O('default_checked').checked
      });
      of.hidden = true;
      renderOpts();
    });

    // Enregistrement.
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var f = GS.formData(form);
      if (!String(f.name || '').trim()) { GS.toast('Le nom est obligatoire.', true); F('name').focus(); return; }
      var keep = opts.filter(function (o) { return removed.indexOf(o) === -1; });
      var added = keep.filter(function (o) { return o.isNew; });
      var dropped = removed.filter(function (o) { return o.id; });
      var mainDirty = serialize(MAIN) !== snapshot.split('|')[0];
      var stockDirty = serialize(STOCK) !== snapshot.split('|')[1];
      var btn = form.querySelector('[type=submit]');
      btn.disabled = true;

      var id = cur.id, chain;
      if (!id || mainDirty) {
        f.id = id;
        f.options = keep.map(function (o) { return { id: o.id, name: o.name, slug: o.slug, price: o.price, billing: o.billing, description: o.description, default_checked: o.default_checked }; });
        chain = GS.post('product.save', f).then(function (r) { if (!r.ok) throw r; id = (r.product && r.product.id) || id; return !cur.id; });
      } else if (stockDirty) {
        chain = GS.post('product.stock', { id: id, stock_limited: f.stock_limited, stock: f.stock, stock_alert: f.stock_alert }).then(function (r) { if (!r.ok) throw r; return false; });
      } else chain = Promise.resolve(false);

      chain.then(function (created) {
        // Article existant : options ajoutées / retirées une par une.
        if (created) return;
        var jobs = added.map(function (o) { return GS.post('product.option.add', { product_id: id, name: o.name, slug: o.slug, price: o.price, billing: o.billing, description: o.description, default_checked: o.default_checked }); })
          .concat(dropped.map(function (o) { return GS.post('product.option.delete', { product_id: id, option_id: o.id }); }));
        return Promise.all(jobs).then(function (rs) { var bad = rs.filter(function (r) { return !r.ok; })[0]; if (bad) throw bad; });
      }).then(function () {
        btn.disabled = false;
        GS.toast('Article enregistré.');
        load(id);
      }).catch(function (r) {
        btn.disabled = false;
        GS.toast((r && r.error) || 'Enregistrement impossible.', true);
      });
    });

    var s = document.getElementById('shopSearch');
    if (s) s.addEventListener('input', render);
    load();
  })();
  </script>
<?php
gnl_staff_close();
