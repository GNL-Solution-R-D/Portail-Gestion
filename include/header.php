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
?>
<div hidden="">
  <!--$--><!--/$-->
</div>
<script>((e,t,r,o,a,n,s,i)=>{let l=document.documentElement,d=["light","dark"];function c(t){var r;(Array.isArray(e)?e:[e]).forEach(e=>{let r="class"===e,o=r&&n?a.map(e=>n[e]||e):a;r?(l.classList.remove(...o),l.classList.add(n&&n[t]?n[t]:t)):l.setAttribute(e,t)}),r=t,i&&d.includes(r)&&(l.style.colorScheme=r)}if(o)c(o);else try{let e=localStorage.getItem(t)||r,o=s&&"system"===e?window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light":e;c(o)}catch(e){}})("class","theme","system",null,["light","dark"],null,true,true)</script>

<style>
  .app-shell-offset-min-height {
    min-height: calc(100vh - var(--app-header-height, 0px));
    min-height: calc(100dvh - var(--app-header-height, 0px));
  }

  .notification-menu {
    position: relative;
  }

  .notification-menu__dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: min(320px, calc(100vw - 1.5rem));
    padding: 0.6rem;
    border-radius: 0.75rem;
    border: 1px solid var(--border);
    background: var(--popover, #fff);
    color: var(--popover-foreground, inherit);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.16);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-6px);
    pointer-events: none;
    transition: opacity 160ms ease, transform 160ms ease, visibility 160ms ease;
    z-index: 2100;
  }

  .notification-menu__dropdown.is-open {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: auto;
  }

  .notification-menu__title {
    display: block;
    margin: 0 0 0.45rem;
    font-size: 0.9rem;
    font-weight: 600;
  }

  .notification-menu__list {
    display: grid;
    gap: 0.45rem;
    max-height: min(60vh, 360px);
    overflow-y: auto;
  }

  .notification-menu__item {
    display: flex;
    gap: 0.5rem;
    align-items: flex-start;
    margin: 0;
    padding: 0.55rem 0.65rem;
    border-radius: 0.55rem;
    background: color-mix(in oklab, var(--muted) 55%, transparent);
    font-size: 0.84rem;
    line-height: 1.35;
    color: inherit;
    text-decoration: none;
  }

  a.notification-menu__item:hover {
    background: color-mix(in oklab, var(--muted) 80%, transparent);
  }

  .notification-menu__item.is-unread {
    background: color-mix(in oklab, var(--primary, #2563eb) 14%, transparent);
  }

  .notification-menu__dot {
    flex: 0 0 auto;
    width: 0.55rem;
    height: 0.55rem;
    margin-top: 0.32rem;
    border-radius: 999px;
    background: var(--muted-foreground, #64748b);
  }

  .notification-menu__dot--success { background: #16a34a; }
  .notification-menu__dot--warning { background: #d97706; }
  .notification-menu__dot--error,
  .notification-menu__dot--danger  { background: #dc2626; }
  .notification-menu__dot--order        { background: #2563eb; }
  .notification-menu__dot--invoice      { background: #7c3aed; }
  .notification-menu__dot--subscription { background: #0891b2; }
  .notification-menu__dot--team         { background: #db2777; }

  .notification-menu__body {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-width: 0;
  }

  .notification-menu__item-title { font-weight: 600; }

  .notification-menu__item-text {
    color: var(--muted-foreground, #64748b);
    word-break: break-word;
  }

  .notification-menu__time {
    font-size: 0.72rem;
    color: var(--muted-foreground, #64748b);
  }

  .notification-menu__badge {
    position: absolute;
    top: -2px;
    right: -2px;
    min-width: 1.05rem;
    height: 1.05rem;
    padding: 0 0.25rem;
    display: none;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: var(--destructive, #dc2626);
    color: var(--destructive-foreground, #fff);
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    pointer-events: none;
  }

  .notification-menu__badge.is-visible { display: flex; }

  /* La cloche est masquée sous lg : le badge l'est aussi. */
  @media (max-width: 1023px) {
    .notification-menu__badge { display: none !important; }
  }

  /* --- Sélecteur de langue --- */
  .lang-menu {
    position: relative;
  }

  .lang-menu__trigger {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    height: 2.25rem;
    padding: 0 0.5rem;
    border: 1px solid transparent;
    border-radius: 0.5rem;
    background: transparent;
    color: inherit;
    cursor: pointer;
    transition: background-color 140ms ease;
  }

  .lang-menu__trigger:hover {
    background: color-mix(in oklab, var(--muted) 70%, transparent);
  }

  .lang-menu__trigger:focus-visible {
    outline: none;
    border-color: var(--ring, #94a3b8);
    box-shadow: 0 0 0 3px color-mix(in oklab, var(--ring, #94a3b8) 45%, transparent);
  }

  .lang-menu__flag {
    display: inline-flex;
    flex: 0 0 auto;
    width: 22px;
    height: 16px;
    border-radius: 3px;
    overflow: hidden;
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.10);
  }

  .lang-menu__flag svg {
    display: block;
    width: 100%;
    height: 100%;
  }

  .lang-menu__caret {
    width: 14px;
    height: 14px;
    opacity: 0.6;
    transition: transform 160ms ease;
  }

  .lang-menu__trigger[aria-expanded="true"] .lang-menu__caret {
    transform: rotate(180deg);
  }

  .lang-menu__dropdown {
    width: min(220px, calc(100vw - 1.5rem));
  }

  .lang-menu__list {
    display: grid;
    gap: 0.2rem;
  }

  .lang-menu__item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0;
    padding: 0.5rem 0.6rem;
    border-radius: 0.5rem;
    font-size: 0.88rem;
    line-height: 1.2;
    color: inherit;
    text-decoration: none;
  }

  .lang-menu__item:hover {
    background: color-mix(in oklab, var(--muted) 80%, transparent);
  }

  .lang-menu__item.is-active {
    background: color-mix(in oklab, var(--primary, #2563eb) 14%, transparent);
    font-weight: 600;
  }

  .lang-menu__label {
    flex: 1 1 auto;
    min-width: 0;
  }

  .lang-menu__check {
    flex: 0 0 auto;
    color: var(--primary, #2563eb);
    font-weight: 700;
  }

  /* --- Bascule mode clair / mode sombre --- */
  .theme-toggle {
    cursor: pointer;
  }

  /* Par défaut (mode clair) : on affiche le soleil. */
  .theme-toggle__icon--moon { display: none; }
  .theme-toggle__icon--sun  { display: block; }

  /* En mode sombre : on affiche la lune. */
  html.dark .theme-toggle__icon--sun  { display: none; }
  html.dark .theme-toggle__icon--moon { display: block; }
</style>

<div id="appHeader" class="bg-background w-full border shadow-sm">
  <nav class="w-full overflow-visible rounded-lg border border-transparent p-2 shadow-transparent">
    <div class="relative flex items-center gap-8">
      <a href="./dashboard" class="rounded-md px-2.5 py-2 transition-colors">
        <p class="mt-1 ml-1 text-base font-semibold">GNL Solution</p>
        <p class="mt-1 ml-1 text-base font-semibold">Portail Gestion Infrasructure</p>
      </a>

      <div class="ml-auto flex items-center gap-2">
        <?php if ($showSearch): ?>
        <div class="relative hidden w-full max-w-sm min-w-[200px] items-center md:block">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search absolute top-2.5 left-2.5 h-5 w-5 text-slate-600">
            <circle cx="11" cy="11" r="8"></circle>
            <path d="m21 21-4.3-4.3"></path>
          </svg>
          <input id="<?php echo htmlspecialchars($searchInputId, ENT_QUOTES, 'UTF-8'); ?>" type="search" autocomplete="off" data-slot="input" class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive pl-10" placeholder="<?php echo htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"/>
        </div>
        <?php endif; ?>

        <div class="notification-menu">
          <button
            type="button"
            id="notificationMenuButton"
            data-slot="button"
            class="items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9 hidden lg:grid"
            aria-expanded="false"
            aria-haspopup="true"
            aria-controls="notificationMenuDropdown"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bell h-5 w-5">
              <path d="M10.268 21a2 2 0 0 0 3.464 0"></path>
              <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"></path>
            </svg>
          </button>

          <span id="notificationBadge" class="notification-menu__badge" aria-hidden="true"></span>

          <div
            id="notificationMenuDropdown"
            class="notification-menu__dropdown"
            role="menu"
            aria-labelledby="notificationMenuButton"
          >
            <strong class="notification-menu__title">Notifications</strong>
            <div class="notification-menu__list" id="notificationMenuList">
              <p class="notification-menu__item">Chargement…</p>
            </div>
          </div>
        </div>

        <button
          type="button"
          id="themeToggleButton"
          data-slot="button"
          class="theme-toggle items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&amp;_svg]:pointer-events-none [&amp;_svg:not([class*=&#x27;size-&#x27;])]:size-4 shrink-0 [&amp;_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50 size-9 grid place-items-center"
          aria-label="Activer le mode sombre"
          title="Activer le mode sombre"
          aria-pressed="false"
        >
          <svg class="theme-toggle__icon theme-toggle__icon--sun h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M12 3V4M12 20V21M4 12H3M6.31412 6.31412L5.5 5.5M17.6859 6.31412L18.5 5.5M6.31412 17.69L5.5 18.5001M17.6859 17.69L18.5 18.5001M21 12H20M16 12C16 14.2091 14.2091 16 12 16C9.79086 16 8 14.2091 8 12C8 9.79086 9.79086 8 12 8C14.2091 8 16 9.79086 16 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
          <svg class="theme-toggle__icon theme-toggle__icon--moon h-5 w-5" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M13 6V3M18.5 12V7M14.5 4.5H11.5M21 9.5H16M15.5548 16.8151C16.7829 16.8151 17.9493 16.5506 19 16.0754C17.6867 18.9794 14.7642 21 11.3698 21C6.74731 21 3 17.2527 3 12.6302C3 9.23576 5.02061 6.31331 7.92462 5C7.44944 6.05072 7.18492 7.21708 7.18492 8.44523C7.18492 13.0678 10.9322 16.8151 15.5548 16.8151Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
        </button>

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
        <div class="lang-menu" data-current-lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
          <button
            type="button"
            id="langMenuButton"
            class="lang-menu__trigger mr-1"
            aria-expanded="false"
            aria-haspopup="true"
            aria-controls="langMenuDropdown"
            aria-label="<?php echo htmlspecialchars($langMenuTitle . ' : ' . ($langNames[$currentLang] ?? strtoupper($currentLang)), ENT_QUOTES, 'UTF-8'); ?>"
          >
            <span class="lang-menu__flag"><?php echo $langFlags[$currentLang] ?? ''; ?></span>
            <svg class="lang-menu__caret" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="m6 9 6 6 6-6"></path>
            </svg>
          </button>

          <div
            id="langMenuDropdown"
            class="notification-menu__dropdown lang-menu__dropdown"
            role="menu"
            aria-labelledby="langMenuButton"
          >
            <strong class="notification-menu__title"><?php echo htmlspecialchars($langMenuTitle, ENT_QUOTES, 'UTF-8'); ?></strong>
            <div class="lang-menu__list">
              <?php foreach ($langSupported as $code): ?>
                <?php
                  $isActive = ($code === $currentLang);
                  $flag     = $langFlags[$code] ?? '';
                  $name     = $langNames[$code] ?? strtoupper($code);
                ?>
                <a
                  class="lang-menu__item<?php echo $isActive ? ' is-active' : ''; ?>"
                  href="<?php echo build_lang_switch_url($code); ?>"
                  hreflang="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                  lang="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
                  role="menuitemradio"
                  aria-checked="<?php echo $isActive ? 'true' : 'false'; ?>"
                >
                  <span class="lang-menu__flag"><?php echo $flag; ?></span>
                  <span class="lang-menu__label"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php if ($isActive): ?><span class="lang-menu__check" aria-hidden="true">&#10003;</span><?php endif; ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="user-menu">
          <button
            type="button"
            id="userMenuButton"
            class="user-menu__trigger"
            aria-expanded="false"
            aria-haspopup="true"
            aria-controls="userMenuDropdown"
          >
            <span data-slot="avatar" class="relative flex size-8 shrink-0 overflow-hidden rounded-full h-8 w-8">
              <span data-slot="avatar-fallback" class="user-menu__avatar bg-muted flex size-full items-center justify-center rounded-full">
                <?php echo htmlspecialchars($menuInitial, ENT_QUOTES, 'UTF-8'); ?>
              </span>
            </span>
          </button>

          <div
            id="userMenuDropdown"
            class="user-menu__dropdown"
            role="menu"
            aria-labelledby="userMenuButton"
          >
            <span
              class="user-menu__username"
              title="<?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?>"
            >
              <?php echo htmlspecialchars($menuUsername, ENT_QUOTES, 'UTF-8'); ?>
            </span>

            <div class="user-menu__actions">
              <a href="https://auth.gnl-solution.fr/auth/realms/client-auth/account/" class="priority-item flex w-full items-center gap-2 rounded-sm px-3 py-2 text-left text-sm outline-none transition-all hover:bg-accent hover:text-accent-foreground" role="menuitem">Paramètres</a>
              <a href="/deconnexion" class="priority-item flex w-full items-center gap-2 rounded-sm px-3 py-2 text-left text-sm outline-none transition-all hover:bg-accent hover:text-accent-foreground" role="menuitem">Déconnexion</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </nav>
</div>

<!--$--><!--/$-->
<section aria-label="Notifications alt+T" tabindex="-1" aria-live="polite" aria-relevant="additions text" aria-atomic="false"></section>

<script>
  window.NOTIF_API = window.NOTIF_API || '/data/notifications_api.php';
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

  const API     = window.NOTIF_API || '/data/notifications_api.php';
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
      return n.link
        ? '<a class="' + cls + '" href="' + esc(n.link) + '">' + inner + '</a>'
        : '<div class="' + cls + '">' + inner + '</div>';
    }).join('');
  }

  async function load() {
    try {
      const res = await fetch(API + '?action=list', {
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

  async function markAllRead() {
    if (unread <= 0) return;
    try {
      const res = await fetch(API + '?action=read', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': CSRF,
          'Content-Type': 'application/x-www-form-urlencoded',
          'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: 'all=1'
      });
      const data = await res.json().catch(() => null);
      if (res.ok && data && data.ok) {
        unread = 0;
        items = items.map((n) => Object.assign({}, n, { is_read: true }));
        updateBadge();
        render();
      }
    } catch (_e) { /* sera retenté au prochain cycle */ }
  }

  function openMenu() {
    dropdown.classList.add('is-open');
    button.setAttribute('aria-expanded', 'true');
    markAllRead();
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