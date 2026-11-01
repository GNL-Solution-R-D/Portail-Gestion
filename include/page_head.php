<?php
/* =====================================================================
   include/page_head.php — en-tête de page et onglets de section
   ---------------------------------------------------------------------
   gnl_page_head()    : surtitre vert + titre + sous-titre + actions,
                        identique sur toutes les pages de l'espace client.
   gnl_section_tabs() : bandeau d'onglets des sections regroupées dans
                        la barre latérale (Facturation, Équipe et compte).
   Aucune sortie à l'inclusion : ce fichier ne définit que des fonctions.
   ===================================================================== */

declare(strict_types=1);

if (!function_exists('gnl_ph_t')) {
    function gnl_ph_t(string $s): string
    {
        return function_exists('t') ? (string) t($s) : $s;
    }
}

if (!function_exists('gnl_page_head')) {
    /**
     * @param string $actionsHtml HTML déjà échappé (boutons, compteur…)
     * @param string $subId       id du sous-titre, si un script doit l'écrire
     */
    function gnl_page_head(string $kicker, string $title, string $sub = '', string $actionsHtml = '', string $subId = ''): void
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        echo '<div class="ec-head"><div>';
        if ($kicker !== '') echo '<p class="ec-kicker">' . $e($kicker) . '</p>';
        echo '<h1 class="ec-h1">' . $e($title) . '</h1>';
        if ($sub !== '' || $subId !== '') {
            echo '<p class="ec-sub"' . ($subId !== '' ? ' id="' . $e($subId) . '"' : '') . '>' . $e($sub) . '</p>';
        }
        echo '</div>';
        if ($actionsHtml !== '') echo '<div class="ec-actions">' . $actionsHtml . '</div>';
        echo '</div>';
    }
}

if (!function_exists('gnl_section_tabs')) {
    /** @param string $group 'facturation' | 'equipe' */
    function gnl_section_tabs(string $group, string $active): void
    {
        $can = static function (string $key): bool {
            return function_exists('orgCan') ? orgCan($key) : true;
        };
        $tabs = [];
        if ($group === 'facturation') {
            if ($can('invoices.view')) $tabs['facture']     = ['/facture',     gnl_ph_t('Factures')];
            if ($can('orders.view'))   $tabs['abonnements'] = ['/abonnements', gnl_ph_t('Abonnements')];
            if ($can('orders.view'))   $tabs['commande']    = ['/commande',    gnl_ph_t('Commandes')];
        } elseif ($group === 'equipe') {
            $tabs['equipes'] = ['/equipes', gnl_ph_t('Équipe')];
            $tabs['account'] = ['/account', gnl_ph_t('Mon compte')];
        }
        if (count($tabs) < 2) return;
        echo '<nav class="ec-tabs" aria-label="' . htmlspecialchars(gnl_ph_t('Sections'), ENT_QUOTES, 'UTF-8') . '">';
        foreach ($tabs as $key => [$href, $label]) {
            echo '<a href="' . $href . '"' . ($key === $active ? ' aria-current="page"' : '') . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</nav>';
    }
}

if (!function_exists('gnl_billing_head')) {
    /**
     * En-tête commun des pages Facturation (factures, abonnements, commandes) :
     * titre, trois cartes de synthèse (remplies par assets/js/billing_kpis.js)
     * et onglets. Les cartes ne s'affichent qu'avec le droit invoices.view.
     */
    function gnl_billing_head(string $active): void
    {
        $e  = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $t  = static fn (string $v): string => gnl_ph_t($v);
        gnl_page_head($t('Facturation'), $t('Factures et abonnements'),
            $t('Paiement sécurisé par Mollie. Vos factures restent téléchargeables à tout moment.'));

        $canKpi = function_exists('orgCan') ? (orgCan('invoices.view') && orgCan('orders.view')) : true;
        if ($canKpi) {
            $i18n = [
                'upToDate'     => $t('Tout est à jour'),
                'openInvoices' => $t('facture(s) en attente'),
                'activeSubs'   => $t('abonnement(s) actif(s)'),
                'noSub'        => $t('Aucun abonnement actif'),
                'noMethod'     => $t('Aucun enregistré'),
            ];
            echo '<div class="ec-kpis" data-billing-kpis data-api="../data/portail_api.php" data-i18n="'
                . $e((string) json_encode($i18n, JSON_UNESCAPED_UNICODE)) . '">';
            $cards = [
                ['next',   $t('Prochaine échéance'), ''],
                ['due',    $t('Reste à payer'),      ''],
                ['method', $t('Moyen de paiement'),  '<a class="ec-link" href="/abonnements">' . $e($t('Changer')) . '</a>'],
            ];
            foreach ($cards as [$key, $label, $extra]) {
                echo '<div class="ec-kpi" data-kpi="' . $key . '">'
                    . '<span class="ec-kpi__label">' . $e($label) . '</span>'
                    . '<span class="ec-kpi__value' . ($key === 'method' ? ' ec-kpi__value--sm' : '') . '">…</span>'
                    . '<span class="ec-kpi__note"></span>' . $extra . '</div>';
            }
            echo '</div>';
            $js = __DIR__ . '/../assets/js/billing_kpis.js';
            echo '<script src="../assets/js/billing_kpis.js?v=' . (int) @filemtime($js) . '" defer></script>';
        }
        gnl_section_tabs('facturation', $active);
    }
}

if (!function_exists('gnl_billing_note')) {
    /** Lien d'aide sous les tableaux de facturation. */
    function gnl_billing_note(): void
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        echo '<p class="ec-billing-note">' . $e(gnl_ph_t('Une question sur une facture ?')) . ' '
            . '<a href="/tickets?nouveau=1&amp;service=' . rawurlencode(gnl_ph_t('Facturation')) . '">'
            . $e(gnl_ph_t('Ouvrez un ticket « Facturation »')) . '</a>.</p>';
    }
}
