<?php
/* =====================================================================
   pages/documentation.php — Rédaction de la documentation
   (maquette « Documentation » du Portail Gestion)
   ---------------------------------------------------------------------
   Alimente la page Documentation de l'Espace Client :
     documentation.list    articles (brouillons compris : include_drafts=1)
     documentation.save    création / modification (status draft|published)
     documentation.publish publier / repasser en brouillon
     documentation.delete  suppression
   Le HTML est nettoyé côté serveur (gs_clean_html) : intertitres, gras,
   italique, listes, étapes, code, liens, images.
   ===================================================================== */
require_once __DIR__ . '/../include/staff_shell.php';

gnl_staff_open('Documentation', ['search' => 'Rechercher un article', 'searchId' => 'docSearch']);
gnl_page_head(
    'SUPPORT',
    'Documentation',
    'Rédigez les articles de la base de connaissance. Un article publié apparaît dans la page Documentation de l\'Espace Client.',
    '<a class="ec-btn ec-btn--ghost" href="https://b2b-portal.eu.gnl-solution.com/documentation" target="_blank" rel="noopener">Voir la page côté client</a>'
    . '<button type="button" class="ec-btn ec-btn--primary" id="docNew">Nouvel article</button>'
);
?>
        <div class="gs-split">
          <section class="ec-card" aria-label="Articles">
            <div class="ec-card__head">
              <label class="gs-field" style="flex:1;min-width:180px">
                <span class="ec-sr">Catégorie</span>
                <select id="docCat"><option value="">Toutes les catégories</option></select>
              </label>
              <span class="ec-muted ec-small" id="docCount"></span>
            </div>
            <ul class="ec-list" id="docList"><li class="ec-muted ec-small">Chargement des articles…</li></ul>
          </section>

          <form class="ec-card" id="docForm" novalidate>
            <div class="ec-card__head">
              <div class="gs-seg" role="group" aria-label="Mode">
                <label><input type="radio" name="mode" value="edit" checked><span>Rédaction</span></label>
                <label><input type="radio" name="mode" value="preview"><span>Aperçu client</span></label>
              </div>
              <span id="docBadge"></span>
            </div>

            <div class="ec-card__body gs-grid" data-pane="edit">
              <label class="gs-field gs-span2">Titre *<input name="title" required maxlength="200"></label>
              <label class="gs-field">Catégorie
                <input name="category" list="docCats" maxlength="80" placeholder="Démarrer">
                <small>Devient un onglet de la page client.</small>
              </label>
              <label class="gs-field">Temps de lecture (min)
                <input name="read_time" inputmode="numeric" maxlength="3">
                <small>Calculé automatiquement, modifiable.</small>
              </label>
              <label class="gs-field gs-span2">Résumé<textarea name="summary" rows="2" maxlength="400"></textarea></label>
              <div class="gs-field gs-span2">
                <span id="docContentLabel">Contenu</span>
                <div class="gs-editor__bar" role="toolbar" aria-label="Mise en forme">
                  <button type="button" data-cmd="h2" title="Intertitre">H2</button>
                  <button type="button" data-cmd="bold" title="Gras"><b>B</b></button>
                  <button type="button" data-cmd="italic" title="Italique"><i>I</i></button>
                  <button type="button" data-cmd="ul" title="Liste">• Liste</button>
                  <button type="button" data-cmd="ol" title="Étapes numérotées">1. Étapes</button>
                  <button type="button" data-cmd="code" title="Code">&lt;/&gt;</button>
                  <button type="button" data-cmd="link" title="Lien">Lien</button>
                  <button type="button" data-cmd="p" title="Paragraphe">¶</button>
                </div>
                <div class="gs-editor__area" id="docEditor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="docContentLabel"></div>
                <small>Balises conservées : intertitres, gras, italique, listes, étapes, code, liens, images. Le reste est retiré à l'enregistrement.</small>
              </div>
            </div>

            <div class="ec-card__body" data-pane="preview" hidden>
              <p class="ec-kicker">TEL QUE LE CLIENT LE VERRA</p>
              <article class="gs-doc" id="docPreview"></article>
              <p class="gs-doc__feedback">Cet article vous a-t-il aidé ? <strong>Oui · Non</strong> — Toujours bloqué ? <strong>Ouvrir un ticket</strong></p>
            </div>

            <div class="gs-dialog__foot">
              <button type="button" class="ec-btn ec-btn--ghost" id="docDelete" style="margin-right:auto;color:var(--gnl-danger)">Supprimer</button>
              <button type="button" class="ec-btn ec-btn--ghost" id="docDraft">Enregistrer le brouillon</button>
              <button type="button" class="ec-btn ec-btn--primary" id="docPublish">Publier</button>
            </div>
          </form>
        </div>
        <datalist id="docCats"></datalist>

  <script>
  (function () {
    'use strict';
    var articles = [], cur = null;
    var form = document.getElementById('docForm');
    var ed = document.getElementById('docEditor');
    var F = function (n) { return form.elements[n]; };

    function readTime() {
      var words = (ed.innerText || '').trim().split(/\s+/).filter(Boolean).length;
      return Math.max(1, Math.ceil(words / 200));
    }

    function renderList() {
      var q = ((document.getElementById('docSearch') || {}).value || '').trim().toLowerCase();
      var cat = document.getElementById('docCat').value;
      var rows = articles.filter(function (a) {
        if (cat && a.category !== cat) return false;
        return !q || (a.title + ' ' + a.summary + ' ' + a.category).toLowerCase().indexOf(q) !== -1;
      });
      document.getElementById('docCount').textContent = rows.length + ' article' + (rows.length > 1 ? 's' : '');
      var ul = document.getElementById('docList');
      if (!rows.length) { ul.innerHTML = '<li class="ec-muted ec-small">Aucun article.</li>'; return; }
      ul.innerHTML = rows.map(function (a) {
        var on = cur && String(cur.id) === String(a.id);
        return '<li class="gs-alert" style="cursor:pointer' + (on ? ';background:var(--gnl-ok-bg)' : '') + '" data-id="' + GS.esc(a.id) + '" tabindex="0">'
          + '<div class="gs-alert__txt"><strong>' + GS.esc(a.title) + '</strong><span>' + GS.esc(a.category) + ' · ' + GS.esc(a.updated_at || '') + '</span></div>'
          + GS.badge(a.status === 'draft' ? 'Brouillon' : 'Publié', a.status === 'draft' ? 'warn' : 'ok') + '</li>';
      }).join('');
    }

    function fillCats() {
      var cats = [];
      articles.forEach(function (a) { if (a.category && cats.indexOf(a.category) === -1) cats.push(a.category); });
      ['Démarrer', 'Sites web', 'Domaines et DNS', 'Facturation', 'Sécurité', 'Le Laboratoire'].forEach(function (c) { if (cats.indexOf(c) === -1) cats.push(c); });
      cats.sort(function (a, b) { return a.localeCompare(b, 'fr'); });
      var sel = document.getElementById('docCat'), v = sel.value;
      sel.innerHTML = '<option value="">Toutes les catégories</option>' + cats.map(function (c) { return '<option>' + GS.esc(c) + '</option>'; }).join('');
      sel.value = v;
      document.getElementById('docCats').innerHTML = cats.map(function (c) { return '<option value="' + GS.esc(c) + '">'; }).join('');
    }

    function open(a) {
      cur = a;
      F('title').value = a.title || ''; F('category').value = a.category || '';
      F('summary').value = a.summary || '';
      ed.innerHTML = a.content_raw || a.content_html || GS.esc(a.content || '');
      F('read_time').value = a.read_time || (a.id ? readTime() : '');
      document.getElementById('docBadge').innerHTML = !a.id ? GS.badge('Nouveau', 'warn') : GS.badge(a.status === 'draft' ? 'Brouillon' : 'Publié', a.status === 'draft' ? 'warn' : 'ok');
      document.getElementById('docPublish').textContent = a.id && a.status !== 'draft' ? 'Mettre à jour' : 'Publier';
      document.getElementById('docDelete').hidden = !a.id;
      setMode('edit');
      renderList();
    }

    function setMode(m) {
      form.querySelector('[value="' + m + '"]').checked = true;
      form.querySelector('[data-pane="edit"]').hidden = m !== 'edit';
      form.querySelector('[data-pane="preview"]').hidden = m !== 'preview';
      if (m === 'preview') {
        document.getElementById('docPreview').innerHTML = '<p class="ec-kicker" style="color:var(--muted-foreground)">' + GS.esc(F('category').value || 'Général') + ' · ' + GS.esc(F('read_time').value || readTime()) + ' min</p>'
          + '<h2 style="font-size:24px;margin-top:4px">' + GS.esc(F('title').value || 'Sans titre') + '</h2>'
          + (F('summary').value ? '<p class="ec-muted">' + GS.esc(F('summary').value) + '</p>' : '')
          + ed.innerHTML;
      }
    }
    form.addEventListener('change', function (e) { if (e.target.name === 'mode') setMode(e.target.value); });

    // Barre d'outils (execCommand reste la voie la plus simple pour un contenteditable).
    form.querySelector('.gs-editor__bar').addEventListener('mousedown', function (e) { if (e.target.closest('button')) e.preventDefault(); });
    form.querySelector('.gs-editor__bar').addEventListener('click', function (e) {
      var b = e.target.closest('[data-cmd]');
      if (!b) return;
      ed.focus();
      var c = b.getAttribute('data-cmd');
      if (c === 'h2' || c === 'p') document.execCommand('formatBlock', false, c === 'h2' ? 'h2' : 'p');
      else if (c === 'bold' || c === 'italic') document.execCommand(c);
      else if (c === 'ul') document.execCommand('insertUnorderedList');
      else if (c === 'ol') document.execCommand('insertOrderedList');
      else if (c === 'code') {
        var t = String(window.getSelection() || '');
        document.execCommand('insertHTML', false, '<code>' + GS.esc(t || 'code') + '</code>');
      } else if (c === 'link') {
        var url = window.prompt('Adresse du lien (https://… ou /page)');
        if (url && /^(https?:\/\/|\/|mailto:)/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
      }
    });
    ed.addEventListener('input', function () { if (!F('read_time').dataset.touched) F('read_time').value = readTime(); });
    F('read_time').addEventListener('input', function () { this.dataset.touched = '1'; });
    // Collage : texte brut, pour ne pas importer la mise en forme d'ailleurs.
    ed.addEventListener('paste', function (e) {
      e.preventDefault();
      document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
    });

    function save(status) {
      if (!F('title').value.trim()) { GS.toast('Le titre est obligatoire.', true); setMode('edit'); F('title').focus(); return; }
      var btns = form.querySelectorAll('.gs-dialog__foot button');
      Array.prototype.forEach.call(btns, function (b) { b.disabled = true; });
      GS.post('documentation.save', {
        id: cur && cur.id ? cur.id : '', title: F('title').value, category: F('category').value,
        summary: F('summary').value, content: ed.innerHTML, read_time: F('read_time').value, status: status
      }).then(function (r) {
        Array.prototype.forEach.call(btns, function (b) { b.disabled = false; });
        if (!r.ok) { GS.toast(r.error || 'Enregistrement impossible.', true); return; }
        GS.toast(status === 'published' ? 'Article publié dans l’Espace Client.' : 'Brouillon enregistré.');
        load((r.article && r.article.id) || (cur && cur.id));
      });
    }
    document.getElementById('docDraft').addEventListener('click', function () { save('draft'); });
    document.getElementById('docPublish').addEventListener('click', function () { save('published'); });
    document.getElementById('docDelete').addEventListener('click', function () {
      if (!cur || !cur.id || !window.confirm('Supprimer « ' + cur.title + ' » ? Il disparaîtra aussi de l’Espace Client.')) return;
      GS.post('documentation.delete', { id: cur.id }).then(function (r) {
        GS.toast(r.ok ? 'Article supprimé.' : (r.error || 'Suppression impossible.'), !r.ok);
        if (r.ok) { cur = null; load(); }
      });
    });
    document.getElementById('docNew').addEventListener('click', function () {
      open({ id: '', title: '', category: document.getElementById('docCat').value, summary: '', content_raw: '<p></p>', status: 'draft' });
      delete F('read_time').dataset.touched;
      F('title').focus();
    });
    document.getElementById('docList').addEventListener('click', function (e) {
      var li = e.target.closest('[data-id]');
      if (!li) return;
      var a = articles.filter(function (x) { return String(x.id) === li.getAttribute('data-id'); })[0];
      if (a) { delete F('read_time').dataset.touched; open(a); }
    });
    document.getElementById('docList').addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.closest('[data-id]')) e.target.click(); });
    document.getElementById('docCat').addEventListener('change', renderList);
    var s = document.getElementById('docSearch');
    if (s) s.addEventListener('input', renderList);

    function load(selectId) {
      GS.get('documentation.list').then(function (d) {
        if (!d.ok) { document.getElementById('docList').innerHTML = '<li class="ec-muted ec-small">' + GS.esc(d.error || 'Articles indisponibles.') + '</li>'; return; }
        articles = d.articles || [];
        fillCats();
        var a = articles.filter(function (x) { return String(x.id) === String(selectId); })[0] || articles[0];
        if (a) open(a); else document.getElementById('docNew').click();
      });
    }
    load();
  })();
  </script>
<?php
gnl_staff_close();
