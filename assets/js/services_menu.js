/**
 * assets/js/services_menu.js
 *
 * PORTAIL GESTION : remplit les dépliants « Client » de la barre latérale avec
 * les PRODUITS ACHETÉS par TOUS les clients, regroupés par entreprise (statut active, suspended ou deployment).
 *
 * Un seul appel : data/services_menu_api.php, qui enchaîne côté serveur
 * order.list → order.product → product.list et renvoie les entrées déjà
 * groupées par dépliant (colonne product.esp_cli_menu_name).
 *
 *   web    → #web-services-list        (Services WEB)
 *   cloud  → #cloud-services-list      (Services Cloud)
 *   other  → #specific-services-list   (Services Spécifiques)
 *   vm     → #virtual-servers-list     (Serveurs Virtualisés)
 *   bm     → #dedicated-servers-list   (Serveurs Dédiés)
 *
 * Remplace assets/js/k8s_menu.js (déploiements Kubernetes) pour « Services WEB ».
 *
 * DÉPLIANTS VIDES — une catégorie sans aucun service actif ou suspendu est
 * masquée entièrement (bouton compris) : un client qui n'a pas de serveur dédié
 * ne voit pas « Serveurs Dédiés ». Le dépliant réapparaît dès qu'un service y
 * entre. Les 5 dépliants sont MASQUÉS PAR DÉFAUT (attribut « hidden » dans
 * include/menu.php) : rien n'apparaît pendant le chargement, seules les
 * catégories qui contiennent au moins un service sont affichées ensuite.
 * En cas d'erreur, seul « Services WEB » s'affiche, avec le message.
 *
 * RENOMMAGE — clic droit sur un service → « Renommer ». Réutilise le menu
 * contextuel (#deploymentContextMenu) et le modal (#renameDeploymentModal)
 * déjà présents dans include/menu.php, et l'action déjà en place
 * « deployment.rename » de data/portail_api.php. La clé envoyée à n8n est
 * order_product.uid → colonne product_uid de la table label_portail (V2).
 * Un nom vide réinitialise l'affichage au nom du produit du catalogue.
 *
 * LIEN — une entrée devient un <a> quand l'API renvoie un « href » non vide,
 * c'est-à-dire quand product.provider_type = « kube » et que la ligne de
 * commande porte un provider_service_slug. Le lien mène à
 * .../deployment?deployment=<provider_service_slug>. Les autres entrées restent
 * de simples libellés (<div>), renommables de la même façon.
 */

(async function () {
  // Portail GESTION : chargé par include/menu.php ET éventuellement par la page
  // (dashboard) — une seule exécution.
  if (window.__servicesMenuInit) return;
  window.__servicesMenuInit = true;

  var TARGETS = {
    web:   'web-services-list',
    cloud: 'cloud-services-list',
    other: 'specific-services-list',
    vm:    'virtual-servers-list',
    bm:    'dedicated-servers-list'
  };

  // Icône affichée devant chaque produit, par dépliant.
  var ICON_PATHS = {
    web:   '<path d="M6 7.95h.01M9 7.95h.01M12 7.95h.01M6.2 19h11.6c1.12 0 1.68 0 2.108-.218a2 2 0 0 0 .874-.874C21 17.48 21 16.92 21 15.8V8.2c0-1.12 0-1.68-.218-2.108a2 2 0 0 0-.874-.874C19.48 5 18.92 5 17.8 5H6.2c-1.12 0-1.68 0-2.108.218a2 2 0 0 0-.874.874C3 6.52 3 7.08 3 8.2v7.6c0 1.12 0 1.68.218 2.108a2 2 0 0 0 .874.874C4.52 19 5.08 19 6.2 19Z"></path>',
    cloud: '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"></path>',
    other: '<path d="M20 7h-9"></path><path d="M14 17H5"></path><circle cx="17" cy="17" r="3"></circle><circle cx="7" cy="7" r="3"></circle>',
    vm:    '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"></path><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"></path><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"></path>',
    bm:    '<rect height="8" rx="2" ry="2" width="20" x="2" y="2"></rect><rect height="8" rx="2" ry="2" width="20" x="2" y="14"></rect><line x1="6" x2="6.01" y1="6" y2="6"></line><line x1="6" x2="6.01" y1="18" y2="18"></line>'
  };

  var hosts = {};
  var found = false;
  Object.keys(TARGETS).forEach(function (key) {
    var el = document.getElementById(TARGETS[key]);
    if (el) { hosts[key] = el; found = true; }
  });
  if (!found) return;

  var apiUrl = (function () {
    if (typeof window !== 'undefined' && window.SERVICES_MENU_API_URL) {
      return new URL(String(window.SERVICES_MENU_API_URL), window.location.href);
    }
    var inPagesDir = window.location.pathname.indexOf('/pages/') !== -1;
    return new URL(inPagesDir ? '../data/services_menu_api.php' : './data/services_menu_api.php', window.location.href);
  })();

  // Proxy n8n (deployment.rename). Exposé par include/menu.php, sinon déduit.
  var portailApiUrl = (function () {
    if (typeof window !== 'undefined' && window.PORTAIL_API) {
      return new URL(String(window.PORTAIL_API), window.location.href);
    }
    var inPagesDir = window.location.pathname.indexOf('/pages/') !== -1;
    return new URL(inPagesDir ? '../data/portail_api.php' : './data/portail_api.php', window.location.href);
  })();

  // Index uid → entrée, alimenté à chaque rendu (utilisé par le modal).
  var entriesByUid = {};

  await load(false);
  applyStates();   // volontairement sans await : les badges arrivent apres coup
  // Portail GESTION : pas de renommage depuis le menu « Client ». L'action
  // deployment.rename du portail gestion attend deployment_name (pas product_uid)
  // et le menu contextuel / modal sont déjà câblés sur « Les services GNL ».
  // wireRename();

  // ── Chargement + rendu ──────────────────────────────────────────────────────

  async function load(force) {
    // Aucun changement de visibilité ici : au premier chargement tout reste
    // masqué (HTML), lors d'un rechargement les dépliants gardent leur état.
    setAll('<div class="text-muted-foreground text-xs px-2.5 py-1 pl-10">Chargement…</div>');

    var url = new URL(apiUrl.toString());
    if (force) url.searchParams.set('refresh', '1');
    // Portail GESTION : raccourci ?org=<uuid> de /entreprises → une seule entreprise.
    var pageOrg = new URL(window.location.href).searchParams.get('org');
    if (pageOrg) url.searchParams.set('org', pageOrg);

    try {
      var res = await fetch(url.toString(), { credentials: 'same-origin' });
      var ct  = (res.headers.get('content-type') || '').toLowerCase();
      var raw = await res.text();

      var data = null;
      try { data = JSON.parse(raw); } catch (_) { /* ignore */ }

      if (ct.indexOf('application/json') === -1 || !data) {
        throw new Error(buildNonJsonError(res.status, url.pathname, raw));
      }
      if (!res.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + res.status));
      }

      var menus = (data && typeof data.menus === 'object' && data.menus) ? data.menus : {};
      entriesByUid = {};

      Object.keys(hosts).forEach(function (key) {
        var entries = Array.isArray(menus[key]) ? menus[key] : [];
        entries.forEach(function (e) {
          if (e && e.uid) entriesByUid[String(e.uid)] = e;
        });

        if (entries.length) {
          hosts[key].innerHTML = renderGrouped(entries, key);
          showBlock(key, true);
        } else {
          // Aucun service dans cette catégorie : le dépliant disparaît.
          hosts[key].innerHTML = '';
          showBlock(key, false);
        }
      });

      if (Array.isArray(data.warnings) && data.warnings.length) {
        console.warn('[services] ' + data.warnings.join(' | '));
      }
      if (Array.isArray(data.unmapped) && data.unmapped.length) {
        console.warn('[services] produits sans esp_cli_menu_name exploitable : ' + data.unmapped.join(', '));
      }
    } catch (e) {
      var msg = escapeHtml(e && e.message ? e.message : String(e));
      showError('<div class="text-red-600 text-xs px-2.5 py-1 pl-10">Services : ' + msg + '</div>');
    }
  }

  // ── Clic droit → « Renommer » → modal → deployment.rename ───────────────────
  //  Réutilise le menu contextuel et le modal déjà présents dans
  //  include/menu.php. La clé envoyée à n8n est order_product.uid
  //  (colonne label_portail.product_uid).

  function wireRename() {
    var ctxMenu = document.getElementById('deploymentContextMenu');
    var modal   = document.getElementById('renameDeploymentModal');
    if (!ctxMenu || !modal) return;

    var input      = modal.querySelector('[data-rename-input]');
    var confirmBtn = modal.querySelector('[data-rename-confirm]');
    var statusEl   = modal.querySelector('[data-rename-status]');
    var nameEl     = modal.querySelector('[data-rename-deployment-name]');

    function hideCtx() { ctxMenu.classList.add('hidden'); }

    function showCtx(x, y, uid) {
      ctxMenu.dataset.serviceUid = uid || '';
      ctxMenu.classList.remove('hidden');
      var r = ctxMenu.getBoundingClientRect();
      ctxMenu.style.left = Math.max(8, Math.min(x, window.innerWidth  - r.width  - 8)) + 'px';
      ctxMenu.style.top  = Math.max(8, Math.min(y, window.innerHeight - r.height - 8)) + 'px';
    }

    function setStatus(text, kind) {
      if (!statusEl) return;
      statusEl.textContent = text || '';
      statusEl.className = 'mt-3 text-xs ' +
        (kind === 'err' ? 'text-red-600' : kind === 'ok' ? 'text-emerald-600' : 'text-muted-foreground');
    }

    function openModal(uid) {
      var entry = entriesByUid[uid];
      if (!entry) return;
      modal.dataset.serviceUid = uid;
      if (nameEl) nameEl.textContent = String(entry.product_name || entry.name || uid);
      if (input)  input.value = String(entry.display_name || '');
      setStatus('');
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      if (input) requestAnimationFrame(function () { input.focus(); input.select(); });
    }

    function closeModal() {
      modal.classList.remove('flex');
      modal.classList.add('hidden');
      modal.dataset.serviceUid = '';
    }

    // Délégation sur chaque conteneur : les entrées sont re-rendues à chaque
    // chargement, on ne peut pas écouter sur les lignes elles-mêmes.
    Object.keys(hosts).forEach(function (key) {
      hosts[key].addEventListener('contextmenu', function (e) {
        var el = e.target.closest('[data-service-uid]');
        if (!el) return;
        var uid = el.getAttribute('data-service-uid');
        if (!uid) return;
        e.preventDefault();
        e.stopPropagation();
        showCtx(e.clientX, e.clientY, uid);
      });
    });

    document.addEventListener('click', hideCtx);
    document.addEventListener('contextmenu', hideCtx);
    window.addEventListener('scroll', hideCtx, true);
    window.addEventListener('resize', hideCtx);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideCtx(); });

    var renameItem = ctxMenu.querySelector('[data-deployment-rename]');
    if (renameItem) {
      renameItem.addEventListener('click', function () {
        var uid = ctxMenu.dataset.serviceUid || '';
        hideCtx();
        openModal(uid);
      });
    }

    modal.querySelectorAll('[data-rename-cancel]').forEach(function (b) {
      b.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('flex')) closeModal();
    });
    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); if (confirmBtn) confirmBtn.click(); }
      });
    }

    if (confirmBtn) {
      confirmBtn.addEventListener('click', async function () {
        var uid = modal.dataset.serviceUid || '';
        if (!uid) return;

        var displayName = input ? input.value.trim() : '';
        confirmBtn.disabled = true;
        setStatus('Enregistrement…');

        try {
          var u = new URL(portailApiUrl.toString());
          u.searchParams.set('action', 'deployment.rename');

          var res = await fetch(u.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': String(window.PORTAIL_CSRF || '')
            },
            // display_name vide ⇒ le backend réinitialise au nom du catalogue.
            body: new URLSearchParams({ product_uid: uid, display_name: displayName })
          });

          var raw = await res.text();
          var data = null;
          try { data = JSON.parse(raw); } catch (_) { /* ignore */ }
          if (!data) throw new Error('Réponse non-JSON (' + res.status + ').');
          if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));

          closeModal();
          await load(true); // cache serveur invalidé, on relit la liste à jour
          applyStates();    // le rendu a effacé les badges d'état : on les repose
        } catch (err) {
          setStatus('Erreur : ' + (err && err.message ? err.message : String(err)), 'err');
        } finally {
          confirmBtn.disabled = false;
        }
      });
    }
  }

  // ── Rendu ───────────────────────────────────────────────────────────────────

  // Une entrée = une ligne de commande (order_product.uid). Le badge de droite
  // affiche le statut brut de cette ligne (« active », « suspended »,
  // « deployment », …).
  // Portail GESTION : les entrées (triées par entreprise côté serveur) sont
  // regroupées sous un intitulé par client dans chaque dépliant.
  function renderGrouped(entries, menuKey) {
    var html = '';
    var current = null;
    entries.forEach(function (e) {
      var client = String((e && e.client_name) || '').trim();
      if (client !== current) {
        current = client;
        html += '<div class="text-muted-foreground text-xs font-semibold px-2.5 pt-2 pb-1 truncate" ' +
          'title="' + escapeHtml(client || 'Client inconnu') + '">' + escapeHtml(client || 'Client inconnu') + '</div>';
      }
      html += renderEntry(e, menuKey);
    });
    return html;
  }

  function renderEntry(entry, menuKey) {
    var name = String((entry && entry.name) || (entry && entry.slug) || '').trim();
    if (!name) return '';

    var uid         = String((entry && entry.uid) || '').trim();
    var status      = String((entry && entry.status) || '').trim();
    var productName = String((entry && entry.product_name) || '').trim();
    var renamed     = productName !== '' && productName !== name;
    var statusKey   = status.toLowerCase();
    var suspended   = statusKey === 'suspended';
    var deploying   = statusKey === 'deployment';

    var icon =
      '<span class="mr-0.5 grid shrink-0 place-items-center">' +
        '<svg class="h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" ' +
        'aria-hidden="true">' + (ICON_PATHS[menuKey] || ICON_PATHS.other) + '</svg>' +
      '</span>';

    // « data-service-badge » : point d'accroche pour applyStates(), qui remplace
    // ce badge de facturation par ERROR / CRASH STATE quand le fournisseur
    // signale un incident.
    var badge = status
      ? '<span data-service-badge class="ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium ' +
          (suspended ? 'bg-amber-100 text-amber-700'
            : deploying ? 'bg-orange-100 text-orange-700'
            : 'bg-secondary text-muted-foreground') + '">' +
          escapeHtml(status) +
        '</span>'
      : '';

    // href n'est rempli par l'API que si product.provider_type = « kube » et que
    // order_product.provider_service_slug est renseigné. Sinon : simple libellé.
    var href = String((entry && entry.href) || '').trim();

    var clientName = String((entry && entry.client_name) || '').trim();
    var title = (clientName ? clientName + ' — ' : '') + name +
      (renamed ? ' (' + productName + ')' : '') +
      (status ? ' — ' + status : '') +
      (uid ? ' · ' + uid : '') +
      (href ? '\nOuvrir la page du déploiement' : '');

    var attrs = 'data-service-uid="' + escapeHtml(uid) + '" ' +
      'data-service-slug="' + escapeHtml(String(entry.slug || '')) + '" ' +
      'title="' + escapeHtml(title) + '" ' +
      'class="text-muted-foreground hover:text-foreground hover:bg-secondary flex w-full items-center gap-2 rounded px-2.5 py-2 text-sm transition-colors">';

    var inner =
      icon +
      '<span class="font-medium truncate min-w-0' + (suspended ? ' opacity-70' : '') + '">' + escapeHtml(name) + '</span>' +
      badge;

    return href
      ? '<a href="' + escapeHtml(href) + '" ' + attrs + inner + '</a>'
      : '<div ' + attrs + inner + '</div>';
  }

  // Le dépliant complet (bouton + panneau), pas seulement la liste.
  function blockOf(key) {
    return hosts[key].closest('[data-slot="collapsible"]');
  }

  // Un dépliant sans service n'a rien à ouvrir : on le retire du menu plutôt
  // que d'afficher un bouton qui ne mène à rien. « hidden » suffit : le
  // conteneur n'a aucune classe d'affichage qui pourrait le contredire.
  function showBlock(key, visible) {
    var block = blockOf(key);
    if (!block) return;
    block.hidden = !visible;
    if (visible) block.removeAttribute('data-services-empty');
    else block.setAttribute('data-services-empty', 'true');
  }

  function setAll(html) {
    Object.keys(hosts).forEach(function (key) {
      hosts[key].innerHTML = html;
    });
  }

  // Erreur : un seul message, dans le premier dépliant disponible (Services WEB
  // en priorité), pour ne pas faire disparaître le menu sans explication.
  // Les autres catégories sont masquées.
  function showError(html) {
    var keys = Object.keys(hosts);
    var target = hosts.web ? 'web' : keys[0];
    keys.forEach(function (key) {
      if (key === target) {
        hosts[key].innerHTML = html;
        showBlock(key, true);
      } else {
        hosts[key].innerHTML = '';
        showBlock(key, false);
      }
    });
  }

  function buildNonJsonError(status, path, raw) {
    var compact = String(raw || '').replace(/\s+/g, ' ').trim();

    if (/failed opening required/i.test(compact) || /failed to open stream/i.test(compact)) {
      return 'API services indisponible (' + status + '). Vérifie la configuration serveur de ' + path + '.';
    }
    if (/<\/?(html|body|br|b)\b/i.test(compact)) {
      return 'API services indisponible (' + status + '). Le serveur a renvoyé une page HTML au lieu de JSON.';
    }
    return 'Réponse API invalide (' + status + ') sur ' + path + '.';
  }

  // ── État de santé des services ─────────────────────────────────────────
  // Le badge rendu par renderEntry() porte le statut de FACTURATION venu de n8n
  // (active / suspended / deployment). data/services_state_api.php, lui, dit si
  // le service est réellement en panne côté fournisseur — la même chose que ce
  // qu'affiche sa page de gestion, mais pour tous les services en un appel.
  // Un incident PRIME sur le statut n8n : le badge est remplacé, pas doublé.
  // Tout est LOCAL a la fonction : ce bloc est place en fin de fichier, apres
  // l'appel a applyStates(). Une declaration de fonction est hoistee, pas la
  // VALEUR d'un « var » — des constantes de module seraient undefined au moment
  // du premier appel.
  async function applyStates() {
    // Couleurs en style INLINE, pas en classes Tailwind : le CSS compile
    // (assets/styles/connexion-style.css) ne contient aucun utilitaire de fond
    // jaune ou ambre \u2014 c'est deja pourquoi le badge « suspended » n'a pas de
    // fond. Un style inline s'affiche sans recompiler quoi que ce soit.
    var STATE_STYLES = {
      error: 'background:#dc2626;color:#ffffff;',            // rouge, texte blanc
      crash: 'background:#facc15;color:#422006;'             // jaune, texte sombre
    };
    // États live, même code couleur que la pastille de deployment_ptero.php :
    // vert en marche, orange en transition, rouge à l'arrêt. Un état inconnu
    // n'a pas d'entrée ici — on laisse alors le statut n8n en place plutôt que
    // d'afficher un mot sans couleur ni sens.
    var LIVE_STYLES = {
      running:  'background:#16a34a;color:#ffffff;',
      starting: 'background:#ea580c;color:#ffffff;',
      stopping: 'background:#ea580c;color:#ffffff;',
      stopped:  'background:#dc2626;color:#ffffff;',
      offline:  'background:#dc2626;color:#ffffff;'
    };
    var BADGE_CLASS = 'ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px]';
    var url = new URL(
      (window.location.pathname.indexOf('/pages/') !== -1 ? '../' : './') + 'data/services_state_api.php',
      window.location.href
    );

    var states;
    try {
      var res = await fetch(url.toString(), { credentials: 'same-origin' });
      var ct  = (res.headers.get('content-type') || '').toLowerCase();
      if (ct.indexOf('application/json') === -1) return;   // repli muet
      var data = await res.json();
      if (!res.ok || !data || !data.ok) return;
      if (Array.isArray(data.warnings) && data.warnings.length) {
        console.warn('[services] état : ' + data.warnings.join(' | '));
      }
      states = (data.states && typeof data.states === 'object') ? data.states : null;
    } catch (e) {
      // L'état est un bonus : son absence ne doit rien casser — mais elle ne
      // doit pas non plus être invisible.
      console.warn('[services] état indisponible : ' + (e && e.message ? e.message : e));
      return;
    }
    if (!states) return;

    var nodes = document.querySelectorAll('[data-service-uid]');
    Array.prototype.forEach.call(nodes, function (node) {
      var st = states[node.getAttribute('data-service-uid') || ''];
      if (!st || !st.level) return;

      // Un incident s'affiche en gras : il doit sauter aux yeux avant un simple
      // état de marche, qui reste une information de routine.
      var style  = STATE_STYLES[st.level];
      var weight = ' font-semibold tracking-wide';
      if (st.level === 'state') {
        style  = LIVE_STYLES[String(st.label || '').toLowerCase()];
        weight = ' font-medium';
      }
      if (!style) return;

      var badge = node.querySelector('[data-service-badge]');
      if (!badge) {
        // Service sans statut n8n : il n'y avait pas de badge, on en crée un.
        badge = document.createElement('span');
        badge.setAttribute('data-service-badge', '');
        node.appendChild(badge);
      }
      badge.className = BADGE_CLASS + weight;
      badge.setAttribute('style', style);
      badge.textContent = String(st.label || (st.level === 'crash' ? 'CRASH STATE' : 'ERROR'));

      if (st.reason) {
        badge.setAttribute('title', String(st.reason));
        node.setAttribute('title', (node.getAttribute('title') || '') + '\n\n' + String(st.reason));
      }
    });
  }

  function escapeHtml(s) {
    return String(s)
      .split('&').join('&amp;')
      .split('<').join('&lt;')
      .split('>').join('&gt;')
      .split('"').join('&quot;')
      .split("'").join('&#039;');
  }
})();
