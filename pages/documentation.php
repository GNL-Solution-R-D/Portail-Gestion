<?php

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit();
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ── Barre de recherche du header ──────────────────────────────────────────────
// On active la barre du header.php et on personnalise son libellé. Le JS de cette
// page se branche dessus via son id (globalSearchInput) pour filtrer la liste.
$showSearch        = true;
$searchPlaceholder = t('Rechercher dans la documentation…');

// Chaînes traduites passées au JS (le rendu des cartes se fait côté client).
$docI18n = [
    'updated'      => t('Mise à jour :'),
    'articles'     => t('article(s)'),
    'see_solution' => t('Voir la solution'),
    'load_error'   => t('Impossible de charger la documentation'),
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Documentation - GNL Solution') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>

  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}

    .docs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;}
    .doc-item{display:flex;flex-direction:column;gap:.65rem;border:1px solid rgba(148,163,184,.25);border-radius:.85rem;padding:1rem;background:rgba(255,255,255,.03);}
    .doc-item h3{font-size:1rem;line-height:1.35;margin:0;}
    .doc-item p{margin:0;font-size:.9rem;color:var(--muted-foreground,#64748b);line-height:1.5;}
    .doc-meta{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;font-size:.78rem;color:var(--muted-foreground,#64748b);}
    .doc-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:999px;font-size:.72rem;font-weight:700;background:rgba(59,130,246,.12);color:rgb(96,165,250);}
    .doc-content{white-space:pre-line;font-size:.9rem;color:var(--foreground,#e2e8f0);line-height:1.6;}
    .doc-item.is-hidden{display:none;}

    .search-wrap{position:relative;max-width:520px;}
    .search-input{width:100%;border:1px solid rgba(148,163,184,.28);border-radius:.7rem;background:transparent;padding:.6rem .85rem;font-size:.92rem;outline:none;}
    .search-input:focus{border-color:rgba(96,165,250,.65);box-shadow:0 0 0 3px rgba(59,130,246,.22);}

    .empty-state{border:1px dashed rgba(148,163,184,.36);border-radius:.8rem;padding:1.4rem;text-align:center;color:var(--muted-foreground,#64748b);font-size:.92rem;}

    .collapsible-content{overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open{opacity:1;}
    .collapsible-trigger .collapsible-chevron{transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron{transform:rotate(90deg);}

    @media (max-width:1200px){
      .docs-grid{grid-template-columns:1fr;}
    }

    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main{padding:1rem;}
    }
  </style>
</head>
<body class="bg-background text-foreground">
  <?php include('../include/header.php'); ?>

  <div class="dashboard-layout">
    <aside class="dashboard-sidebar">
      <?php include('../include/menu.php'); ?>
    </aside>

    <main class="dashboard-main">
      <div class="app-shell-offset-min-height w-full bg-surface p-6">
        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold"><?= t('Documentation') ?></h1>
              <p class="text-sm text-muted-foreground mt-1"><?= t('Base de connaissance synchronisée automatiquement.') ?></p>
            </div>
            <span class="text-sm text-muted-foreground" id="doc-count">—&nbsp;<?= t('article(s)') ?></span>
          </div>

          <!-- Repli mobile : la barre de recherche du header est masquée sous md.
               On propose donc le même filtrage ici sur petit écran. Les deux champs
               restent synchronisés. -->
          <div class="px-6 pb-1 md:hidden">
            <label class="sr-only" for="docs-search"><?= t('Rechercher un article') ?></label>
            <div class="search-wrap">
              <input id="docs-search" data-doc-search-input class="search-input" type="search"
                     placeholder="<?= t('Rechercher dans la documentation…') ?>" autocomplete="off"/>
            </div>
          </div>

          <!-- Erreur de chargement (renseignée par le JS depuis l'API). -->
          <div id="docs-error" class="mx-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 ml-8 mr-8" hidden></div>

          <!-- État de chargement initial. -->
          <div id="docs-loading" class="mx-6 mb-2 empty-state"><?= t('Chargement…') ?></div>

          <!-- Grille des articles (remplie par le JS). -->
          <div class="docs-grid px-6 pb-1" id="docs-list"></div>

          <!-- Aucune donnée disponible. -->
          <div class="mx-6 mb-2 empty-state" id="docs-empty" hidden>
            <?= t('Aucun article trouvé dans votre base de connaissance.') ?>
          </div>

          <!-- Aucun résultat pour la recherche en cours. -->
          <div class="mx-6 mb-2 empty-state" id="docs-empty-search" hidden>
            <?= t('Aucun résultat pour votre recherche.') ?>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    window.DOC_API  = "../data/portail_api.php";
    window.DOC_I18N = <?php echo json_encode($docI18n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  </script>

  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
      var API   = window.DOC_API || '../data/portail_api.php';
      var I18N   = window.DOC_I18N || {};

      var listEl        = document.getElementById('docs-list');
      var countEl       = document.getElementById('doc-count');
      var loadingEl     = document.getElementById('docs-loading');
      var errorEl       = document.getElementById('docs-error');
      var emptyEl       = document.getElementById('docs-empty');
      var emptySearchEl = document.getElementById('docs-empty-search');

      // Champs de recherche : la barre du header (#globalSearchInput) + repli mobile.
      var searchInputs = Array.prototype.slice.call(
        document.querySelectorAll('#globalSearchInput, [data-doc-search-input]')
      );

      if (!listEl) return;

      var allArticles  = [];
      var currentQuery = '';

      function tr(key, fallback){ return (I18N && I18N[key]) ? I18N[key] : fallback; }

      function esc(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
      }
      function nl2br(s){ return esc(s).replace(/\n/g, '<br>'); }

      function cardHtml(a) {
        var meta =
          '<div class="doc-meta">' +
            '<span class="doc-badge">' + esc(a.category) + '</span>' +
            '<span>' + esc(tr('updated', 'Mise à jour :')) + ' ' + esc(a.updated_at) + '</span>' +
          '</div>';

        var title   = '<h3>\u2753 ' + esc(a.title) + '</h3>';
        var summary = a.summary ? '<p>' + esc(a.summary) + '</p>' : '';

        var solution = '';
        if (a.content_html || a.content) {
          // content_html est déjà filtré côté serveur (liste blanche de balises).
          var inner = a.content_html ? a.content_html : nl2br(a.content);
          solution =
            '<details>' +
              '<summary class="cursor-pointer text-sm font-semibold text-blue-400">' +
                esc(tr('see_solution', 'Voir la solution')) +
              '</summary>' +
              '<div class="doc-content mt-3">' + inner + '</div>' +
            '</details>';
        }

        return '<article class="doc-item">' + meta + title + summary + solution + '</article>';
      }

      function setCount(n) {
        if (countEl) countEl.textContent = n + '\u00A0' + tr('articles', 'article(s)');
      }

      function applyFilter() {
        var raw = currentQuery.toLowerCase().trim();

        var filtered = !raw ? allArticles : allArticles.filter(function (a) {
          var idx = (a.title + ' ' + a.category + ' ' + a.summary + ' ' + a.content).toLowerCase();
          return idx.indexOf(raw) !== -1;
        });

        setCount(filtered.length);

        // Aucune donnée du tout.
        if (!allArticles.length) {
          listEl.innerHTML = '';
          if (emptyEl) emptyEl.hidden = false;
          if (emptySearchEl) emptySearchEl.hidden = true;
          return;
        }
        if (emptyEl) emptyEl.hidden = true;

        // Données présentes mais aucun résultat pour la recherche.
        if (!filtered.length) {
          listEl.innerHTML = '';
          if (emptySearchEl) emptySearchEl.hidden = false;
          return;
        }
        if (emptySearchEl) emptySearchEl.hidden = true;

        listEl.innerHTML = filtered.map(cardHtml).join('');
      }

      function showError(msg, code) {
        if (loadingEl) loadingEl.hidden = true;
        if (!errorEl) return;
        var txt = tr('load_error', 'Impossible de charger la documentation');
        if (code) txt += ' (code: ' + code + ')';
        txt += '.';
        if (msg) txt += ' ' + msg;
        errorEl.textContent = txt;
        errorEl.hidden = false;
      }

      function load() {
        fetch(API + '?action=documentation.list', {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        }).then(function (res) {
          return res.json().then(function (data) { return { res: res, data: data }; })
                            .catch(function () { return { res: res, data: null }; });
        }).then(function (r) {
          if (loadingEl) loadingEl.hidden = true;
          var res = r.res, data = r.data;
          if (!res.ok || !data || !data.ok) {
            showError((data && data.error) || ('HTTP ' + res.status), data && data.code);
            return;
          }
          allArticles = Array.isArray(data.articles) ? data.articles : [];
          applyFilter();
        }).catch(function (e) {
          showError(String(e && e.message ? e.message : e));
        });
      }

      // Branche chaque champ de recherche et garde-les synchronisés.
      searchInputs.forEach(function (input) {
        input.addEventListener('input', function () {
          currentQuery = input.value || '';
          searchInputs.forEach(function (other) {
            if (other !== input && other.value !== input.value) other.value = input.value;
          });
          applyFilter();
        }, { passive: true });
      });

      load();
    });
  })();
  </script>

  <script>
    window.K8S_API_URL = "../data/k8s_api.php";
    window.K8S_UI_BASE = "./pages/";
  </script>
  <script src="../assets/js/k8s_menu.js" defer></script>
</body>
</html>