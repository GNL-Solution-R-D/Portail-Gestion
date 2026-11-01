<?php
/* =====================================================================
   pages/dashboard.php — Tableau de bord du Portail Gestion (maquette Main)
   ---------------------------------------------------------------------
   Ce qui demande l'attention de l'équipe, toutes entreprises confondues :
     • KPI : tickets ouverts, entreprises, services en ligne, factures
       impayées ;
     • tickets à traiter (support.ticket.list) ;
     • services en alerte (data/services_menu_api.php) ;
     • dernières commandes (order.list).
   Les KPI et tableaux sont remplis côté navigateur par
   assets/js/gestion-dashboard.js (tout passe par data/portail_api.php).
   ===================================================================== */
require_once __DIR__ . '/../include/staff_shell.php';

$u = $_SESSION['user'];
$firstName = trim((string) ($u['prenom'] ?? $u['firstname'] ?? $u['first_name'] ?? $u['given_name'] ?? ''));
if ($firstName === '') {
    $firstName = trim(explode(' ', trim((string) ($u['name'] ?? $u['username'] ?? '')))[0] ?? '');
}

gnl_staff_open('Tableau de bord', ['search' => 'Rechercher un ticket, une entreprise…', 'searchId' => 'dashSearch']);

gnl_page_head(
    'TABLEAU DE BORD',
    $firstName !== '' ? 'Bonjour ' . $firstName : 'Bonjour',
    'Ce qui demande votre attention aujourd\'hui, toutes entreprises confondues.',
    '<button type="button" class="ec-btn ec-btn--ghost" data-gs-open="maintModal">Planifier une maintenance</button>'
    . '<a class="ec-btn ec-btn--primary" href="/entreprises?add=1">Ajouter une entreprise</a>'
);
?>
        <div class="ec-kpis" id="dashKpis">
          <a class="ec-kpi" href="/gestion-ticket">
            <span class="ec-kpi__label">Tickets ouverts</span>
            <span class="ec-kpi__value" data-kpi="tickets">—</span>
            <span class="ec-kpi__note" data-kpi-note="tickets">Chargement…</span>
          </a>
          <a class="ec-kpi" href="/entreprises">
            <span class="ec-kpi__label">Entreprises</span>
            <span class="ec-kpi__value" data-kpi="orgs">—</span>
            <span class="ec-kpi__note" data-kpi-note="orgs">Organisations de l'Espace Client</span>
          </a>
          <a class="ec-kpi" href="/services">
            <span class="ec-kpi__label">Services en ligne</span>
            <span class="ec-kpi__value" data-kpi="services">—</span>
            <span class="ec-kpi__note" data-kpi-note="services">Chargement…</span>
          </a>
          <a class="ec-kpi" href="/facture">
            <span class="ec-kpi__label">Factures impayées</span>
            <span class="ec-kpi__value" data-kpi="unpaid">—</span>
            <span class="ec-kpi__note" data-kpi-note="unpaid">Chargement…</span>
          </a>
        </div>

        <div class="gs-grid2">
          <section class="ec-card" aria-labelledby="dashTicketsTitle">
            <div class="ec-card__head">
              <h2 class="ec-h2" id="dashTicketsTitle">Tickets à traiter</h2>
              <a href="/gestion-ticket">Voir tous les tickets</a>
            </div>
            <div class="ec-table-wrap">
              <table class="ec-table">
                <thead><tr><th>Ticket</th><th>Entreprise</th><th>Priorité</th><th>Statut</th><th class="ec-num">Attente</th></tr></thead>
                <tbody id="dashTickets"><tr class="ec-table__state"><td colspan="5">Chargement des tickets…</td></tr></tbody>
              </table>
            </div>
          </section>

          <div style="display:flex;flex-direction:column;gap:24px;min-width:0">
            <section class="ec-card" aria-labelledby="dashAlertsTitle">
              <div class="ec-card__head">
                <h2 class="ec-h2" id="dashAlertsTitle">Services en alerte</h2>
                <a href="/services">Tous les services</a>
              </div>
              <ul class="ec-list" id="dashAlerts"><li class="ec-muted ec-small">Chargement…</li></ul>
            </section>

            <section class="ec-card" aria-labelledby="dashOrdersTitle">
              <div class="ec-card__head">
                <h2 class="ec-h2" id="dashOrdersTitle">Dernières commandes</h2>
                <a href="/commande">Tout voir</a>
              </div>
              <ul class="ec-list" id="dashOrders"><li class="ec-muted ec-small">Chargement…</li></ul>
            </section>
          </div>
        </div>
<?php
gnl_staff_close(['/assets/js/gestion-dashboard.js']);
