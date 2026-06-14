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
$searchInputId     = 'invoicesSearchInput';
$searchPlaceholder = t('Rechercher une facture…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Mes factures - GNL Solution') ?></title>
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

    .invoices-table-wrap{overflow:auto;}
    .invoices-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
    .invoices-table th,.invoices-table td{padding:0.9rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.92rem;white-space:nowrap;}
    .invoices-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;color:var(--muted-foreground, #64748b);text-align:left;}
    .invoices-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .invoices-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);white-space:normal;}
    .invoices-state--error td{color:#b91c1c;}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.2rem .6rem;font-size:.75rem;font-weight:600;}
    .download-btn{display:inline-flex;align-items:center;justify-content:center;border-radius:.55rem;padding:.35rem .7rem;border:1px solid rgba(148,163,184,.35);font-size:.8rem;font-weight:600;transition:all .15s ease;text-decoration:none;}
    .download-btn:hover{background:rgba(148,163,184,.12);}
    .download-btn.is-disabled{opacity:.45;cursor:not-allowed;pointer-events:none;}

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
              <h1 class="text-xl font-bold"><?= t('Mes factures') ?></h1>
              <p class="text-sm text-muted-foreground mt-1"><?= t('Suivi de vos factures et téléchargement des PDF.') ?></p>
            </div>
            <span id="invoicesCount" class="text-sm text-muted-foreground"
                  data-suffix="<?php echo h(t('facture(s)')); ?>"></span>
          </div>

          <div class="invoices-table-wrap px-2 md:px-6">
            <table class="invoices-table">
              <thead>
                <tr>
                  <th><?= t('Référence') ?></th>
                  <th><?= t('Date') ?></th>
                  <th><?= t('Échéance') ?></th>
                  <th><?= t('Statut') ?></th>
                  <th><?= t('Total HT') ?></th>
                  <th><?= t('Total TTC') ?></th>
                  <th><?= t('Reste à payer') ?></th>
                  <th>Téléchargement</th>
                </tr>
              </thead>
              <tbody id="invoicesTableBody">
                <tr class="invoices-state">
                  <td colspan="8"><?= t('Chargement des factures…') ?></td>
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

  <!-- Données des factures via data/portail_api.php (→ n8n) + recherche du header -->
  <script>
    window.INVOICES_API_URL = window.INVOICES_API_URL || "../data/portail_api.php";
    window.INVOICES_I18N = {
      loading:        <?= json_encode(t('Chargement des factures…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:          <?= json_encode(t('Aucune facture trouvée pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults:      <?= json_encode(t('Aucune facture ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:          <?= json_encode(t('Impossible de charger les factures.'), JSON_UNESCAPED_UNICODE) ?>,
      download:       <?= json_encode('Télécharger PDF', JSON_UNESCAPED_UNICODE) ?>,
      pdfUnavailable: <?= json_encode(t('PDF indisponible'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    var I18N = window.INVOICES_I18N || {};
    var API  = window.INVOICES_API_URL || "../data/portail_api.php";

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
      var input   = document.getElementById('invoicesSearchInput');
      var tbody   = document.getElementById('invoicesTableBody');
      var counter = document.getElementById('invoicesCount');
      if (!tbody) return;

      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function setCounter(n) {
        if (!counter) return;
        counter.textContent = (n == null) ? '' : (n + (suffix ? ' ' + suffix : ''));
      }
      function stateRow(text, isError) {
        return '<tr class="invoices-state' + (isError ? ' invoices-state--error' : '') +
               '"><td colspan="8">' + esc(text) + '</td></tr>';
      }
      function downloadCell(inv) {
        if (inv.has_pdf && inv.download_url) {
          return '<a class="download-btn" href="' + esc(inv.download_url) + '">' +
                 esc(I18N.download || 'Télécharger PDF') + '</a>';
        }
        return '<span class="download-btn is-disabled">' + esc(I18N.pdfUnavailable || 'PDF indisponible') + '</span>';
      }
      function rowHtml(inv) {
        var hay = [inv.ref, inv.date, inv.due, inv.status_label, inv.total_ht, inv.total_ttc, inv.remaining]
          .join(' ').toLowerCase();
        return '<tr data-search="' + esc(hay) + '">' +
          '<td class="font-medium">' + esc(inv.ref) + '</td>' +
          '<td>' + esc(inv.date) + '</td>' +
          '<td>' + esc(inv.due) + '</td>' +
          '<td><span class="badge ' + esc(inv.status_class) + '">' + esc(inv.status_label) + '</span></td>' +
          '<td>' + esc(inv.total_ht) + '</td>' +
          '<td>' + esc(inv.total_ttc) + '</td>' +
          '<td>' + esc(inv.remaining) + '</td>' +
          '<td>' + downloadCell(inv) + '</td>' +
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
        var noRes = document.getElementById('invoicesNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows(list) {
        if (!list.length) {
          tbody.innerHTML = stateRow(I18N.empty || 'Aucune facture.', false);
          setCounter(0);
          return;
        }
        var html = list.map(rowHtml).join('') +
          '<tr id="invoicesNoResults" class="invoices-state" hidden><td colspan="8">' +
          esc(I18N.noResults || '') + '</td></tr>';
        tbody.innerHTML = html;
        setCounter(list.length);
        applyFilter();
      }

      function load() {
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        fetch(API + '?action=invoice.list', {
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
          renderRows(Array.isArray(data.invoices) ? data.invoices : []);
        })
        .catch(function () {
          tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les factures.', true);
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