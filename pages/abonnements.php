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

// Barre de recherche du header (include/header.php) : activée pour cette page.
// Le champ porte l'id ci-dessous ; le JS en bas de page y branche le filtrage
// du tableau (les données proviennent de data/portail_api.php → n8n).
$showSearch        = true;
$searchInputId     = 'subscriptionsSearchInput';
$searchPlaceholder = t('Rechercher un abonnement…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Mes abonnements - GNL Solution') ?></title>
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

    .subscriptions-table-wrap{overflow:auto;}
    .subscriptions-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
    .subscriptions-table th,.subscriptions-table td{padding:0.9rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.92rem;white-space:nowrap;}
    .subscriptions-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;color:var(--muted-foreground, #64748b);text-align:left;}
    .subscriptions-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .subscriptions-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);white-space:normal;}
    .subscriptions-state--error td{color:#b91c1c;}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.2rem .6rem;font-size:.75rem;font-weight:600;}

    .collapsible-content {overflow:hidden;height:0;opacity:0;transition:height 220ms ease, opacity 220ms ease;will-change:height, opacity;}
    .collapsible-content.is-open {opacity:1;}
    .collapsible-trigger .collapsible-chevron {transition:transform 220ms ease;will-change:transform;}
    .collapsible-trigger[aria-expanded="true"] .collapsible-chevron {transform:rotate(90deg);}

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
              <h1 class="text-xl font-bold"><?= t('Mes abonnements') ?></h1>
              <p class="text-sm text-muted-foreground mt-1"><?= t('Suivi de vos abonnements.') ?></p>
            </div>
            <span id="subscriptionsCount" class="text-sm text-muted-foreground"
                  data-suffix="<?php echo h(t('abonnement(s)')); ?>"></span>
          </div>

          <div class="subscriptions-table-wrap px-2 md:px-6">
            <table class="subscriptions-table">
              <thead>
                <tr>
                  <th><?= t('Référence') ?></th>
                  <th><?= t('Libellé') ?></th>
                  <th><?= t('Date de début') ?></th>
                  <th><?= t('Prochaine échéance') ?></th>
                  <th>Fréquence</th>
                  <th><?= t('Montant') ?></th>
                  <th><?= t('Statut') ?></th>
                </tr>
              </thead>
              <tbody id="subscriptionsTableBody">
                <tr class="subscriptions-state">
                  <td colspan="7"><?= t('Chargement des abonnements…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
        </main>

    </div>

  <script>
  (function () {
    function ready(fn){ if(document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    ready(function () {
      var triggers = document.querySelectorAll('[data-slot="collapsible-trigger"]');
      triggers.forEach(function (btn) {
        btn.classList.add('collapsible-trigger');
        var targetId = btn.getAttribute('aria-controls');
        var content = targetId ? document.getElementById(targetId) : null;
        if (!content) {
          var parent = btn.closest('[data-slot="collapsible"]');
          if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
        }
        if (!content) return;

        content.classList.add('collapsible-content');
        var chev = btn.querySelector('.lucide-chevron-right');
        if (chev) chev.classList.add('collapsible-chevron');

        var expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
          content.hidden = false;
          content.classList.add('is-open');
          content.style.height = 'auto';
        } else {
          content.hidden = true;
          content.classList.remove('is-open');
          content.style.height = '0px';
        }

        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var isOpen = btn.getAttribute('aria-expanded') === 'true';

          if (!isOpen) {
            btn.setAttribute('aria-expanded', 'true');
            content.hidden = false;
            content.classList.add('is-open');
            content.style.height = '0px';
            var h = content.scrollHeight;
            requestAnimationFrame(function () { content.style.height = h + 'px'; });
            content.addEventListener('transitionend', function onEnd(ev) {
              if (ev.propertyName !== 'height') return;
              content.style.height = 'auto';
              content.removeEventListener('transitionend', onEnd);
            });
          } else {
            btn.setAttribute('aria-expanded', 'false');
            content.classList.remove('is-open');
            var current = content.scrollHeight;
            content.style.height = current + 'px';
            requestAnimationFrame(function () { content.style.height = '0px'; });
            content.addEventListener('transitionend', function onEndClose(ev) {
              if (ev.propertyName !== 'height') return;
              content.hidden = true;
              content.removeEventListener('transitionend', onEndClose);
            });
          }
        }, { passive: false });
      });
    });
  })();
  </script>

  <!-- Données des abonnements via data/portail_api.php (→ n8n) + recherche du header -->
  <script>
    window.SUBSCRIPTIONS_API_URL = window.SUBSCRIPTIONS_API_URL || "../data/portail_api.php";
    window.SUBSCRIPTIONS_I18N = {
      loading:   <?= json_encode(t('Chargement des abonnements…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:     <?= json_encode(t('Aucun abonnement trouvé pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults: <?= json_encode(t('Aucun abonnement ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:     <?= json_encode(t('Impossible de charger les abonnements.'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    var I18N = window.SUBSCRIPTIONS_I18N || {};
    var API  = window.SUBSCRIPTIONS_API_URL || "../data/portail_api.php";

    // Minuscules + suppression des accents pour une recherche tolérante.
    function norm(s) {
      return String(s == null ? '' : s).toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
      });
    }

    ready(function () {
      var input   = document.getElementById('subscriptionsSearchInput');
      var tbody   = document.getElementById('subscriptionsTableBody');
      var counter = document.getElementById('subscriptionsCount');
      if (!tbody) return;

      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function setCounter(n) {
        if (!counter) return;
        counter.textContent = (n == null) ? '' : (n + (suffix ? ' ' + suffix : ''));
      }
      function stateRow(text, isError) {
        return '<tr class="subscriptions-state' + (isError ? ' subscriptions-state--error' : '') +
               '"><td colspan="7">' + esc(text) + '</td></tr>';
      }
      function rowHtml(s) {
        var hay = [s.ref, s.label, s.start, s.end, s.frequency, s.amount, s.status_label]
          .join(' ').toLowerCase();
        return '<tr data-search="' + esc(hay) + '">' +
          '<td class="font-medium">' + esc(s.ref) + '</td>' +
          '<td>' + esc(s.label) + '</td>' +
          '<td>' + esc(s.start) + '</td>' +
          '<td>' + esc(s.end) + '</td>' +
          '<td>' + esc(s.frequency) + '</td>' +
          '<td>' + esc(s.amount) + '</td>' +
          '<td><span class="badge ' + esc(s.status_class) + '">' + esc(s.status_label) + '</span></td>' +
        '</tr>';
      }

      function dataRows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));
      }
      function applyFilter() {
        var rows = dataRows();
        if (!rows.length) return;
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var visible = 0;
        rows.forEach(function (row) {
          var hay = norm(row.getAttribute('data-search') || '');
          var match = tokens.every(function (t) { return hay.indexOf(t) !== -1; });
          row.hidden = !match;
          if (match) visible++;
        });
        var noRes = document.getElementById('subscriptionsNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows(list) {
        if (!list.length) {
          tbody.innerHTML = stateRow(I18N.empty || 'Aucun abonnement.', false);
          setCounter(0);
          return;
        }
        var html = list.map(rowHtml).join('') +
          '<tr id="subscriptionsNoResults" class="subscriptions-state" hidden><td colspan="7">' +
          esc(I18N.noResults || '') + '</td></tr>';
        tbody.innerHTML = html;
        setCounter(list.length);
        applyFilter();
      }

      function load() {
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        fetch(API + '?action=subscription.list', {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        })
        .then(function (res) {
          return res.json().catch(function () { return null; }).then(function (data) {
            return { ok: res.ok, data: data };
          });
        })
        .then(function (r) {
          var data = r.data;
          if (!r.ok || !data || !data.ok) {
            var msg = (data && data.error) ? data.error : (I18N.error || 'Erreur.');
            var code = (data && data.code) ? ' (code: ' + data.code + ')' : '';
            tbody.innerHTML = stateRow((I18N.error || 'Erreur') + code + ' ' + msg, true);
            setCounter(null);
            return;
          }
          renderRows(Array.isArray(data.subscriptions) ? data.subscriptions : []);
        })
        .catch(function () {
          tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les abonnements.', true);
          setCounter(null);
        });
      }

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter); // croix « effacer » du type=search
      }

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