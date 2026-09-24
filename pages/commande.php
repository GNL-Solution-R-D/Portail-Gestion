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
// du tableau. Même page que /commande du portail client, mais pour TOUS les
// clients : data/portail_api.php → API Mollie (GET /v2/payments), chaque
// paiement étant rattaché à l'entreprise Keycloak dont l'attribut
// d'organisation « moliecliid » porte son client Mollie (cst_…).
$showSearch        = true;
$searchInputId     = 'ordersSearchInput';
$searchPlaceholder = t('Rechercher une commande ou un client…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Commandes - GNL Solution') ?></title>
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

    .orders-table-wrap{overflow:auto;}
    .orders-table{width:100%;border-collapse:separate;border-spacing:0;min-width:880px;}
    .orders-table th,.orders-table td{padding:0.9rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.92rem;white-space:nowrap;}
    .orders-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;color:var(--muted-foreground, #64748b);text-align:left;}
    .orders-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .orders-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);white-space:normal;}
    .orders-state--error td{color:#b91c1c;}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.2rem .6rem;font-size:.75rem;font-weight:600;}

    /* ── Ligne dépliable ──────────────────────────────────────────────── */
    .order-row{cursor:pointer;}
    .order-row:focus-visible{outline:2px solid var(--gnl-teal, #009494);outline-offset:-2px;}
    .order-caret{display:inline-block;width:1.1em;color:var(--muted-foreground, #64748b);
                 transition:transform 200ms ease;transform-origin:50% 50%;}
    .order-row.is-open .order-caret{transform:rotate(90deg);}
    .order-row.is-open > td{border-bottom-color:transparent;background:rgba(148,163,184,.08);}

    .order-detail-cell{padding:0 !important;background:rgba(148,163,184,.06);}
    .order-detail-anim{overflow:hidden;height:0;transition:height 220ms ease;will-change:height;}
    .order-detail-body{padding:1rem 1.25rem 1.25rem;}

    .order-detail-table{width:100%;border-collapse:collapse;min-width:0;}
    .order-detail-table th,.order-detail-table td{padding:.45rem .6rem;font-size:.85rem;
                 border-bottom:1px solid rgba(148,163,184,.18);white-space:nowrap;}
    .order-detail-table th{font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;
                 color:var(--muted-foreground, #64748b);text-align:left;}
    .order-detail-table .num{text-align:right;}
    .order-detail-table .strong{font-weight:600;}
    .order-line-option td{color:var(--muted-foreground, #64748b);font-size:.8rem;border-bottom-style:dashed;}
    .opt-mark{display:inline-block;width:1.2em;opacity:.55;}
    .opt-tag{display:inline-block;margin-left:.4rem;padding:.05rem .35rem;border-radius:.35rem;
             background:rgba(148,163,184,.2);font-size:.7rem;}

    .order-detail-total{display:flex;justify-content:flex-end;gap:1.5rem;padding:.35rem .6rem;font-size:.85rem;}
    .order-detail-total span{color:var(--muted-foreground, #64748b);}
    .order-detail-total.is-main{font-size:.95rem;padding-top:.6rem;margin-top:.3rem;
                 border-top:1px solid rgba(148,163,184,.25);}
    .order-detail-meta{text-align:right;padding:.35rem .6rem 0;font-size:.78rem;
                 color:var(--muted-foreground, #64748b);}
    .order-detail-meta.is-warn{color:#b45309;}
    .order-detail-empty{margin:0;padding:.5rem .6rem;font-size:.85rem;color:var(--muted-foreground, #64748b);}
    .order-detail-empty.is-error{color:#b91c1c;}

    /* Console support : colonne + filtre « Client » */
    .orders-toolbar{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
    .orders-toolbar select{height:2.25rem;min-width:14rem;max-width:100%;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;background:var(--background,#fff);color:inherit;padding:0 .75rem;font-size:.875rem;}
    .orders-btn{display:inline-flex;align-items:center;height:2.25rem;padding:0 .75rem;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;font-size:.875rem;font-weight:500;background:var(--background,#fff);color:inherit;cursor:pointer;}
    .orders-btn:hover{background:var(--secondary,#f1f5f9);}
    .orders-notice{margin:0 1.5rem;padding:.75rem 1rem;border-radius:.5rem;font-size:.875rem;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.1);color:#b45309;}
    .order-client{display:flex;flex-direction:column;gap:.1rem;white-space:normal;min-width:8rem;max-width:13rem;}
    .order-client-name{font-weight:500;}
    .order-client-id{font-size:.72rem;color:var(--muted-foreground,#64748b);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}
    .order-client-tag{display:inline-block;margin-left:.35rem;padding:0 .35rem;border-radius:.25rem;font-size:.68rem;font-weight:500;white-space:nowrap;background:rgba(148,163,184,.18);color:var(--muted-foreground,#64748b);}
    .orders-table td.col-desc{white-space:normal;min-width:8rem;}

    @media (prefers-reduced-motion:reduce){
      .order-detail-anim{transition:none;}
      .order-caret{transition:none;}
    }

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
  <?php require_once '../include/org_filter.php'; // filtre ?org=<uuid> posé par /entreprises ?>
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
              <h1 class="text-xl font-bold"><?= t('Commandes') ?></h1>
              <p class="text-sm text-muted-foreground mt-1"><?= t('Suivi des commandes de tous les clients.') ?></p>
            </div>
            <span id="ordersCount" class="text-sm text-muted-foreground"
                  data-suffix="<?php echo h(t('commande(s)')); ?>"></span>
          </div>

          <div class="px-6 orders-toolbar">
            <label for="ordersClientFilter" class="sr-only"><?= t('Client') ?></label>
            <select id="ordersClientFilter" disabled>
              <option value=""><?= t('Tous les clients') ?></option>
            </select>
            <button type="button" class="orders-btn" id="ordersReset" hidden><?= t('Réinitialiser') ?></button>
          </div>

          <div id="ordersNotice" class="orders-notice" role="status" hidden></div>

          <div class="orders-table-wrap px-2 md:px-6">
            <table class="orders-table">
              <thead>
                <tr>
                  <th><?= t('Client') ?></th>
                  <th><?= t('Référence') ?></th>
                  <th><?= t('Description') ?></th>
                  <th><?= t('Date') ?></th>
                  <th><?= t('Statut') ?></th>
                  <th><?= t('Montant') ?></th>
                  <th><?= t('Fréquence') ?></th>
                </tr>
              </thead>
              <tbody id="ordersTableBody">
                <tr class="orders-state">
                  <td colspan="7"><?= t('Chargement des commandes…') ?></td>
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

  <!-- Données des commandes (tous clients) via data/portail_api.php (→ API Mollie) + filtre client + recherche du header -->
  <script>
    window.ORDERS_API_URL = window.ORDERS_API_URL || "../data/portail_api.php";
    window.ORDERS_I18N = {
      loading:   <?= json_encode(t('Chargement des commandes…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:     <?= json_encode(t('Aucune commande trouvée pour le moment.'), JSON_UNESCAPED_UNICODE) ?>,
      notLinked: <?= json_encode(t('Aucun client Mollie n\'est associé à cette entreprise (attribut d\'organisation « moliecliid » absent dans Keycloak).'), JSON_UNESCAPED_UNICODE) ?>,
      truncated: <?= json_encode(t('Seules les 1000 commandes les plus récentes sont affichées.'), JSON_UNESCAPED_UNICODE) ?>,
      allClients: <?= json_encode(t('Tous les clients'), JSON_UNESCAPED_UNICODE) ?>,
      notLinkedTag: <?= json_encode(t('non rattaché'), JSON_UNESCAPED_UNICODE) ?>,
      notLinkedTitle: <?= json_encode(t('Client Mollie sans entreprise Keycloak (attribut « moliecliid »)'), JSON_UNESCAPED_UNICODE) ?>,
      method:    <?= json_encode(t('Moyen de paiement'), JSON_UNESCAPED_UNICODE) ?>,
      paidAt:    <?= json_encode(t('Payée le'), JSON_UNESCAPED_UNICODE) ?>,
      refunded:  <?= json_encode(t('Remboursé'), JSON_UNESCAPED_UNICODE) ?>,
      chargedBack: <?= json_encode(t('Rejeté par la banque'), JSON_UNESCAPED_UNICODE) ?>,
      noResults: <?= json_encode(t('Aucune commande ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:     <?= json_encode(t('Impossible de charger les commandes.'), JSON_UNESCAPED_UNICODE) ?>,

      // Panneau de détail (order.detail)
      detailLoading: <?= json_encode(t('Chargement du détail…'), JSON_UNESCAPED_UNICODE) ?>,
      detailEmpty:   <?= json_encode(t('Aucune ligne pour cette commande.'), JSON_UNESCAPED_UNICODE) ?>,
      detailError:   <?= json_encode(t('Détail indisponible.'), JSON_UNESCAPED_UNICODE) ?>,
      product:       <?= json_encode(t('Produit'), JSON_UNESCAPED_UNICODE) ?>,
      qty:           <?= json_encode(t('Qté'), JSON_UNESCAPED_UNICODE) ?>,
      unitPrice:     <?= json_encode(t('Prix'), JSON_UNESCAPED_UNICODE) ?>,
      lineTotal:     <?= json_encode(t('Sous-total'), JSON_UNESCAPED_UNICODE) ?>,
      perPeriod:     <?= json_encode(t('Total par période'), JSON_UNESCAPED_UNICODE) ?>,
      oneOff:        <?= json_encode(t('frais unique'), JSON_UNESCAPED_UNICODE) ?>,
      oneOffTotal:   <?= json_encode(t('Frais uniques'), JSON_UNESCAPED_UNICODE) ?>,
      billed:        <?= json_encode(t('Facturé'), JSON_UNESCAPED_UNICODE) ?>,
      total:         <?= json_encode(t('Total'), JSON_UNESCAPED_UNICODE) ?>,
      nextRenewal:   <?= json_encode(t('Prochain renouvellement'), JSON_UNESCAPED_UNICODE) ?>,
      computed:      <?= json_encode(t('Total calculé depuis les lignes'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

    var I18N = window.ORDERS_I18N || {};
    var API  = window.ORDERS_API_URL || "../data/portail_api.php";

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
      var input   = document.getElementById('ordersSearchInput');
      var tbody   = document.getElementById('ordersTableBody');
      var counter = document.getElementById('ordersCount');
      var notice  = document.getElementById('ordersNotice');
      var clientSel = document.getElementById('ordersClientFilter');
      var resetBtn  = document.getElementById('ordersReset');
      var COLS = 7;
      if (!tbody) return;

      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function setCounter(n) {
        if (!counter) return;
        counter.textContent = (n == null) ? '' : (n + (suffix ? ' ' + suffix : ''));
      }
      function stateRow(text, isError) {
        return '<tr class="orders-state' + (isError ? ' orders-state--error' : '') +
               '"><td colspan="' + COLS + '">' + esc(text) + '</td></tr>';
      }
      // Montant = premier paiement (champ « amount »). Replis sur total_ttc /
      // total_ht : la page reste lisible si n8n renvoie encore l'ancien format.
      function amountOf(o) {
        return o.amount || o.total_ttc || o.total_ht || '—';
      }
      // Titre au survol : rappelle l'échéance suivante quand elle diffère.
      function amountTitle(o) {
        if (!o.amount_next || o.amount_next === '—') return '';
        if (o.amount_next_raw != null && o.amount_raw != null &&
            o.amount_next_raw === o.amount_raw) return '';
        return 'Échéances suivantes : ' + o.amount_next;
      }

      function clientHtml(o) {
        var tag = o.client_linked ? '' :
          '<span class="order-client-tag" title="' + esc(I18N.notLinkedTitle) + '">' + esc(I18N.notLinkedTag) + '</span>';
        var id = (o.customer_id && o.client_name !== o.customer_id) ? o.customer_id : '';
        return '<div class="order-client"><span class="order-client-name">' + esc(o.client_name) + (o.customer_id ? tag : '') + '</span>' +
               (id ? '<span class="order-client-id">' + esc(id) + '</span>' : '') + '</div>';
      }
      function rowHtml(o) {
        var amount = amountOf(o);
        var freq   = o.frequency_label || '—';
        var title  = amountTitle(o);
        var who    = o.description || o.requester || '—';
        var hay = [o.client_name, o.customer_id, o.ref, who, o.date, o.status_label, amount, freq, o.next_renewal]
                  .join(' ').toLowerCase();
        // La ligne est un bouton : clic ou Entrée/Espace déplie le détail.
        return '<tr class="order-row" data-search="' + esc(hay) + '"' +
               ' data-ref="' + esc(o.ref) + '"' +
               ' data-client="' + esc(o.client_key) + '"' +
               ' tabindex="0" role="button" aria-expanded="false">' +
          '<td>' + clientHtml(o) + '</td>' +
          '<td class="font-medium"><span class="order-caret" aria-hidden="true">▸</span>' + esc(o.ref) + '</td>' +
          '<td class="col-desc">' + esc(who) + '</td>' +
          '<td>' + esc(o.date) + '</td>' +
          '<td><span class="badge ' + esc(o.status_class) + '">' + esc(o.status_label) + '</span></td>' +
          '<td' + (title ? ' title="' + esc(title) + '"' : '') + '>' + esc(amount) + '</td>' +
          '<td>' + esc(freq) + '</td>' +
        '</tr>' +
        // Panneau de détail, inséré entre la ligne et la suivante.
        '<tr class="order-detail-row" hidden>' +
          '<td colspan="' + COLS + '" class="order-detail-cell">' +
            '<div class="order-detail-anim">' +
              '<div class="order-detail-body" role="region"></div>' +
            '</div>' +
          '</td>' +
        '</tr>';
      }

      function dataRows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]'));
      }
      function detailOf(row) {
        var next = row.nextElementSibling;
        return (next && next.classList.contains('order-detail-row')) ? next : null;
      }
      function applyFilter() {
        var rows = dataRows();
        if (!rows.length) return;
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var client = clientSel ? clientSel.value : '';
        if (resetBtn) resetBtn.hidden = !(client || q);
        var visible = 0;
        rows.forEach(function (row) {
          var hay = norm(row.getAttribute('data-search') || '');
          var match = (!client || row.getAttribute('data-client') === client) &&
                      tokens.every(function (t) { return hay.indexOf(t) !== -1; });
          row.hidden = !match;
          // Le panneau suit toujours sa ligne : masqué avec elle, et jamais
          // réaffiché tout seul si la ligne n'était pas dépliée.
          var det = detailOf(row);
          if (det) det.hidden = !match || row.getAttribute('aria-expanded') !== 'true';
          if (match) visible++;
        });
        var noRes = document.getElementById('ordersNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderClients(clients, total) {
        if (!clientSel) return;
        var current = clientSel.value;
        var html = '<option value="">' + esc(I18N.allClients || '') + ' (' + total + ')</option>';
        var found = false;
        clients.forEach(function (c) {
          if (c.key === current) found = true;
          html += '<option value="' + esc(c.key) + '">' + esc(c.name) +
                  (c.linked || c.key === 'none' ? '' : ' — ' + esc(I18N.notLinkedTag || '')) + ' (' + c.count + ')</option>';
        });
        clientSel.innerHTML = html;
        clientSel.value = found ? current : '';
        clientSel.disabled = clients.length < 2;
      }

      function renderRows(list, linked, truncated, clients) {
        renderClients(clients || [], list.length);
        if (!list.length) {
          tbody.innerHTML = stateRow((linked === false ? I18N.notLinked : I18N.empty) || 'Aucune commande.', false);
          setCounter(0);
          return;
        }
        // L'en-tête de commande sert au panneau de détail : on le garde sous la
        // main plutôt que de le redemander à n8n à chaque ouverture.
        ordersByRef = {};
        list.forEach(function (o) { if (o && o.ref) ordersByRef[o.ref] = o; });

        var html = list.map(rowHtml).join('') +
          '<tr id="ordersNoResults" class="orders-state" hidden><td colspan="' + COLS + '">' +
          esc(I18N.noResults || '') + '</td></tr>' +
          (truncated ? '<tr class="orders-state"><td colspan="' + COLS + '">' + esc(I18N.truncated || '') + '</td></tr>' : '');
        tbody.innerHTML = html;
        setCounter(list.length);
        applyFilter();
      }

      // ─────────────────────────────────────────────────────────────────────
      //  Détail d'une commande (order.detail → paiement Mollie)
      // ─────────────────────────────────────────────────────────────────────
      var detailCache = {};   // ref → HTML déjà construit
      var detailBusy  = {};   // ref → requête en cours
      var ordersByRef = {};   // ref → commande issue d'order.list

      // order.detail ne renvoie QUE les lignes : l'en-tête vient de la liste
      // déjà chargée, d'où le second paramètre.
      // Détail d'un paiement Mollie : lignes du paiement (ou sa description),
      // puis total, moyen de paiement, date de paiement, remboursements.
      function detailHtmlMollie(d, order) {
        order = order || {};
        var lines = Array.isArray(d.products) ? d.products : [];
        var pay   = d.payment || {};
        var rows = lines.map(function (p) {
          return '<tr class="order-line">' +
              '<td>' + esc(p.label) + '</td>' +
              '<td class="num">' + esc(p.quantity) + '</td>' +
              '<td class="num">' + esc(p.unit_price) + '</td>' +
              '<td class="num strong">' + esc(p.line_total) + '</td>' +
            '</tr>';
        }).join('');

        var billed = order.frequency_label && order.frequency_label !== 'Paiement unique'
          ? (I18N.billed || 'Facturé') + ' — ' + order.frequency_label
          : (I18N.total || 'Total');
        var foot = '<div class="order-detail-total is-main"><span>' + esc(billed) +
                   '</span><strong>' + esc(order.amount || '—') + '</strong></div>';
        if (pay.method && pay.method !== '—') {
          foot += '<div class="order-detail-meta">' + esc(I18N.method || 'Moyen de paiement') + ' : ' + esc(pay.method) + '</div>';
        }
        if (pay.paid_at && pay.paid_at !== '—') {
          foot += '<div class="order-detail-meta">' + esc(I18N.paidAt || 'Payée le') + ' ' + esc(pay.paid_at) + '</div>';
        }
        if (pay.refunded) {
          foot += '<div class="order-detail-meta is-warn">' + esc(I18N.refunded || 'Remboursé') + ' : ' + esc(pay.refunded) + '</div>';
        }
        if (pay.charged_back) {
          foot += '<div class="order-detail-meta is-warn">' + esc(I18N.chargedBack || 'Rejeté') + ' : ' + esc(pay.charged_back) + '</div>';
        }
        if (order.next_renewal && order.next_renewal !== '—') {
          foot += '<div class="order-detail-meta">' + esc(I18N.nextRenewal || 'Prochain renouvellement') + ' : ' + esc(order.next_renewal) + '</div>';
        }

        return '<table class="order-detail-table">' +
            '<thead><tr>' +
              '<th>' + esc(I18N.product || 'Produit') + '</th>' +
              '<th class="num">' + esc(I18N.qty || 'Qté') + '</th>' +
              '<th class="num">' + esc(I18N.unitPrice || 'Prix') + '</th>' +
              '<th class="num">' + esc(I18N.lineTotal || 'Sous-total') + '</th>' +
            '</tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
          '</table>' + foot;
      }

      function detailHtml(d, order) {
        if (d && d.source === 'mollie') return detailHtmlMollie(d, order);
        order = order || {};
        var products = Array.isArray(d.products) ? d.products : [];
        var extras   = Array.isArray(d.extra_options) ? d.extra_options : [];
        var totals   = d.totals || {};

        if (!products.length && !extras.length) {
          // Panneau vide : dire si c'est un échec n8n plutôt qu'une commande
          // réellement sans ligne.
          if (d.lines_warning) {
            return '<p class="order-detail-empty is-error">' +
                   esc(I18N.detailError || 'Détail indisponible.') + ' — ' +
                   esc(String(d.lines_warning)) + '</p>';
          }
          return '<p class="order-detail-empty">' +
                 esc(I18N.detailEmpty || 'Aucune ligne pour cette commande.') + '</p>';
        }

        var rows = products.map(function (p) {
          var head =
            '<tr class="order-line">' +
              '<td>' + esc(p.label || p.slug) + '</td>' +
              '<td class="num">' + esc(p.quantity) + '</td>' +
              '<td class="num">' + esc(p.unit_price) + '</td>' +
              '<td class="num strong">' + esc(p.line_total) + '</td>' +
            '</tr>';
          var opts = (p.options || []).map(function (o) {
            return '<tr class="order-line-option">' +
                '<td><span class="opt-mark" aria-hidden="true">└</span>' + esc(o.label || o.slug) +
                  (o.one_off ? ' <span class="opt-tag">' + esc(I18N.oneOff || 'frais unique') + '</span>' : '') +
                '</td>' +
                '<td class="num"></td>' +
                '<td class="num">' + esc(o.price) + '</td>' +
                '<td class="num"></td>' +
              '</tr>';
          }).join('');
          return head + opts;
        }).join('');

        // Options dont le produit n'est pas revenu dans la réponse.
        rows += extras.map(function (o) {
          return '<tr class="order-line-option">' +
              '<td><span class="opt-mark" aria-hidden="true">└</span>' + esc(o.label || o.slug) + '</td>' +
              '<td class="num"></td>' +
              '<td class="num">' + esc(o.price) + '</td>' +
              '<td class="num"></td>' +
            '</tr>';
        }).join('');

        var foot = '';
        if (totals.recurring) {
          foot += '<div class="order-detail-total"><span>' +
                  esc(I18N.perPeriod || 'Total par période') + '</span><strong>' +
                  esc(totals.recurring) + '</strong></div>';
        }
        if (totals.one_off_raw) {
          foot += '<div class="order-detail-total"><span>' +
                  esc(I18N.oneOffTotal || 'Frais uniques') + '</span><strong>' +
                  esc(totals.one_off) + '</strong></div>';
        }
        if (order.amount) {
          var billed = order.frequency_label
            ? (I18N.billed || 'Facturé') + ' — ' + order.frequency_label
            : (I18N.total || 'Total');
          foot += '<div class="order-detail-total is-main"><span>' + esc(billed) +
                  '</span><strong>' + esc(order.amount) + '</strong></div>';

          // Contrôle : (récurrent × périodes) + frais uniques doit retomber sur
          // le montant de la commande. En cas d'écart, on l'affiche au lieu de
          // le masquer.
          var months = order.interval_months || 1;
          var calc   = (totals.recurring_raw || 0) * months + (totals.one_off_raw || 0);
          if (order.amount_raw != null && Math.abs(calc - order.amount_raw) >= 0.01) {
            foot += '<div class="order-detail-meta is-warn">' +
                    esc(I18N.computed || 'Total calculé depuis les lignes') + ' : ' +
                    esc(calc.toFixed(2).replace('.', ',')) + ' €</div>';
          }
        }
        if (order.next_renewal && order.next_renewal !== '—') {
          foot += '<div class="order-detail-meta">' +
                  esc(I18N.nextRenewal || 'Prochain renouvellement') + ' : ' +
                  esc(order.next_renewal) + '</div>';
        }

        return '<table class="order-detail-table">' +
            '<thead><tr>' +
              '<th>' + esc(I18N.product || 'Produit') + '</th>' +
              '<th class="num">' + esc(I18N.qty || 'Qté') + '</th>' +
              '<th class="num">' + esc(I18N.unitPrice || 'Prix') + '</th>' +
              '<th class="num">' + esc(I18N.lineTotal || 'Sous-total') + '</th>' +
            '</tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
          '</table>' + foot;
      }

      function fillDetail(row, body) {
        var ref = row.getAttribute('data-ref') || '';

        if (detailCache[ref]) { body.innerHTML = detailCache[ref]; return Promise.resolve(); }
        if (detailBusy[ref])  { return detailBusy[ref]; }

        body.innerHTML = '<p class="order-detail-empty">' +
                         esc(I18N.detailLoading || 'Chargement du détail…') + '</p>';

        detailBusy[ref] = fetch(API + '?action=order.detail&ref=' + encodeURIComponent(ref), {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        })
        // On lit le corps en TEXTE : si ce n'est pas du JSON (page d'erreur PHP,
        // notice avant la réponse, réponse n8n brute…), on veut pouvoir le
        // montrer au lieu de perdre l'information.
        .then(function (res) {
          return res.text().then(function (raw) {
            var data = null;
            try { data = JSON.parse(raw); } catch (e) { /* corps non JSON */ }
            return { ok: res.ok, status: res.status, data: data, raw: raw };
          });
        })
        .then(function (r) {
          var data = r.data;
          // Strictement true : un corps valant `true`, `1` ou `{}` n'est pas
          // une réponse valide du proxy et doit tomber ici.
          if (!r.ok || !data || data.ok !== true) {
            // N'accepter comme message que du texte. Sinon, montrer le corps
            // réellement reçu — c'est ce qui permet de diagnostiquer.
            var detail = (data && typeof data.error === 'string' && data.error)
                       ? data.error
                       : String(r.raw == null ? '' : r.raw).trim().slice(0, 300);
            // « code » porte le vrai statut quand le proxy a dû répondre 200
            // pour que son message survive au middleware Traefik.
            var code = (data && data.code) ? data.code : r.status;
            var msg  = (I18N.detailError || 'Détail indisponible.') + ' (HTTP ' + code + ')';
            body.innerHTML = '<p class="order-detail-empty is-error">' +
                             esc(detail ? msg + ' — ' + detail : msg) + '</p>';
            return;
          }
          detailCache[ref] = detailHtml(data, ordersByRef[ref]);
          body.innerHTML = detailCache[ref];
        })
        .catch(function () {
          body.innerHTML = '<p class="order-detail-empty is-error">' +
                           esc(I18N.detailError || 'Détail indisponible.') + '</p>';
        })
        .then(function () { delete detailBusy[ref]; });

        return detailBusy[ref];
      }

      // Ouverture/fermeture animées : on anime la hauteur d'un conteneur
      // interne, pas la cellule elle-même (une <td> ne s'anime pas bien).
      function toggleRow(row) {
        var det = detailOf(row);
        if (!det) return;
        var anim = det.querySelector('.order-detail-anim');
        var body = det.querySelector('.order-detail-body');
        if (!anim || !body) return;

        var isOpen = row.getAttribute('aria-expanded') === 'true';

        if (isOpen) {
          row.setAttribute('aria-expanded', 'false');
          row.classList.remove('is-open');
          anim.style.height = body.scrollHeight + 'px';
          requestAnimationFrame(function () { anim.style.height = '0px'; });
          anim.addEventListener('transitionend', function onEnd(ev) {
            if (ev.propertyName !== 'height') return;
            det.hidden = true;
            anim.removeEventListener('transitionend', onEnd);
          });
          return;
        }

        row.setAttribute('aria-expanded', 'true');
        row.classList.add('is-open');
        det.hidden = false;
        anim.style.height = '0px';

        var grow = function () {
          anim.style.height = body.scrollHeight + 'px';
          anim.addEventListener('transitionend', function onEnd(ev) {
            if (ev.propertyName !== 'height') return;
            if (row.getAttribute('aria-expanded') === 'true') anim.style.height = 'auto';
            anim.removeEventListener('transitionend', onEnd);
          });
        };

        // Le contenu peut arriver après coup : on réajuste la hauteur ensuite.
        fillDetail(row, body).then(function () {
          if (row.getAttribute('aria-expanded') !== 'true') return;
          if (anim.style.height === 'auto') { return; }
          requestAnimationFrame(grow);
        });
        requestAnimationFrame(grow);
      }

      // Délégation : les lignes sont recréées à chaque rendu.
      tbody.addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('tr.order-row') : null;
        if (!row || !tbody.contains(row)) return;
        if (e.target.closest('a, button, input')) return;   // liens futurs
        if (window.getSelection && String(window.getSelection()) !== '') return;
        toggleRow(row);
      });
      tbody.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        var row = e.target.closest ? e.target.closest('tr.order-row') : null;
        if (!row) return;
        e.preventDefault();
        toggleRow(row);
      });

      function load() {
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        fetch(API + '?action=order.list', {
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
          var warn = Array.isArray(data.warnings) ? data.warnings : [];
          if (notice) { notice.textContent = warn.join(' '); notice.hidden = !warn.length; }
          renderRows(Array.isArray(data.orders) ? data.orders : [], data.linked, !!data.truncated,
                     Array.isArray(data.clients) ? data.clients : []);
        })
        .catch(function () {
          tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les commandes.', true);
          setCounter(null);
        });
      }

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter); // croix « effacer » du type=search
      }
      if (clientSel) clientSel.addEventListener('change', applyFilter);
      if (resetBtn) resetBtn.addEventListener('click', function () {
        if (clientSel) clientSel.value = '';
        if (input) input.value = '';
        applyFilter();
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