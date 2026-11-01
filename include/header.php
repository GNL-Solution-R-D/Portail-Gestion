<!-- Bandeau supérieur plein largeur -->
<?php
$menuUser = $_SESSION['user'] ?? [];

$prenom = trim((string)($_SESSION['user']['prenom'] ?? ''));
$name   = trim((string)($_SESSION['user']['nom'] ?? ''));

$menuUsername = trim($prenom . ' ' . $name);

if ($menuUsername === '') {
    $menuUsername = trim((string)(
        $menuUser['username']
        ?? $menuUser['email']
        ?? 'Utilisateur'
    ));
}

$initialPrenom = $prenom !== ''
    ? (function_exists('mb_substr') ? mb_substr($prenom, 0, 1, 'UTF-8') : substr($prenom, 0, 1))
    : '';

$initialNom = $name !== ''
    ? (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1))
    : '';

$menuInitial = $initialPrenom . $initialNom;

if ($menuInitial === '') {
    $menuInitial = function_exists('mb_substr')
        ? mb_substr($menuUsername, 0, 1, 'UTF-8')
        : substr($menuUsername, 0, 1);
}

$menuInitial = function_exists('mb_strtoupper')
    ? mb_strtoupper($menuInitial, 'UTF-8')
    : strtoupper($menuInitial);

// Barre de recherche : masquée par défaut.
// Pour l'afficher sur une page, définir $showSearch = true; AVANT d'inclure ce header
// (pages commandes, abonnements, factures, équipes...).
//
// Personnalisation (toutes optionnelles, à définir AVANT l'inclusion) :
//   $showSearch        = true;                  // affiche la barre
//   $searchInputId     = 'globalSearchInput';   // id ciblé par le JS de la page
//   $searchPlaceholder = 'Rechercher…';         // texte indicatif du champ
// Le champ porte un id stable : chaque page peut y brancher son propre filtrage
// (ex. data/documentation_api.php pour la page Documentation).
$showSearch        = $showSearch ?? false;
$searchInputId     = $searchInputId ?? 'globalSearchInput';
$searchPlaceholder = $searchPlaceholder ?? 'Search';

// Jeton CSRF partagé (même clé que data/*_api.php) pour le marquage « lu ».
// On ne le crée que s'il n'existe pas encore : on ne casse pas un jeton existant.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (\Throwable $e) {
        $_SESSION['csrf'] = bin2hex((string)mt_rand());
    }
}
// Organisation courante (affichée dans la barre latérale et le menu utilisateur).
$menuOrgName = trim((string)(
    $menuUser['organization_commercial_name']
    ?? $menuUser['nom_commercial']
    ?? ''
));
if ($menuOrgName === '') {
    $menuOrgName = trim((string)($menuUser['organization_name'] ?? $menuUser['organization'] ?? $menuUser['raison'] ?? ''));
}
?>
<script>((e,t,r,o,a,n,s,i)=>{let l=document.documentElement,d=["light","dark"];function c(t){var r;(Array.isArray(e)?e:[e]).forEach(e=>{let r="class"===e,o=r&&n?a.map(e=>n[e]||e):a;r?(l.classList.remove(...o),l.classList.add(n&&n[t]?n[t]:t)):l.setAttribute(e,t)}),r=t,i&&d.includes(r)&&(l.style.colorScheme=r)}if(o)c(o);else try{let e=localStorage.getItem(t)||r,o=s&&"system"===e?window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light":e;c(o)}catch(e){}})("class","theme","system",null,["light","dark"],null,true,true)</script>
<?php
// --- Sélecteur de langue (drapeaux) ---------------------------------------
// On s'assure que lang.php est chargé (il définit t(), $lang, $supportedLanguages…).
// lang.php est dans le même dossier que ce header.
if (!function_exists('t')) {
    $langBootstrapFile = __DIR__ . '/lang.php';
    if (is_file($langBootstrapFile)) {
        require_once $langBootstrapFile;
    }
}

// Langues prises en charge (alignées sur lang.php).
$langSupported = isset($supportedLanguages) && is_array($supportedLanguages)
    ? $supportedLanguages
    : ['fr', 'en', 'es', 'de'];

// Langue active : on lit la session que lang.php renseigne, avec repli sur $lang.
$currentLang = (string)($_SESSION['language'] ?? ($lang ?? 'fr'));
if (!in_array($currentLang, $langSupported, true)) {
    $currentLang = in_array('fr', $langSupported, true) ? 'fr' : (string)reset($langSupported);
}

// Drapeaux SVG (rendu identique sur toutes les plateformes, contrairement aux emojis).
$langFlags = [
    'fr' => '<svg viewBox="0 0 3 2" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          . '<rect width="1" height="2" x="0" fill="#0055A4"/><rect width="1" height="2" x="1" fill="#FFFFFF"/><rect width="1" height="2" x="2" fill="#EF4135"/></svg>',
    'en' => '<svg viewBox="0 0 60 30" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          . '<rect width="60" height="30" fill="#012169"/>'
          . '<path d="M0,0 60,30 M60,0 0,30" stroke="#FFFFFF" stroke-width="6"/>'
          . '<path d="M0,0 60,30 M60,0 0,30" stroke="#C8102E" stroke-width="4"/>'
          . '<path d="M30,0 V30 M0,15 H60" stroke="#FFFFFF" stroke-width="10"/>'
          . '<path d="M30,0 V30 M0,15 H60" stroke="#C8102E" stroke-width="6"/></svg>',
    'es' => '<svg viewBox="0 0 3 2" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          . '<rect width="3" height="2" fill="#AA151B"/><rect width="3" height="1" y="0.5" fill="#F1BF00"/></svg>',
    'de' => '<svg viewBox="0 0 3 3" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
          . '<rect width="3" height="3" y="0" fill="#000000"/><rect width="3" height="2" y="1" fill="#DD0000"/><rect width="3" height="1" y="2" fill="#FFCE00"/></svg>',
];

// Noms affichés dans leur propre langue.
$langNames = [
    'fr' => 'Français',
    'en' => 'English',
    'es' => 'Español',
    'de' => 'Deutsch',
];

// Construit une URL vers la page courante en remplaçant uniquement ?lang=.
if (!function_exists('build_lang_switch_url')) {
    function build_lang_switch_url(string $code): string
    {
        $params = $_GET;
        $params['lang'] = $code;
        $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        if ($path === false || $path === '') {
            $path = (string)($_SERVER['PHP_SELF'] ?? '');
        }
        $query = http_build_query($params);
        return htmlspecialchars($path . ($query !== '' ? '?' . $query : ''), ENT_QUOTES, 'UTF-8');
    }
}

// Titre du menu (traduit si la clé existe, repli français sinon).
$langMenuTitle = function_exists('t') ? t('language_menu_title') : 'language_menu_title';
if ($langMenuTitle === 'language_menu_title') {
    $langMenuTitle = 'Langue';
}
?>
<div id="appHeader" class="ec-top">
  <button type="button" class="ec-icon-btn ec-top__burger" id="sidebarToggle"
          aria-controls="appSidebar" aria-expanded="false" aria-label="Ouvrir le menu">
    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h16"></path></svg>
  </button>
  <a href="/dashboard" class="ec-top__brand" aria-label="GNL Solution — Tableau de bord">
    <img src="/assets/gnl/img/logo-gnl-icone.svg" alt="" width="34" height="34">
  </a>

  <?php if ($showSearch): ?>
  <label class="ec-search">
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
    <span class="ec-sr"><?php echo htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8'); ?></span>
    <input id="<?php echo htmlspecialchars($searchInputId, ENT_QUOTES, 'UTF-8'); ?>" type="search" autocomplete="off"
           placeholder="<?php echo htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>">
  </label>
  <?php endif; ?>

  <div class="ec-top__actions">
    <div class="lang-menu" data-current-lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
      <button type="button" id="langMenuButton" class="ec-chip-btn lang-menu__trigger"
              aria-expanded="false" aria-haspopup="true" aria-controls="langMenuDropdown"
              aria-label="<?php echo htmlspecialchars($langMenuTitle . ' : ' . ($langNames[$currentLang] ?? strtoupper($currentLang)), ENT_QUOTES, 'UTF-8'); ?>">
        <span class="lang-menu__flag"><?php echo $langFlags[$currentLang] ?? ''; ?></span>
        <span><?php echo htmlspecialchars(strtoupper($currentLang), ENT_QUOTES, 'UTF-8'); ?></span>
      </button>
      <div id="langMenuDropdown" class="ec-dropdown notification-menu__dropdown lang-menu__dropdown" role="menu" aria-labelledby="langMenuButton">
        <strong class="ec-dropdown__title"><?php echo htmlspecialchars($langMenuTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
        <div class="lang-menu__list">
          <?php foreach ($langSupported as $code):
            $isActive = ($code === $currentLang);
            $flag     = $langFlags[$code] ?? '';
            $name     = $langNames[$code] ?? strtoupper($code); ?>
          <a class="lang-menu__item<?php echo $isActive ? ' is-active' : ''; ?>"
             href="<?php echo build_lang_switch_url($code); ?>"
             hreflang="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
             lang="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
             role="menuitemradio" aria-checked="<?php echo $isActive ? 'true' : 'false'; ?>">
            <span class="lang-menu__flag"><?php echo $flag; ?></span>
            <span class="lang-menu__label"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($isActive): ?><span class="lang-menu__check" aria-hidden="true">&#10003;</span><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="notification-menu">
      <button type="button" id="notificationMenuButton" class="ec-icon-btn"
              aria-expanded="false" aria-haspopup="true" aria-controls="notificationMenuDropdown" aria-label="Notifications">
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path></svg>
      </button>
      <span id="notificationBadge" class="notification-menu__badge" aria-hidden="true"></span>
      <div id="notificationMenuDropdown" class="ec-dropdown notification-menu__dropdown" role="menu" aria-labelledby="notificationMenuButton">
        <div class="notification-menu__head">
          <strong class="ec-dropdown__title">Notifications</strong>
          <button type="button" class="notification-menu__all" id="notificationMarkAll" hidden>Tout marquer comme lu</button>
        </div>
        <div class="notification-menu__list" id="notificationMenuList">
          <p class="notification-menu__item">Chargement…</p>
        </div>
      </div>
    </div>

    <button type="button" id="themeToggleButton" class="ec-icon-btn theme-toggle"
            aria-label="Activer le mode sombre" title="Activer le mode sombre" aria-pressed="false">
      <svg class="theme-toggle__icon theme-toggle__icon--sun" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3V4M12 20V21M4 12H3M6.31412 6.31412L5.5 5.5M17.6859 6.31412L18.5 5.5M6.31412 17.69L5.5 18.5001M17.6859 17.69L18.5 18.5001M21 12H20M16 12C16 14.2091 14.2091 16 12 16C9.79086 16 8 14.2091 8 12C8 9.79086 9.79086 8 12 8C14.2091 8 16 9.79086 16 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
      <svg class="theme-toggle__icon theme-toggle__icon--moon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M13 6V3M18.5 12V7M14.5 4.5H11.5M21 9.5H16M15.5548 16.8151C16.7829 16.8151 17.9493 16.5506 19 16.0754C17.6867 18.9794 14.7642 21 11.3698 21C6.74731 21 3 17.2527 3 12.6302C3 9.23576 5.02061 6.31331 7.92462 5C7.44944 6.05072 7.18492 7.21708 7.18492 8.44523C7.18492 13.0678 10.9322 16.8151 15.5548 16.8151Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
    </button>

    <div class="user-menu">
      <button type="button" id="userMenuButton" class="ec-user" aria-expanded="false" aria-haspopup="true" aria-controls="userMenuDropdown">
        <span class="ec-avatar" aria-hidden="true"><?php echo htmlspecialchars($menuInitial, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="ec-user__name"><?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?></span>
        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"></path></svg>
      </button>
      <div id="userMenuDropdown" class="ec-dropdown user-menu__dropdown" role="menu" aria-labelledby="userMenuButton">
        <span class="user-menu__username" title="<?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($menuOrgName !== ''): ?><span class="user-menu__org"><?php echo htmlspecialchars($menuOrgName, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
        <div class="user-menu__actions">
          <!-- Portail Gestion : pas de page « Mon compte » (comptes gérés dans Keycloak),
               2FA et sessions, lus et réécrits dans Keycloak via l'Admin REST. -->
          <a href="/equipes" class="user-menu__item" role="menuitem">Équipe GNL</a>
          <a href="https://b2b-portal.eu.gnl-solution.com" class="user-menu__item" role="menuitem" target="_blank" rel="noopener">Ouvrir l'Espace Client</a>
          <a href="/deconnexion" class="user-menu__item user-menu__item--danger" role="menuitem">Déconnexion</a>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="ec-scrim" id="sidebarScrim" hidden></div>

<script>
(function () {
  // Barre latérale en tiroir sous 1024 px.
  var btn = document.getElementById('sidebarToggle');
  var scrim = document.getElementById('sidebarScrim');
  if (!btn) return;
  function set(open) {
    document.body.classList.toggle('ec-nav-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (scrim) scrim.hidden = !open;
  }
  btn.addEventListener('click', function () { set(!document.body.classList.contains('ec-nav-open')); });
  if (scrim) scrim.addEventListener('click', function () { set(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
})();
</script>

<script>
  // data/notifications_api.php a été fusionné dans data/portail_api.php
  // (actions préfixées « notification.list » / « notification.read »).
  window.NOTIF_API = window.NOTIF_API || '/data/portail_api.php';
<?php
  // Jeton CSRF de session, attendu par csrf_check() (data/portail_api.php) pour
  // notification.read. Seules quelques pages le créaient : sur les autres, la
  // cloche envoyait un jeton vide → 403 et AUCUN appel n8n « notif_viewed ».
  if (session_status() === PHP_SESSION_ACTIVE
      && (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '')) {
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
?>
  window.NOTIF_CSRF = <?php echo json_encode($_SESSION['csrf'] ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>

<script>
(function () {
  const menu     = document.querySelector('.notification-menu');
  const button   = document.getElementById('notificationMenuButton');
  const dropdown = document.getElementById('notificationMenuDropdown');
  const list     = document.getElementById('notificationMenuList');
  const badge    = document.getElementById('notificationBadge');

  if (!menu || !button || !dropdown || !list) return;

  const API     = window.NOTIF_API || '/data/portail_api.php';
  const CSRF    = window.NOTIF_CSRF || '';
  const POLL_MS = 30000;

  let items  = [];
  let unread = 0;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));

  function timeAgo(value) {
    if (!value) return '';
    const t = Date.parse(value);
    if (isNaN(t)) return '';
    const s = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (s < 60) return "à l'instant";
    const m = Math.floor(s / 60); if (m < 60) return 'il y a ' + m + ' min';
    const h = Math.floor(m / 60); if (h < 24) return 'il y a ' + h + ' h';
    const d = Math.floor(h / 24); if (d < 7) return 'il y a ' + d + ' j';
    return new Date(t).toLocaleDateString('fr-FR');
  }

  function updateBadge() {
    const allBtn = document.getElementById('notificationMarkAll');
    if (allBtn) allBtn.hidden = !(unread > 0 || items.some((n) => !n.is_read));
    if (!badge) return;
    if (unread > 0) {
      badge.textContent = unread > 99 ? '99+' : String(unread);
      badge.classList.add('is-visible');
      button.setAttribute('aria-label', unread + ' notification(s) non lue(s)');
    } else {
      badge.textContent = '';
      badge.classList.remove('is-visible');
      button.setAttribute('aria-label', 'Notifications');
    }
  }

  function render() {
    if (!items.length) {
      list.innerHTML = '<p class="notification-menu__item">Aucune notification pour le moment.</p>';
      return;
    }
    list.innerHTML = items.map(function (n) {
      const cls  = 'notification-menu__item' + (n.is_read ? '' : ' is-unread');
      const time = timeAgo(n.created_at);
      const inner =
        '<span class="notification-menu__dot notification-menu__dot--' + esc(n.type || 'info') + '"></span>' +
        '<span class="notification-menu__body">' +
          (n.title   ? '<strong class="notification-menu__item-title">' + esc(n.title) + '</strong>' : '') +
          (n.message ? '<span class="notification-menu__item-text">' + esc(n.message) + '</span>' : '') +
          (time      ? '<time class="notification-menu__time">' + esc(time) + '</time>' : '') +
        '</span>';
      const data = ' data-notif-id="' + esc(n.id) + '"';
      return n.link
        ? '<a class="' + cls + '"' + data + ' href="' + esc(n.link) + '">' + inner + '</a>'
        : '<div class="' + cls + '"' + data + ' role="button" tabindex="0">' + inner + '</div>';
    }).join('');
  }

  async function load() {
    try {
      const res = await fetch(API + '?action=notification.list', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;
      items  = Array.isArray(data.notifications) ? data.notifications : [];
      unread = Number(data.unread || 0);
      render();
      updateBadge();
    } catch (_e) { /* hors-ligne : on garde l'affichage courant */ }
  }

  // POST notification.read → n8n { action:'notif_viewed', all:1 } ou { all:0, id }.
  // keepalive : la requête part même si le clic ouvre aussitôt une autre page.
  function postRead(body) {
    return fetch(API + '?action=notification.read', {
      method: 'POST',
      keepalive: true,
      headers: {
        'X-CSRF-Token': CSRF,
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      },
      credentials: 'same-origin',
      body: body
    }).then((res) => res.json().catch(() => null).then((data) => !!(res.ok && data && data.ok)))
      .catch(() => false);
  }

  function hasUnread() {
    return unread > 0 || items.some((n) => !n.is_read);
  }

  async function markAllRead() {
    if (!hasUnread()) return;
    if (await postRead('all=1')) {
      unread = 0;
      items = items.map((n) => Object.assign({}, n, { is_read: true }));
      updateBadge();
      render();
    }
  }

  function markOneRead(id) {
    const n = items.find((x) => String(x.id) === String(id));
    if (!id || !n || n.is_read) return;
    n.is_read = true;                       // affichage immédiat
    unread = Math.max(0, unread - 1);
    updateBadge();
    const el = list.querySelector('[data-notif-id="' + CSS.escape(String(id)) + '"]');
    if (el) el.classList.remove('is-unread');
    postRead('id=' + encodeURIComponent(id)).then((ok) => { if (!ok) load(); });
  }

  // Clic (ou Entrée) sur une notification : la marque comme lue côté n8n.
  list.addEventListener('click', function (event) {
    const el = event.target.closest('[data-notif-id]');
    if (el) markOneRead(el.getAttribute('data-notif-id'));
  });
  list.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const el = event.target.closest('div[data-notif-id]');
    if (el) { event.preventDefault(); markOneRead(el.getAttribute('data-notif-id')); }
  });

  // L'ouverture ne marque plus rien : chaque notification est marquée lue
  // (n8n « notif_viewed » + id) quand on clique dessus, ou toutes via le bouton.
  function openMenu() {
    dropdown.classList.add('is-open');
    button.setAttribute('aria-expanded', 'true');
  }

  const markAllBtn = document.getElementById('notificationMarkAll');
  if (markAllBtn) {
    markAllBtn.addEventListener('click', function (event) {
      event.stopPropagation();
      markAllRead();
    });
  }

  function closeMenu() {
    dropdown.classList.remove('is-open');
    button.setAttribute('aria-expanded', 'false');
  }

  function toggleMenu() {
    dropdown.classList.contains('is-open') ? closeMenu() : openMenu();
  }

  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', function (event) {
    if (!menu.contains(event.target)) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
      button.focus();
    }
  });

  load();
  setInterval(load, POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') load();
  });
})();
</script>

<script>
(function () {
  const menu = document.querySelector('.user-menu');
  const button = document.getElementById('userMenuButton');
  const dropdown = document.getElementById('userMenuDropdown');

  if (!menu || !button || !dropdown) return;

  function openMenu() {
    dropdown.classList.add('is-open');
    button.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    dropdown.classList.remove('is-open');
    button.setAttribute('aria-expanded', 'false');
  }

  function toggleMenu() {
    dropdown.classList.contains('is-open') ? closeMenu() : openMenu();
  }

  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', function (event) {
    if (!menu.contains(event.target)) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
      button.focus();
    }
  });
})();
</script>

<script>
(function () {
  const menu     = document.querySelector('.lang-menu');
  const button   = document.getElementById('langMenuButton');
  const dropdown = document.getElementById('langMenuDropdown');

  if (!menu || !button || !dropdown) return;

  function openMenu() {
    dropdown.classList.add('is-open');
    button.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    dropdown.classList.remove('is-open');
    button.setAttribute('aria-expanded', 'false');
  }

  function toggleMenu() {
    dropdown.classList.contains('is-open') ? closeMenu() : openMenu();
  }

  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', function (event) {
    if (!menu.contains(event.target)) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeMenu();
      button.focus();
    }
  });
})();
</script>

<script>
(function () {
  const STORAGE_KEY = 'theme';
  const button = document.getElementById('themeToggleButton');
  if (!button) return;

  const root = document.documentElement;

  // Thème effectif courant (déjà appliqué par le script d'amorçage en haut de page).
  function currentTheme() {
    return root.classList.contains('dark') ? 'dark' : 'light';
  }

  function syncLabel(theme) {
    const label = theme === 'dark' ? 'Activer le mode clair' : 'Activer le mode sombre';
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
    button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
  }

  // On synchronise seulement le libellé au chargement (sans écrire dans le stockage,
  // afin de préserver un éventuel choix « système »).
  syncLabel(currentTheme());

  button.addEventListener('click', function () {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    root.classList.remove('light', 'dark');
    root.classList.add(next);
    root.style.colorScheme = next;
    try { localStorage.setItem(STORAGE_KEY, next); } catch (_e) { /* stockage indisponible */ }
    syncLabel(next);
  });
})();
</script>

<script>
(function () {
  const header = document.getElementById('appHeader');
  if (!header) return;

  const root = document.documentElement;
  const updateHeaderHeight = () => {
    root.style.setProperty('--app-header-height', `${header.getBoundingClientRect().height}px`);
  };

  updateHeaderHeight();
  window.addEventListener('load', updateHeaderHeight);
  window.addEventListener('resize', updateHeaderHeight);

  if (typeof ResizeObserver !== 'undefined') {
    const observer = new ResizeObserver(updateHeaderHeight);
    observer.observe(header);
  }
})();
</script>

<script>
(function () {
  document.addEventListener('click', function (event) {
    if (event.defaultPrevented) return;
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const link = event.target.closest('a[href]');
    if (!link) return;
    if (link.hasAttribute('download')) return;
    if (link.target && link.target !== '_self') return;
    if (link.hasAttribute('data-no-force-nav')) return;

    const rawHref = link.getAttribute('href');
    if (!rawHref || rawHref.startsWith('#')) return;

    let url;
    try {
      url = new URL(link.href, window.location.href);
    } catch (_error) {
      return;
    }

    if (!/^https?:$/.test(url.protocol)) return;
    if (url.origin !== window.location.origin) return;

    const currentWithoutHash = window.location.href.split('#')[0];
    const targetWithoutHash = url.href.split('#')[0];
    if (currentWithoutHash === targetWithoutHash) return;

    event.preventDefault();
    window.location.assign(url.href);
  }, true);
})();
</script>