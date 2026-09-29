<?php
/* =====================================================================
   GNL Solution — ENTREPRISES  (/entreprises)
   ---------------------------------------------------------------------
   Liste les ORGANISATIONS Keycloak de l'ESPACE CLIENT, lues via l'Admin
   REST API (data/portail_api.php ?action=org.list →
   include/keycloak_esp_client.php). Même principe que /equipes, mais sur
   un AUTRE realm, avec le client KEYCLOAK_ESP-CLI_*.

   Les membres d'une entreprise ne sont PAS chargés au démarrage : un
   appel par organisation coûterait N requêtes Admin REST au chargement.
   On déplie une ligne → on charge ses membres une seule fois
   (?action=org.members&org_id=…), puis on garde le résultat en mémoire.

   Keycloak reste la source de vérité. Seule écriture proposée : le bouton
   « Ajouter » du tableau, qui crée une organisation (?action=org.create).
   ===================================================================== */

require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: /connexion');
    exit();
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

// user_account_sessions a une clé INT : 'account_id' s'il est posé, sinon
// 'id' — l'entier dérivé de sha1(sub) construit par keycloakBuildSessionUser().
$entreprisesAccountId = (int) ($_SESSION['user']['account_id'] ?? 0);
if ($entreprisesAccountId <= 0 && ctype_digit((string) ($_SESSION['user']['id'] ?? ''))) {
    $entreprisesAccountId = (int) $_SESSION['user']['id'];
}

if ($entreprisesAccountId > 0) {
    if (accountSessionsIsCurrentSessionRevoked($pdo, $entreprisesAccountId)) {
        accountSessionsDestroyPhpSession();
        header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
        exit();
    }

    accountSessionsTouchCurrent($pdo, $entreprisesAccountId);
}

// Jeton CSRF (même clé que header.php et que data/portail_api.php).
if (empty($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf'] = bin2hex((string) mt_rand());
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

// Barre de recherche du header : filtre la liste des entreprises côté client.
$showSearch        = true;
$searchInputId     = 'orgsSearchInput';
$searchPlaceholder = t('Rechercher une entreprise…');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title><?= t('Entreprises - GNL Solution') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="preload" href="../assets/front/4cf2300e9c8272f7-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/81f255edf7f746ee-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/96b9d03623b8cae2-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <link rel="preload" href="../assets/front/e4af272ccee01ff0-s.p.woff2" as="font" crossorigin="" type="font/woff2"/>
  <meta name="next-size-adjust" content=""/>
  <meta name="theme-color" content="#ffffff"/>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css?dpl=dpl_67HPKFsXBSK8g98pV2ngjPFkZSfN" data-precedence="next"/>
  <style>
    .dashboard-layout {display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height, 0px));min-height:calc(100dvh - var(--app-header-height, 0px));}
    .dashboard-sidebar {flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main {flex:1 1 auto;min-width:0;}
    .table-wrap {overflow-x:auto;}
    .orgs-state td{padding:1.5rem 1rem;text-align:center;color:var(--muted-foreground, #64748b);}
    .orgs-state--error td{color:#b91c1c;}

    .org-toggle{display:inline-flex;align-items:center;gap:.4rem;border:none;background:none;padding:0;font:inherit;color:inherit;cursor:pointer;text-align:left;}
    .org-toggle .chev{transition:transform 180ms ease;flex:none;}
    .org-toggle[aria-expanded="true"] .chev{transform:rotate(90deg);}
    @media (prefers-reduced-motion: reduce){ .org-toggle .chev{transition:none;} }

    .org-members{background:color-mix(in srgb, currentColor 4%, transparent);}
    .org-members td{padding:0 !important;}
    .org-members-inner{padding:1rem 1.25rem 1.25rem 3.25rem;}
    .member-line{display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid color-mix(in srgb, currentColor 10%, transparent);}
    .member-line:last-child{border-bottom:none;}
    .member-av{display:grid;place-items:center;width:2rem;height:2rem;border-radius:999px;background:color-mix(in srgb, currentColor 10%, transparent);font-size:.72rem;font-weight:600;flex:none;}
    .member-main{min-width:0;flex:1 1 auto;}
    .member-main b{display:block;font-size:.875rem;font-weight:600;}
    .member-main span{display:block;font-size:.8125rem;color:var(--muted-foreground, #64748b);}
    .member-add-row{display:flex;align-items:center;padding:.3rem 0 0;}
    .member-add{display:inline-flex;align-items:center;gap:.35rem;height:1.75rem;padding:0 .5rem;margin-left:-.5rem;border:none;border-radius:3px;background:none;font:inherit;font-size:.8125rem;font-weight:500;color:var(--muted-foreground, #64748b);cursor:pointer;transition:color 120ms ease, background-color 120ms ease;}
    .member-add:hover{color:inherit;background:color-mix(in srgb, currentColor 8%, transparent);}
    @media (prefers-reduced-motion: reduce){ .member-add{transition:none;} }
    .member-add-note{margin-left:.5rem;font-size:.8125rem;color:#15803d;}
    .member-meta{font-size:.8125rem;color:var(--muted-foreground, #64748b);text-align:right;flex:none;}

    .org-links{display:flex;align-items:center;gap:.35rem;}
    .org-link{display:grid;place-items:center;width:2rem;height:2rem;border-radius:.5rem;border:1px solid rgba(148,163,184,.35);color:inherit;opacity:.7;transition:opacity 120ms ease, background-color 120ms ease;}
    .org-link:hover{opacity:1;background:color-mix(in srgb, currentColor 8%, transparent);}
    @media (prefers-reduced-motion: reduce){ .org-link{transition:none;} }

    .tm-btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;height:2.25rem;padding:0 .75rem;border:1px solid var(--border,#e2e8f0);border-radius:3px;font-size:.875rem;font-weight:500;background:var(--background,#fff);color:inherit;cursor:pointer;white-space:nowrap;transition:background .15s, opacity .15s;}
    .tm-btn:hover{background:var(--secondary,#f1f5f9);}
    .tm-btn:disabled{opacity:.5;cursor:not-allowed;}
    .tm-btn--primary{background:var(--primary,#0f172a);color:var(--primary-foreground,#fff);border-color:transparent;}
    .tm-btn--primary:hover{background:var(--primary,#0f172a);opacity:.9;}
    .tm-modal{position:fixed;inset:0;z-index:60;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);}
    .tm-modal.is-open{display:flex;}
    .tm-dialog{width:100%;max-width:40rem;max-height:calc(100vh - 2rem);overflow:auto;border:1px solid var(--border,#e2e8f0);border-radius:.75rem;background:var(--card,#fff);color:var(--card-foreground,#0f172a);box-shadow:0 10px 30px rgba(0,0,0,.2);}
    .tm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem 1rem;}
    .tm-grid .tm-span{grid-column:1 / -1;}
    @media (max-width: 560px){ .tm-grid{grid-template-columns:1fr;} }
    .tm-field label{display:block;margin-bottom:.35rem;font-size:.75rem;font-weight:500;color:var(--muted-foreground,#64748b);}
    .tm-field input[type=text],.tm-field input[type=email],.tm-field input[type=tel]{height:2.5rem;width:100%;border:1px solid var(--border,#e2e8f0);border-radius:.375rem;background:var(--background,#fff);color:inherit;padding:0 .75rem;font-size:.875rem;}
    .tm-check{display:flex;align-items:center;gap:.5rem;font-size:.875rem;}
    .tm-error{font-size:.8rem;color:#b91c1c;}
    .tm-hint{font-size:.75rem;color:var(--muted-foreground,#64748b);margin-top:.25rem;}
    @media (prefers-reduced-motion: reduce){ .tm-btn{transition:none;} }

    @media (max-width: 1024px) {
      .dashboard-layout { flex-direction: column; }
      .dashboard-sidebar {width:100%;max-width:none;flex:0 0 auto;height:auto !important;}
      .dashboard-main { padding: 1rem; }
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
      <div class="app-shell-offset-min-height w-full bg-surface p-6 space-y-6">

        <section class="bg-background text-card-foreground rounded border py-4 shadow-sm">
          <div class="px-6 pb-4 border-b flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h2 class="text-base font-semibold"><?= t('Liste des entreprises') ?></h2>
              <p class="text-sm text-muted-foreground"><?= t('Organisations enregistrées dans l’espace client.') ?></p>
            </div>
            <button type="button" class="tm-btn tm-btn--primary" id="orgAddOpen">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
              <?= t('Ajouter') ?>
            </button>
          </div>

          <div class="table-wrap" data-slot="card-content">
            <table class="w-full min-w-max table-auto text-left">
              <thead>
                <tr>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Entreprise') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Identifiants') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Localisation') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Domaines') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Statut') ?></p></th>
                  <th class="border-surface border-b p-4"><p class="text-default block text-sm font-medium"><?= t('Raccourcis') ?></p></th>
                </tr>
              </thead>
              <tbody id="orgsTableBody">
                <tr class="orgs-state">
                  <td colspan="6"><?= t('Chargement des entreprises…') ?></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="px-6 pt-4 flex justify-end">
            <span id="orgsCount" class="inline-flex items-center justify-center rounded border px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                  data-suffix="<?php echo h(t('entreprise(s)')); ?>">…</span>
          </div>
        </section>
      </div>
    </main>
  </div>

  <!-- Modale : ajouter une entreprise -->
  <div id="orgAddModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="orgAddTitle">
    <div class="tm-dialog">
      <form id="orgAddForm" class="p-6" novalidate>
        <h2 id="orgAddTitle" class="text-lg font-semibold"><?= t('Ajouter une entreprise') ?></h2>
        <p class="text-sm text-muted-foreground mt-1"><?= t('Crée une nouvelle organisation dans l’espace client.') ?></p>

        <div class="tm-grid mt-5">
          <div class="tm-field">
            <label for="orgName"><?= t('Nom de l’organisation') ?> *</label>
            <input type="text" id="orgName" name="name" required maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgNomCommercial"><?= t('Nom commercial') ?></label>
            <input type="text" id="orgNomCommercial" name="nom_commercial" maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgRaison"><?= t('Raison sociale') ?></label>
            <input type="text" id="orgRaison" name="raison" maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgEntite"><?= t('Forme juridique') ?></label>
            <input type="text" id="orgEntite" name="entite_legal" maxlength="255" placeholder="SAS, SARL…" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgSiret"><?= t('SIRET') ?></label>
            <input type="text" id="orgSiret" name="siret" inputmode="numeric" maxlength="20" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgTva"><?= t('N° TVA') ?></label>
            <input type="text" id="orgTva" name="tva" maxlength="32" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgEmail"><?= t('E-mail') ?></label>
            <input type="email" id="orgEmail" name="ent_email" maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgTel"><?= t('Téléphone') ?></label>
            <input type="tel" id="orgTel" name="telephone" maxlength="32" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgCp"><?= t('Code postal') ?></label>
            <input type="text" id="orgCp" name="cp" maxlength="16" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgCommune"><?= t('Commune') ?></label>
            <input type="text" id="orgCommune" name="commune" maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgPays"><?= t('Pays') ?></label>
            <input type="text" id="orgPays" name="pays" maxlength="64" value="France" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="orgAlias"><?= t('Alias') ?></label>
            <input type="text" id="orgAlias" name="alias" maxlength="255" autocomplete="off">
            <p class="tm-hint"><?= t('Facultatif — déduit du nom si vide.') ?></p>
          </div>
          <div class="tm-field tm-span">
            <label for="orgDomains"><?= t('Domaines') ?></label>
            <input type="text" id="orgDomains" name="domains" maxlength="1000" placeholder="exemple.fr, exemple.com" autocomplete="off">
            <p class="tm-hint"><?= t('Séparés par des virgules.') ?></p>
          </div>
          <label class="tm-check tm-span">
            <input type="checkbox" id="orgEnabled" name="enabled" checked>
            <?= t('Organisation activée') ?>
          </label>
        </div>

        <div id="orgAddError" class="tm-error mt-4" role="alert" hidden></div>

        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="tm-btn" data-close><?= t('Annuler') ?></button>
          <button type="submit" class="tm-btn tm-btn--primary" id="orgAddSubmit"><?= t('Créer l’entreprise') ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modale : ajouter un membre à une entreprise -->
  <div id="memberAddModal" class="tm-modal" role="dialog" aria-modal="true" aria-labelledby="memberAddTitle">
    <div class="tm-dialog" style="max-width:28rem">
      <form id="memberAddForm" class="p-6" novalidate>
        <h2 id="memberAddTitle" class="text-lg font-semibold"><?= t('Ajouter un membre') ?></h2>
        <p id="memberAddSub" class="text-sm text-muted-foreground mt-1"></p>

        <div class="tm-grid mt-5">
          <div class="tm-field tm-span">
            <label for="memberEmail"><?= t('E-mail') ?> *</label>
            <input type="email" id="memberEmail" name="email" required maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="memberFirst"><?= t('Prénom') ?></label>
            <input type="text" id="memberFirst" name="first_name" maxlength="255" autocomplete="off">
          </div>
          <div class="tm-field">
            <label for="memberLast"><?= t('Nom') ?></label>
            <input type="text" id="memberLast" name="last_name" maxlength="255" autocomplete="off">
          </div>
        </div>
        <p class="tm-hint mt-3"><?= t('Une invitation est envoyée par e-mail : lien d’invitation si le compte existe, lien d’inscription sinon.') ?></p>

        <div id="memberAddError" class="tm-error mt-4" role="alert" hidden></div>

        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="tm-btn" data-close><?= t('Annuler') ?></button>
          <button type="submit" class="tm-btn tm-btn--primary" id="memberAddSubmit"><?= t('Envoyer l’invitation') ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Entreprises : organisations Keycloak de l'espace client via
       data/portail_api.php ?action=org.list (include/keycloak_esp_client.php
       → Admin REST du realm ESP-CLI). Lecture seule.
       Les membres sont chargés à la demande (?action=org.members). -->
  <script>
    window.ORG_API_URL = window.ORG_API_URL || "../data/portail_api.php";
    window.ORG_CSRF = <?= json_encode((string) ($_SESSION['csrf'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    window.ORG_I18N = {
      loading:     <?= json_encode(t('Chargement des entreprises…'), JSON_UNESCAPED_UNICODE) ?>,
      empty:       <?= json_encode(t('Aucune entreprise enregistrée.'), JSON_UNESCAPED_UNICODE) ?>,
      noResults:   <?= json_encode(t('Aucune entreprise ne correspond à votre recherche.'), JSON_UNESCAPED_UNICODE) ?>,
      error:       <?= json_encode(t('Impossible de charger les entreprises.'), JSON_UNESCAPED_UNICODE) ?>,
      nameRequired:<?= json_encode(t('Le nom de l’organisation est obligatoire.'), JSON_UNESCAPED_UNICODE) ?>,
      createErr:   <?= json_encode(t('Impossible de créer l’entreprise.'), JSON_UNESCAPED_UNICODE) ?>,
      creating:    <?= json_encode(t('Création…'), JSON_UNESCAPED_UNICODE) ?>,
      created:     <?= json_encode(t('Entreprise créée.'), JSON_UNESCAPED_UNICODE) ?>,
      addMember:   <?= json_encode(t('Ajouter un membre'), JSON_UNESCAPED_UNICODE) ?>,
      emailInvalid:<?= json_encode(t('Adresse e-mail invalide.'), JSON_UNESCAPED_UNICODE) ?>,
      inviting:    <?= json_encode(t('Envoi…'), JSON_UNESCAPED_UNICODE) ?>,
      inviteErr:   <?= json_encode(t('Impossible d’envoyer l’invitation.'), JSON_UNESCAPED_UNICODE) ?>,
      invited:     <?= json_encode(t('Invitation envoyée à'), JSON_UNESCAPED_UNICODE) ?>,
      truncated:   <?= json_encode(t('Liste tronquée : seules les premières entreprises sont affichées.'), JSON_UNESCAPED_UNICODE) ?>,
      active:      <?= json_encode(t('Activée'), JSON_UNESCAPED_UNICODE) ?>,
      inactive:    <?= json_encode(t('Désactivée'), JSON_UNESCAPED_UNICODE) ?>,
      noDomain:    <?= json_encode(t('Aucun domaine'), JSON_UNESCAPED_UNICODE) ?>,
      membersLoad: <?= json_encode(t('Chargement des membres…'), JSON_UNESCAPED_UNICODE) ?>,
      membersNone: <?= json_encode(t('Aucun membre rattaché à cette entreprise.'), JSON_UNESCAPED_UNICODE) ?>,
      membersErr:  <?= json_encode(t('Impossible de charger les membres.'), JSON_UNESCAPED_UNICODE) ?>,
      membersMore: <?= json_encode(t('Liste tronquée : seuls les premiers membres sont affichés.'), JSON_UNESCAPED_UNICODE) ?>,
      noFunction:  <?= json_encode(t('Aucune fonction définie'), JSON_UNESCAPED_UNICODE) ?>,
      goInvoices:  <?= json_encode(t('Factures de cette entreprise'), JSON_UNESCAPED_UNICODE) ?>,
      goOrders:    <?= json_encode(t('Commandes de cette entreprise'), JSON_UNESCAPED_UNICODE) ?>,
      goSubs:      <?= json_encode(t('Abonnements de cette entreprise'), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
  (function () {
    function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
    var I18N = window.ORG_I18N || {};
    var API  = window.ORG_API_URL || "../data/portail_api.php";

    function norm(s){ return String(s==null?'':s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function sel(id){ return (window.CSS && CSS.escape) ? CSS.escape(id) : String(id).replace(/["\\]/g, '\\$&'); }

    var BADGE_OK  = 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-300';
    var BADGE_OFF = 'bg-red-100 text-red-700 dark:bg-red-900/20 dark:text-red-300';
    var COLS = 6;

    ready(function () {
      var input    = document.getElementById('orgsSearchInput');
      var tbody    = document.getElementById('orgsTableBody');
      var counter  = document.getElementById('orgsCount');
      var realmEl  = document.getElementById('realmBadge');
      var alerts   = document.getElementById('orgsAlerts');
      if (!tbody) return;

      // membersCache : org_id -> 'loading' | html. Un dépliage ne rappelle
      // jamais Keycloak pour une organisation déjà chargée.
      var state  = { orgs: [], truncated: false, issuer: '', membersCache: {} };
      var suffix = counter ? (counter.getAttribute('data-suffix') || '') : '';

      function setCounter(n){ if (counter) counter.textContent = (n==null?'…':n) + (suffix ? ' ' + suffix : ''); }

      function showAlert(message, isError, isSuccess){
        if (!alerts) return;
        alerts.innerHTML = '';
        if (!message) return;
        var div = document.createElement('div');
        div.className = 'rounded-xl border px-6 py-4 text-sm ' + (isError
          ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-300'
          : isSuccess
            ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-900/30 dark:bg-green-950/30 dark:text-green-300'
            : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/30 dark:bg-amber-950/30 dark:text-amber-300');
        div.textContent = message;
        alerts.appendChild(div);
      }

      function stateRow(text, isError){
        return '<tr class="orgs-state' + (isError ? ' orgs-state--error' : '') + '"><td colspan="' + COLS + '">' + esc(text) + '</td></tr>';
      }

      function chevron(){
        return '<svg class="chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
      }

      // Raccourcis de fin de ligne : mêmes pages que le menu, mais filtrées
      // sur l'UID Keycloak de l'organisation (?org=). C'est data/portail_api.php
      // qui relaie cet UID à n8n sous la clé « organization_uid ».
      var ICONS = {
        invoice: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>',
        order:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
        sub:     '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>'
      };

      function shortcut(page, orgId, icon, title){
        return '<a class="org-link" href="./' + page + '?org=' + encodeURIComponent(orgId) + '"' +
               ' title="' + esc(title) + '" aria-label="' + esc(title) + '">' + icon + '</a>';
      }

      function rowHtml(o){
        var ids = [];
        if (o.siret)        ids.push('SIRET ' + o.siret);
        else if (o.siren)   ids.push('SIREN ' + o.siren);
        if (o.entite_legal) ids.push(o.entite_legal);
        if (o.tva)          ids.push('TVA ' + o.tva);

        var domains = (o.domains && o.domains.length) ? o.domains.join(', ') : (I18N.noDomain || '—');
        var statusLabel = o.enabled ? (I18N.active || 'Activée') : (I18N.inactive || 'Désactivée');
        var statusClass = o.enabled ? BADGE_OK : BADGE_OFF;

        var hay = [o.label, o.name, o.alias, o.raison, o.siret, o.siren, o.namespace,
                   o.localite, o.entite_legal, domains, statusLabel].join(' ').toLowerCase();

        // Sous-titre : le nom technique, seulement s'il diffère du libellé.
        var technical = (o.name && o.name !== o.label) ? o.name : (o.alias && o.alias !== o.label ? o.alias : '');

        return '<tr data-search="' + esc(hay) + '" data-org-row="' + esc(o.id) + '">' +
          '<td class="border-surface border-b p-4 align-top">' +
            '<button type="button" class="org-toggle" aria-expanded="false" data-org-id="' + esc(o.id) + '">' +
              chevron() +
              '<span><b class="text-default block text-sm font-semibold">' + esc(o.label) + '</b>' +
              (technical ? '<span class="text-foreground block text-sm">' + esc(technical) + '</span>' : '') +
              '</span>' +
            '</button>' +
          '</td>' +
          '<td class="border-surface border-b p-4 align-top"><div>' +
            '<p class="text-default block text-sm">' + esc(ids.length ? ids.join(' · ') : '—') + '</p>' +
            (o.namespace ? '<p class="text-foreground block text-sm">ns ' + esc(o.namespace) + '</p>' : '') +
          '</div></td>' +
          '<td class="border-surface border-b p-4 align-top"><div>' +
            '<p class="text-default block text-sm">' + esc(o.localite || '—') + '</p>' +
            (o.pays ? '<p class="text-foreground block text-sm">' + esc(o.pays) + '</p>' : '') +
          '</div></td>' +
          '<td class="border-surface border-b p-4 align-top"><p class="text-foreground block text-sm">' + esc(domains) + '</p></td>' +
          '<td class="border-surface border-b p-4 align-top"><span class="inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap shrink-0 ' + statusClass + '">' + esc(statusLabel) + '</span></td>' +
          '<td class="border-surface border-b p-4 align-top"><div class="org-links">' +
            shortcut('facture',     o.id, ICONS.invoice, I18N.goInvoices || 'Factures') +
            shortcut('commande',    o.id, ICONS.order,   I18N.goOrders   || 'Commandes') +
            shortcut('abonnements', o.id, ICONS.sub,     I18N.goSubs     || 'Abonnements') +
          '</div></td>' +
        '</tr>' +
        '<tr class="org-members" data-org-members="' + esc(o.id) + '" hidden>' +
          '<td colspan="' + COLS + '"><div class="org-members-inner"></div></td>' +
        '</tr>';
      }

      function dataRows(){ return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-search]')); }

      function applyFilter(){
        var rows = dataRows();
        if (!rows.length) return;
        var q = input ? norm(input.value.trim()) : '';
        var tokens = q ? q.split(/\s+/) : [];
        var visible = 0;
        rows.forEach(function (row){
          var hay = norm(row.getAttribute('data-search') || '');
          var match = tokens.every(function (t){ return hay.indexOf(t) !== -1; });
          row.hidden = !match;
          if (match) visible++;

          // Le panneau des membres suit sa ligne : masqué si la ligne l'est,
          // ou si le dépliage a été refermé.
          var id    = row.getAttribute('data-org-row');
          var panel = tbody.querySelector('tr[data-org-members="' + sel(id) + '"]');
          var btn   = row.querySelector('.org-toggle');
          if (panel) {
            panel.hidden = !match || !btn || btn.getAttribute('aria-expanded') !== 'true';
          }
        });
        var noRes = document.getElementById('orgsNoResults');
        if (noRes) noRes.hidden = (visible !== 0);
        setCounter(visible);
      }

      function renderRows(){
        var list = state.orgs;
        if (!list.length) {
          tbody.innerHTML = stateRow(I18N.empty || 'Aucune entreprise.', false);
          setCounter(0);
          return;
        }
        tbody.innerHTML = list.map(rowHtml).join('') +
          '<tr id="orgsNoResults" class="orgs-state" hidden><td colspan="' + COLS + '">' + esc(I18N.noResults || '') + '</td></tr>';
        setCounter(list.length);
        applyFilter();
      }

      function updateHeader(){
        if (realmEl) {
          // Realm réellement interrogé : utile quand on doute de la source.
          var realm = '';
          if (state.issuer) {
            var m = String(state.issuer).match(/\/realms\/([^\/]+)/);
            realm = m ? m[1] : '';
          }
          if (realm) { realmEl.textContent = realm; realmEl.hidden = false; }
          else       { realmEl.textContent = ''; realmEl.hidden = true; }
        }
      }

      function memberHtml(m){
        return '<div class="member-line">' +
          '<span class="member-av">' + esc(m.initials) + '</span>' +
          '<span class="member-main"><b>' + esc(m.name) + '</b><span>' + esc(m.secondary) + '</span></span>' +
          '<span class="member-meta">' + esc(m.function || I18N.noFunction || '') +
            '<br>' + esc(m.status_label) + ' · ' + esc(m.membership) +
          '</span>' +
        '</div>';
      }

      // Demi-ligne « Ajouter un membre + » sous la liste des membres.
      function addMemberRow(orgId){
        return '<div class="member-add-row">' +
          '<button type="button" class="member-add" data-add-member="' + esc(orgId) + '">' +
            esc(I18N.addMember || 'Ajouter un membre') +
            ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>' +
          '</button>' +
        '</div>';
      }

      function loadMembers(orgId, panel, force){
        var inner = panel.querySelector('.org-members-inner');
        if (!inner) return;

        var cached = state.membersCache[orgId];
        if (cached === 'loading') return Promise.resolve();
        if (cached && !force) { inner.innerHTML = cached; return Promise.resolve(); }

        state.membersCache[orgId] = 'loading';
        inner.innerHTML = '<p class="text-sm text-muted-foreground">' + esc(I18N.membersLoad || 'Chargement…') + '</p>';

        return fetch(API + '?action=org.members&org_id=' + encodeURIComponent(orgId),
              { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
        .then(function (r){
          var data = r.data, html;
          if (!r.ok || !data || !data.ok) {
            var msg = (data && data.error) ? data.error : (I18N.membersErr || 'Erreur.');
            html = '<p class="text-sm" style="color:#b91c1c">' + esc(msg) + '</p>';
            // Un échec n'est pas mis en cache : le prochain dépliage réessaie.
            delete state.membersCache[orgId];
          } else {
            var list = Array.isArray(data.members) ? data.members : [];
            html = list.length
              ? list.map(memberHtml).join('') +
                (data.truncated ? '<p class="text-sm text-muted-foreground" style="margin-top:.6rem">' + esc(I18N.membersMore || '') + '</p>' : '')
              : '<p class="text-sm text-muted-foreground">' + esc(I18N.membersNone || '') + '</p>';
            html += addMemberRow(orgId);
            state.membersCache[orgId] = html;
          }
          inner.innerHTML = html;
        })
        .catch(function (){
          delete state.membersCache[orgId];
          inner.innerHTML = '<p class="text-sm" style="color:#b91c1c">' + esc(I18N.membersErr || 'Erreur.') + '</p>';
        });
      }

      function load(doneMessage){
        tbody.innerHTML = stateRow(I18N.loading || 'Chargement…', false);
        setCounter(null);
        fetch(API + '?action=org.list', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
        .then(function (r){
          var data = r.data;
          if (!r.ok || !data || !data.ok) {
            var msg = (data && data.error) ? data.error : (I18N.error || 'Erreur.');
            tbody.innerHTML = stateRow(msg, true);
            setCounter(null);
            updateHeader();
            return;
          }
          state.orgs         = Array.isArray(data.orgs) ? data.orgs : [];
          state.truncated    = !!data.truncated;
          state.issuer       = data.issuer || '';
          state.membersCache = {};
          updateHeader();
          renderRows();
          if (doneMessage) showAlert(doneMessage, false, true);
          else showAlert(state.truncated ? (I18N.truncated || '') : '', false);
        })
        .catch(function (){ tbody.innerHTML = stateRow(I18N.error || 'Impossible de charger les entreprises.', true); setCounter(null); });
      }

      // Délégation : les lignes sont rendues dynamiquement.
      tbody.addEventListener('click', function (e){
        var addBtn = e.target.closest ? e.target.closest('[data-add-member]') : null;
        if (addBtn) { e.preventDefault(); openMemberAdd(addBtn.getAttribute('data-add-member')); return; }

        var btn = e.target.closest ? e.target.closest('.org-toggle') : null;
        if (!btn) return;
        e.preventDefault();

        var orgId = btn.getAttribute('data-org-id');
        var panel = tbody.querySelector('tr[data-org-members="' + sel(orgId) + '"]');
        if (!panel) return;

        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        panel.hidden = open;
        if (!open) loadMembers(orgId, panel);
      });

      if (input) {
        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter);
      }

      // ── Ajouter une entreprise ─────────────────────────────────────
      var addModal  = document.getElementById('orgAddModal');
      var addForm   = document.getElementById('orgAddForm');
      var addOpen   = document.getElementById('orgAddOpen');
      var addErr    = document.getElementById('orgAddError');
      var addSubmit = document.getElementById('orgAddSubmit');
      var addLabel  = addSubmit ? addSubmit.textContent : '';
      var lastFocus = null;

      function setAddError(msg){
        if (!addErr) return;
        addErr.textContent = msg || '';
        addErr.hidden = !msg;
      }
      function openAdd(){
        if (!addModal || !addForm) return;
        lastFocus = document.activeElement;
        addForm.reset();
        setAddError('');
        addModal.classList.add('is-open');
        var first = document.getElementById('orgName');
        if (first) first.focus();
      }
      function closeAdd(){
        if (!addModal) return;
        addModal.classList.remove('is-open');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
      }

      if (addOpen) addOpen.addEventListener('click', openAdd);
      if (addModal) {
        addModal.addEventListener('click', function (e){
          if (e.target === addModal || (e.target.closest && e.target.closest('[data-close]'))) closeAdd();
        });
      }
      document.addEventListener('keydown', function (e){
        if (e.key === 'Escape' && addModal && addModal.classList.contains('is-open')) closeAdd();
      });

      if (addForm) {
        addForm.addEventListener('submit', function (e){
          e.preventDefault();
          var nameEl = document.getElementById('orgName');
          if (!nameEl || !nameEl.value.trim()) {
            setAddError(I18N.nameRequired || 'Nom obligatoire.');
            if (nameEl) nameEl.focus();
            return;
          }
          setAddError('');

          var body = new URLSearchParams();
          Array.prototype.forEach.call(addForm.elements, function (el){
            if (!el.name || el.type === 'submit' || el.type === 'button') return;
            if (el.type === 'checkbox') body.append(el.name, el.checked ? '1' : '0');
            else body.append(el.name, el.value.trim());
          });

          addSubmit.disabled = true;
          addSubmit.textContent = I18N.creating || '…';

          fetch(API + '?action=org.create', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': window.ORG_CSRF || ''
            },
            body: body.toString()
          })
          .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
          .then(function (r){
            var data = r.data;
            if (!r.ok || !data || !data.ok) {
              setAddError((data && data.error) ? data.error : (I18N.createErr || 'Erreur.'));
              return;
            }
            closeAdd();
            if (input) input.value = '';
            load(I18N.created || 'Entreprise créée.');
          })
          .catch(function (){ setAddError(I18N.createErr || 'Erreur.'); })
          .then(function (){
            addSubmit.disabled = false;
            addSubmit.textContent = addLabel;
          });
        });
      }

      // ── Ajouter un membre ──────────────────────────────────────────
      var mModal  = document.getElementById('memberAddModal');
      var mForm   = document.getElementById('memberAddForm');
      var mSub    = document.getElementById('memberAddSub');
      var mErr    = document.getElementById('memberAddError');
      var mSubmit = document.getElementById('memberAddSubmit');
      var mLabel  = mSubmit ? mSubmit.textContent : '';
      var mOrgId  = '';
      var mFocus  = null;

      function setMemberError(msg){
        if (!mErr) return;
        mErr.textContent = msg || '';
        mErr.hidden = !msg;
      }
      function orgLabel(orgId){
        for (var i = 0; i < state.orgs.length; i++) {
          if (state.orgs[i].id === orgId) return state.orgs[i].label || '';
        }
        return '';
      }
      function openMemberAdd(orgId){
        if (!mModal || !mForm) return;
        mOrgId = orgId;
        mFocus = document.activeElement;
        mForm.reset();
        setMemberError('');
        if (mSub) mSub.textContent = orgLabel(orgId);
        mModal.classList.add('is-open');
        var first = document.getElementById('memberEmail');
        if (first) first.focus();
      }
      function closeMemberAdd(){
        if (!mModal) return;
        mModal.classList.remove('is-open');
        if (mFocus && mFocus.focus && document.contains(mFocus)) mFocus.focus();
      }

      if (mModal) {
        mModal.addEventListener('click', function (e){
          if (e.target === mModal || (e.target.closest && e.target.closest('[data-close]'))) closeMemberAdd();
        });
      }
      document.addEventListener('keydown', function (e){
        if (e.key === 'Escape' && mModal && mModal.classList.contains('is-open')) closeMemberAdd();
      });

      if (mForm) {
        mForm.addEventListener('submit', function (e){
          e.preventDefault();
          var emailEl = document.getElementById('memberEmail');
          var email   = emailEl ? emailEl.value.trim() : '';
          if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setMemberError(I18N.emailInvalid || 'Adresse e-mail invalide.');
            if (emailEl) emailEl.focus();
            return;
          }
          setMemberError('');

          var body = new URLSearchParams();
          body.append('org_id', mOrgId);
          body.append('email', email);
          body.append('first_name', (document.getElementById('memberFirst') || {}).value || '');
          body.append('last_name',  (document.getElementById('memberLast')  || {}).value || '');

          mSubmit.disabled = true;
          mSubmit.textContent = I18N.inviting || '…';

          var orgId = mOrgId;
          fetch(API + '?action=org.invite_member', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-CSRF-Token': window.ORG_CSRF || ''
            },
            body: body.toString()
          })
          .then(function (res){ return res.json().catch(function(){ return null; }).then(function (data){ return { ok: res.ok, data: data }; }); })
          .then(function (r){
            var data = r.data;
            if (!r.ok || !data || !data.ok) {
              setMemberError((data && data.error) ? data.error : (I18N.inviteErr || 'Erreur.'));
              return;
            }
            closeMemberAdd();
            // Recharge les membres, puis confirme à côté du bouton (non mis en cache).
            var panel = tbody.querySelector('tr[data-org-members="' + sel(orgId) + '"]');
            if (!panel) return;
            var notice = (I18N.invited || 'Invitation envoyée à') + ' ' + email + '.';
            (loadMembers(orgId, panel, true) || Promise.resolve()).then(function (){
              var row = panel.querySelector('.member-add-row');
              if (!row) return;
              var span = document.createElement('span');
              span.className = 'member-add-note';
              span.setAttribute('role', 'status');
              span.textContent = notice;
              row.appendChild(span);
            });
          })
          .catch(function (){ setMemberError(I18N.inviteErr || 'Erreur.'); })
          .then(function (){
            mSubmit.disabled = false;
            mSubmit.textContent = mLabel;
          });
        });
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
  <!-- Dépliants de la barre latérale (dont « Client ») : cette page n'avait aucun contrôleur. -->
  <script src="../assets/js/collapsible.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/collapsible.js') ?>" defer></script>
</body>
</html>
