<?php

declare(strict_types=1);

/**
 * pages/gestion-ticket.php
 *
 * Console de gestion des tickets pour les ÉQUIPES INTERNES (support).
 *
 * Différences avec pages/tickets.php (espace client) :
 *  - L'agent voit la file de TOUS les tickets, pas seulement les siens
 *    (le proxy data/portail_api.php ne doit donc PAS scoper sur un client_id
 *    pour les actions "admin.ticket.*" : il s'appuie sur le rôle de session).
 *  - L'agent peut faire évoluer le STATUT et la PRIORITÉ d'un ticket.
 *  - Les réponses de l'agent sont publiées côté "support" et signées avec
 *    l'identité de l'agent (ex. « M. Gabin Grobost ») + un badge certifié.
 *
 * Conventions identiques à tickets.php :
 *  - Le navigateur ne parle JAMAIS à n8n : il appelle data/portail_api.php.
 *  - Jeton CSRF : $_SESSION['csrf'] (en-tête X-CSRF-Token).
 *
 * ⚠️ Sécurité : le contrôle de rôle ci-dessous est une 1re barrière côté page.
 *    Le proxy data/portail_api.php DOIT lui aussi vérifier que la session est
 *    bien un agent avant d'exécuter une action "admin.ticket.*". Ne jamais
 *    faire confiance au seul navigateur.
 */

// Cookie de session valable sur /pages/* ET /data/*
if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params(['path' => '/']);
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode('Cette session a été déconnectée depuis vos paramètres.'));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

// ─────────────────────────────────────────────────────────────────────────────
// Contrôle d'accès : réservé aux équipes internes.
// ⚙️ Adaptez la liste / les champs à votre schéma de session.
// Tant qu'aucun champ de rôle n'est présent en session, l'accès est laissé
// ouvert (phase d'intégration) — pensez à câbler la vérification réelle.
// ─────────────────────────────────────────────────────────────────────────────
$me            = $_SESSION['user'];
$SUPPORT_ROLES = ['support', 'admin', 'staff', 'agent', 'agent_support'];
$myRole        = strtolower((string) ($me['role'] ?? $me['type'] ?? ''));

$isStaff = ($myRole === '') ? true : in_array($myRole, $SUPPORT_ROLES, true);
if (!empty($me['is_admin']) || !empty($me['is_staff']) || !empty($me['is_support'])) {
    $isStaff = true;
}
if (!$isStaff) {
    // Compte client ordinaire → renvoyé vers son espace.
    header('Location: /tickets');
    exit;
}

// CSRF token (identique à celui attendu par data/portail_api.php).
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

// ─────────────────────────────────────────────────────────────────────────────
// Identité de l'agent telle qu'elle apparaît dans le fil de discussion.
// Par défaut « M. Gabin Grobost » ; dérivée de la session si disponible.
// ─────────────────────────────────────────────────────────────────────────────
$prenom   = trim((string) ($me['prenom'] ?? $me['firstname'] ?? $me['first_name'] ?? ''));
$nom      = trim((string) ($me['nom']    ?? $me['lastname']  ?? $me['last_name']  ?? ''));
$civilite = trim((string) ($me['civilite'] ?? '')) ?: 'M.';

$agentName = trim($civilite . ' ' . trim($prenom . ' ' . $nom));
if ($agentName === '' || $agentName === $civilite) {
    $agentName = 'M. Gabin Grobost';
}

$pageTitle = 'Gestion des tickets - GNL Solution';

// On réutilise la barre de recherche du header (définie AVANT son inclusion).
$showSearch        = true;
$searchInputId     = 'gestionTicketsSearchInput';
$searchPlaceholder = 'Rechercher (client, objet, référence…)';
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .dashboard-layout{display:flex;flex-direction:row;align-items:stretch;width:100%;min-height:calc(100vh - var(--app-header-height,0px));min-height:calc(100dvh - var(--app-header-height,0px));}
    .dashboard-sidebar{flex:0 0 20rem;width:20rem;max-width:20rem;}
    .dashboard-main{flex:1 1 auto;min-width:0;}
    @media (max-width:1024px){
      .dashboard-layout{flex-direction:column;}
      .dashboard-sidebar{width:100%;max-width:none;flex:0 0 auto;height:auto!important;}
      .dashboard-main{padding:1rem;}
    }

    /* Boutons */
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;height:38px;border-radius:.6rem;border:1px solid var(--border);padding:0 .9rem;font-size:.875rem;font-weight:500;cursor:pointer;transition:background .15s ease,opacity .15s ease;background:var(--background);color:inherit;}
    .btn:hover{background:var(--secondary);}
    .btn:disabled{opacity:.55;cursor:not-allowed;}
    .btn-primary{background:var(--primary);color:var(--primary-foreground);border-color:transparent;}
    .btn-primary:hover{background:var(--primary);opacity:.9;}
    .btn-ghost{border-color:transparent;background:transparent;}
    .btn-ghost:hover{background:var(--secondary);}
    .btn-sm{height:32px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem;}
    .icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}

    /* Filtres */
    .filterbar{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between;}
    .tabs{display:flex;flex-wrap:wrap;gap:.3rem;}
    .tab{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--border);border-radius:999px;padding:.32rem .7rem;font-size:.8rem;cursor:pointer;background:var(--background);transition:all .15s ease;}
    .tab[aria-selected="true"]{background:var(--primary);color:var(--primary-foreground);border-color:transparent;}
    .tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:1.3rem;height:1.15rem;padding:0 .35rem;border-radius:999px;font-size:.7rem;font-weight:700;background:var(--secondary);color:var(--muted-foreground,#64748b);}
    .tab[aria-selected="true"] .tab-count{background:color-mix(in srgb, var(--primary-foreground) 22%, transparent);color:var(--primary-foreground);}

    .filters-right{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;}
    .filters-right select{height:34px;border-radius:.55rem;border:1px solid var(--border);background:var(--background);color:inherit;font-size:.82rem;padding:0 .55rem;font-family:inherit;}

    /* Table */
    .tickets-table-wrap{overflow-x:auto;}
    .tickets-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
    .tickets-table th,.tickets-table td{padding:.85rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.9rem;text-align:left;vertical-align:middle;}
    .tickets-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.7rem;color:var(--muted-foreground,#64748b);white-space:nowrap;}
    .tickets-table tbody tr{cursor:pointer;}
    .tickets-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .tickets-table .ref{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.82rem;color:var(--muted-foreground,#64748b);white-space:nowrap;}
    .tickets-table .subj{font-weight:600;}
    .client-cell{display:flex;flex-direction:column;gap:.05rem;min-width:0;}
    .client-name{font-weight:600;}
    .client-sub{font-size:.74rem;color:var(--muted-foreground,#64748b);}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.18rem .55rem;font-size:.74rem;font-weight:600;white-space:nowrap;}

    .state-msg{padding:1.25rem 1.5rem;font-size:.9rem;color:var(--muted-foreground,#64748b);}
    .state-error{margin:0 1.5rem;border-radius:.6rem;border:1px solid rgba(248,113,113,.4);background:rgba(248,113,113,.08);padding:.8rem 1rem;font-size:.875rem;color:#b91c1c;}

    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;padding:5vh 1rem;z-index:120;overflow-y:auto;}
    .modal-overlay.is-open{display:flex;}
    .modal{background:var(--card);color:var(--card-foreground);width:100%;max-width:640px;border-radius:.9rem;border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,.25);}
    .modal.modal-lg{max-width:760px;}
    .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);}
    .modal-body{padding:1.1rem 1.25rem;}
    .modal-foot{display:flex;gap:.6rem;justify-content:flex-end;padding:1rem 1.25rem;border-top:1px solid var(--border);flex-wrap:wrap;}

    .field{display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;}
    .field label{font-size:.82rem;font-weight:600;}
    .field input,.field select,.field textarea{width:100%;border-radius:.6rem;border:1px solid var(--border);background:var(--background);padding:.55rem .7rem;font-size:.9rem;color:inherit;font-family:inherit;}
    .field textarea{min-height:110px;resize:vertical;}
    .field .hint{font-size:.74rem;color:var(--muted-foreground,#64748b);}

    /* Barre de gestion (statut + priorité) dans la modale détail */
    .manage-bar{display:flex;flex-wrap:wrap;gap:.9rem;align-items:flex-end;padding:.85rem;border:1px solid var(--border);border-radius:.7rem;background:rgba(148,163,184,.06);margin-bottom:1rem;}
    .manage-field{display:flex;flex-direction:column;gap:.3rem;min-width:170px;flex:1 1 170px;}
    .manage-field label{font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted-foreground,#64748b);}
    .manage-field select{height:36px;border-radius:.55rem;border:1px solid var(--border);background:var(--background);color:inherit;font-size:.85rem;padding:0 .55rem;font-family:inherit;}
    .manage-msg{flex:1 1 100%;}

    /* Fil de discussion */
    .thread{display:flex;flex-direction:column;gap:.75rem;max-height:42vh;overflow-y:auto;padding-right:.25rem;}
    .msg{border:1px solid var(--border);border-radius:.7rem;padding:.7rem .85rem;background:var(--background);}
    .msg.support{background:rgba(59,130,246,.07);border-color:rgba(59,130,246,.3);}
    .msg-meta{display:flex;justify-content:space-between;gap:.5rem;font-size:.74rem;color:var(--muted-foreground,#64748b);margin-bottom:.3rem;}
    .msg-author{font-weight:700;color:inherit;display:inline-flex;align-items:center;gap:.05rem;}
    .msg-author .author-role{font-weight:600;color:var(--muted-foreground,#64748b);margin-left:.15rem;}
    .msg-body{font-size:.88rem;white-space:pre-wrap;word-break:break-word;}

    /* Badge "agent certifié" */
    .cert-badge{width:.95rem;height:.95rem;flex:0 0 auto;vertical-align:-2px;margin-left:.25rem;}

    .agent-banner{display:flex;align-items:center;gap:.35rem;font-size:.8rem;color:var(--muted-foreground,#64748b);margin-bottom:.5rem;}
    .agent-banner b{color:inherit;display:inline-flex;align-items:center;gap:.05rem;}

    .meta-row{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.82rem;color:var(--muted-foreground,#64748b);margin-bottom:1rem;}
    .meta-row b{color:inherit;}

    .form-msg{font-size:.82rem;margin-top:.4rem;}
    .form-msg.err{color:#b91c1c;}
    .form-msg.ok{color:#047857;}

    .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
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

        <div data-slot="card" class="bg-background text-card-foreground flex flex-col gap-4 rounded-xl border py-5 shadow-sm">

          <!-- En-tête -->
          <div class="px-6 flex items-start justify-between gap-4 flex-wrap">
            <div>
              <h1 class="text-xl font-bold">Gestion des tickets</h1>
              <p class="text-sm text-muted-foreground mt-1">File de support : suivez, priorisez et répondez aux demandes des clients.</p>
            </div>
            <button id="btn-refresh" type="button" class="btn">
              <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
              Rafraîchir
            </button>
          </div>

          <!-- Filtres + compteurs -->
          <div class="px-6">
            <div class="filterbar">
              <div class="tabs" id="status-tabs" role="tablist">
                <button class="tab" role="tab" data-status="all" aria-selected="true">Tous <b id="c-total" class="tab-count">0</b></button>
                <button class="tab" role="tab" data-status="ouvert" aria-selected="false">Ouverts <b id="c-ouvert" class="tab-count">0</b></button>
                <button class="tab" role="tab" data-status="en_cours" aria-selected="false">En cours <b id="c-en_cours" class="tab-count">0</b></button>
                <button class="tab" role="tab" data-status="en_attente" aria-selected="false">En attente <b id="c-en_attente" class="tab-count">0</b></button>
                <button class="tab" role="tab" data-status="resolu" aria-selected="false">Résolus <b id="c-resolu" class="tab-count">0</b></button>
                <button class="tab" role="tab" data-status="ferme" aria-selected="false">Fermés <b id="c-ferme" class="tab-count">0</b></button>
              </div>
              <div class="filters-right">
                <select id="f-priority" aria-label="Filtrer par priorité">
                  <option value="all">Toutes priorités</option>
                  <option value="urgente">Urgente</option>
                  <option value="haute">Haute</option>
                  <option value="normale">Normale</option>
                  <option value="basse">Basse</option>
                </select>
                <select id="f-category" aria-label="Filtrer par catégorie">
                  <option value="all">Toutes catégories</option>
                  <option value="technique">Technique</option>
                  <option value="facturation">Facturation</option>
                  <option value="commercial">Commercial</option>
                  <option value="compte">Compte</option>
                  <option value="autre">Autre</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Tableau -->
          <div id="error-box" class="state-error" hidden></div>

          <div class="tickets-table-wrap px-2 md:px-6">
            <table class="tickets-table">
              <thead>
                <tr>
                  <th>Référence</th>
                  <th>Client</th>
                  <th>Objet</th>
                  <th>Catégorie</th>
                  <th>Priorité</th>
                  <th>Statut</th>
                  <th>Créé le</th>
                  <th>Maj</th>
                </tr>
              </thead>
              <tbody id="tickets-body">
                <tr><td colspan="8" class="state-msg" id="loading-row">Chargement des tickets…</td></tr>
              </tbody>
            </table>
          </div>

          <div class="state-msg" id="empty-row" hidden>Aucun ticket ne correspond à ces filtres.</div>

        </div>
      </div>
    </main>
  </div>

  <!-- ═══════════════════ MODALE : DÉTAIL / TRAITEMENT TICKET ═══════════════════ -->
  <div class="modal-overlay" id="modal-detail" role="dialog" aria-modal="true" aria-labelledby="modal-detail-title">
    <div class="modal modal-lg">
      <div class="modal-head">
        <div style="min-width:0;">
          <h2 class="text-lg font-semibold" id="modal-detail-title">Ticket</h2>
          <span class="ref" id="d-ref"></span>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Fermer">✕</button>
      </div>
      <div class="modal-body">
        <div class="meta-row" id="d-meta"></div>

        <!-- Barre de gestion -->
        <div class="manage-bar">
          <div class="manage-field">
            <label for="d-status">Statut</label>
            <select id="d-status">
              <option value="ouvert">Ouvert</option>
              <option value="en_cours">En cours</option>
              <option value="en_attente">En attente</option>
              <option value="resolu">Résolu</option>
              <option value="ferme">Fermé</option>
            </select>
          </div>
          <div class="manage-field">
            <label for="d-priority">Priorité</label>
            <select id="d-priority">
              <option value="basse">Basse</option>
              <option value="normale">Normale</option>
              <option value="haute">Haute</option>
              <option value="urgente">Urgente</option>
            </select>
          </div>
          <div class="form-msg manage-msg" id="manage-msg" hidden></div>
        </div>

        <div class="thread" id="d-thread"></div>

        <div id="d-reply-zone" style="margin-top:1rem;">
          <div class="agent-banner">
            Vous répondez en tant que <b><span id="agent-name"></span><span id="agent-badge"></span></b>
          </div>
          <div class="field" style="margin-bottom:.4rem;">
            <label for="d-reply" class="sr-only">Votre réponse</label>
            <textarea id="d-reply" maxlength="5000" placeholder="Répondre au client…" style="min-height:90px;"></textarea>
          </div>
          <div class="form-msg" id="detail-msg" hidden></div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" data-close>Fermer</button>
        <button type="button" class="btn btn-primary" id="btn-reply">Envoyer la réponse</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    'use strict';

    const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const AGENT_NAME = <?= json_encode($agentName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const API = new URL('../data/portail_api.php', window.location.href);

    // Badge "agent certifié" (SVG inline, contenu de confiance — non échappé).
    const CERT_BADGE =
      '<svg class="cert-badge" viewBox="0 0 24 24" fill="none" role="img" aria-label="Agent support certifié">' +
      '<title>Agent support certifié</title>' +
      '<circle cx="12" cy="12" r="10" fill="#2563eb"/>' +
      '<path d="M7.5 12.4l2.8 2.8L16.5 9" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>' +
      '</svg>';

    // ── État ───────────────────────────────────────────────────────────────
    let tickets = [];
    let filterStatus = 'all';
    let filterPriority = 'all';
    let filterCategory = 'all';
    let searchTerm = '';
    let current = null; // ticket ouvert dans la modale détail

    // Ordres de tri pour la file de triage.
    const STATUS_ORDER = { ouvert: 0, en_cours: 1, en_attente: 2, resolu: 3, ferme: 4 };
    const PRIO_ORDER   = { urgente: 0, haute: 1, normale: 2, basse: 3 };

    // ── Utilitaires DOM ──────────────────────────────────────────────────────
    const $ = (sel, root) => (root || document).querySelector(sel);
    const esc = (s) => String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const clientName = (t) =>
      t.client || t.client_name || t.client_email || (t.client_id != null ? '#' + t.client_id : '—');

    function setFormMsg(el, text, kind) {
      if (!el) return;
      if (!text) { el.hidden = true; el.textContent = ''; return; }
      el.hidden = false;
      el.textContent = text;
      el.className = (el.classList.contains('manage-msg') ? 'form-msg manage-msg' : 'form-msg') + (kind ? ' ' + kind : '');
    }

    function showError(text) {
      const box = $('#error-box');
      if (!text) { box.hidden = true; box.textContent = ''; return; }
      box.hidden = false;
      box.textContent = text;
    }

    function setSelectValue(sel, value) {
      if (!sel) return;
      const v = String(value == null ? '' : value);
      if (Array.from(sel.options).some((o) => o.value === v)) sel.value = v;
    }

    // ── Appels API (mêmes conventions que tickets.php) ───────────────────────
    async function apiGet(action, params) {
      const u = new URL(API.toString());
      u.searchParams.set('action', action);
      Object.entries(params || {}).forEach(([k, v]) => u.searchParams.set(k, v));
      const res = await fetch(u.toString(), { credentials: 'same-origin' });
      return readJson(res);
    }

    async function apiPost(action, fields) {
      const u = new URL(API.toString());
      u.searchParams.set('action', action);
      const res = await fetch(u.toString(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
        body: new URLSearchParams(fields || {}),
      });
      return readJson(res);
    }

    async function readJson(res) {
      const ct = (res.headers.get('content-type') || '').toLowerCase();
      const raw = await res.text();
      let data = null;
      try { data = JSON.parse(raw); } catch (_) { /* noop */ }
      if (!ct.includes('application/json') || !data) {
        throw new Error('Réponse inattendue (' + res.status + '). ' + raw.slice(0, 160).replace(/\s+/g, ' '));
      }
      if (!res.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + res.status));
      }
      return data;
    }

    // ── Chargement de la file ─────────────────────────────────────────────────
    async function load() {
      showError('');
      $('#loading-row') && ($('#loading-row').textContent = 'Chargement des tickets…');
      try {
        // Action admin : renvoie TOUS les tickets (le proxy vérifie le rôle).
        const data = await apiGet('admin.ticket.list');
        tickets = Array.isArray(data.tickets) ? data.tickets : [];
        renderChips();
        render();
      } catch (e) {
        tickets = [];
        $('#tickets-body').innerHTML = '';
        $('#empty-row').hidden = true;
        showError('Impossible de charger les tickets : ' + (e && e.message ? e.message : e));
      }
    }

    function renderChips() {
      const count = (k) => tickets.filter((t) => t.status === k).length;
      $('#c-total').textContent = tickets.length;
      $('#c-ouvert').textContent = count('ouvert');
      $('#c-en_cours').textContent = count('en_cours');
      $('#c-en_attente').textContent = count('en_attente');
      $('#c-resolu').textContent = count('resolu');
      $('#c-ferme').textContent = count('ferme');
    }

    function applyFilters() {
      let rows = tickets.filter((t) => {
        if (filterStatus !== 'all' && t.status !== filterStatus) return false;
        if (filterPriority !== 'all' && t.priority !== filterPriority) return false;
        if (filterCategory !== 'all' && t.category !== filterCategory) return false;
        if (searchTerm) {
          const hay = (t.ref + ' ' + clientName(t) + ' ' + (t.client_email || '') + ' ' +
                       t.subject + ' ' + t.category + ' ' + (t.message || '')).toLowerCase();
          if (!hay.includes(searchTerm)) return false;
        }
        return true;
      });
      // Tri de triage : non clos d'abord, puis priorité, puis ticket le plus récent.
      rows.sort((a, b) => {
        const sa = STATUS_ORDER[a.status] ?? 9, sb = STATUS_ORDER[b.status] ?? 9;
        if (sa !== sb) return sa - sb;
        const pa = PRIO_ORDER[a.priority] ?? 9, pb = PRIO_ORDER[b.priority] ?? 9;
        if (pa !== pb) return pa - pb;
        return (Number(b.id) || 0) - (Number(a.id) || 0);
      });
      return rows;
    }

    function render() {
      const body = $('#tickets-body');
      const rows = applyFilters();
      if (!tickets.length) {
        body.innerHTML = '<tr><td colspan="8" class="state-msg">Aucun ticket dans la file pour le moment.</td></tr>';
        $('#empty-row').hidden = true;
        return;
      }
      if (!rows.length) {
        body.innerHTML = '';
        $('#empty-row').hidden = false;
        return;
      }
      $('#empty-row').hidden = true;
      body.innerHTML = rows.map((t) => `
        <tr data-id="${esc(t.id)}">
          <td class="ref">${esc(t.ref)}</td>
          <td>
            <div class="client-cell">
              <span class="client-name">${esc(clientName(t))}</span>
              ${t.client_email ? `<span class="client-sub">${esc(t.client_email)}</span>` : ''}
            </div>
          </td>
          <td class="subj">${esc(t.subject)}</td>
          <td>${esc(t.category)}</td>
          <td><span class="badge ${esc(t.priority_class)}">${esc(t.priority_label)}</span></td>
          <td><span class="badge ${esc(t.status_class)}">${esc(t.status_label)}</span></td>
          <td>${esc(t.created_at)}</td>
          <td>${esc(t.updated_at)}</td>
        </tr>`).join('');

      body.querySelectorAll('tr[data-id]').forEach((tr) => {
        tr.addEventListener('click', () => openDetail(tr.getAttribute('data-id')));
      });
    }

    // ── Filtres UI ────────────────────────────────────────────────────────────
    $('#status-tabs').addEventListener('click', (ev) => {
      const btn = ev.target.closest('.tab');
      if (!btn) return;
      filterStatus = btn.getAttribute('data-status');
      $('#status-tabs').querySelectorAll('.tab').forEach((b) =>
        b.setAttribute('aria-selected', b === btn ? 'true' : 'false'));
      render();
    });
    $('#f-priority').addEventListener('change', (e) => { filterPriority = e.target.value; render(); });
    $('#f-category').addEventListener('change', (e) => { filterCategory = e.target.value; render(); });
    $('#btn-refresh').addEventListener('click', () => load());

    // Recherche : branchée sur la barre du header (#gestionTicketsSearchInput).
    const headerSearch = document.getElementById('gestionTicketsSearchInput');
    if (headerSearch) {
      headerSearch.addEventListener('input', (ev) => {
        searchTerm = (ev.target.value || '').trim().toLowerCase();
        render();
      });
    }

    // ── Gestion des modales ─────────────────────────────────────────────────
    function openModal(id) { $('#' + id).classList.add('is-open'); document.body.style.overflow = 'hidden'; }
    function closeModal(el) { el.classList.remove('is-open'); document.body.style.overflow = ''; }
    document.querySelectorAll('.modal-overlay').forEach((ov) => {
      ov.addEventListener('click', (e) => { if (e.target === ov) closeModal(ov); });
      ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeModal(ov)));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.is-open').forEach(closeModal);
    });

    // ── Détail / traitement d'un ticket ───────────────────────────────────────
    const findTicket = (id) => tickets.find((t) => String(t.id) === String(id)) || null;

    async function openDetail(id) {
      openModal('modal-detail');
      $('#d-reply').value = '';
      setFormMsg($('#detail-msg'), '');
      setFormMsg($('#manage-msg'), '');

      // Identité de l'agent affichée dans le bandeau de réponse.
      $('#agent-name').textContent = AGENT_NAME;
      $('#agent-badge').innerHTML = CERT_BADGE;

      // 1) Métadonnées fiables issues de la liste (statut, priorité, objet…).
      const base = findTicket(id);
      current = base ? Object.assign({}, base) : { id: id };
      renderDetail();

      // 2) Conversation complète via le détail.
      try {
        const data = await apiGet('admin.ticket.detail', { id });
        const det = data && data.ticket ? data.ticket : null;
        if (det) {
          if (base) {
            if (det.message) current.message = det.message;
            current.messages = Array.isArray(det.messages) ? det.messages : (current.messages || []);
          } else {
            current = det;
          }
          renderDetail();
        }
      } catch (e) {
        setFormMsg($('#detail-msg'), 'Conversation indisponible : ' + (e && e.message ? e.message : e), 'err');
      }
    }

    function renderDetail() {
      if (!current) return;
      const t = current;
      $('#modal-detail-title').textContent = t.subject || 'Ticket';
      $('#d-ref').textContent = t.ref || '';
      const subLabels = { dns: 'DNS', deployment: 'Déploiement' };
      const subTxt = t.subcategory ? (subLabels[t.subcategory] || t.subcategory) : '';
      $('#d-meta').innerHTML =
        `<span><b>Client :</b> ${esc(clientName(t))}${t.client_email ? ' (' + esc(t.client_email) + ')' : ''}</span>` +
        `<span><b>Catégorie :</b> ${esc(t.category || '—')}${subTxt ? ' · ' + esc(subTxt) : ''}</span>` +
        (t.deployments ? `<span><b>Déploiement(s) :</b> ${esc(t.deployments)}</span>` : '') +
        (t.domains ? `<span><b>Domaine(s) :</b> ${esc(t.domains)}</span>` : '') +
        `<span><b>Créé le :</b> ${esc(t.created_at || '—')}</span>` +
        (t.created_by ? `<span><b>Créé par :</b> ${esc(t.created_by)}</span>` : '');

      // Synchronise les sélecteurs de gestion sur l'état courant.
      setSelectValue($('#d-status'), t.status);
      setSelectValue($('#d-priority'), t.priority);

      const msgs = Array.isArray(t.messages) ? t.messages : [];
      const thread = [];
      if (msgs.length) {
        msgs.forEach((m) => thread.push(bubble(m)));
      } else if (t.message) {
        thread.push(bubble({ author: clientName(t), author_type: 'client', body: t.message, created_at: t.created_full || t.created_at }));
      }
      $('#d-thread').innerHTML = thread.join('') ||
        '<div class="state-msg" style="padding:0;">Aucun message.</div>';
      const thr = $('#d-thread');
      thr.scrollTop = thr.scrollHeight;
    }

    function bubble(m) {
      const support = m.author_type === 'support';
      const when = m.created_label || m.created_at || '';
      const full = m.created_at || '';
      const name = m.author || (support ? AGENT_NAME : 'Client');
      // Pour un message support : nom + badge certifié + libellé de rôle.
      const authorHtml = support
        ? esc(name) + CERT_BADGE + '<span class="author-role">· Support</span>'
        : esc(name);
      return `<div class="msg ${support ? 'support' : ''}">
        <div class="msg-meta"><span class="msg-author">${authorHtml}</span><span title="${esc(full)}">${esc(when)}</span></div>
        <div class="msg-body">${esc(m.body)}</div>
      </div>`;
    }

    // ── Réponse de l'agent ──────────────────────────────────────────────────
    $('#btn-reply').addEventListener('click', async () => {
      if (!current) return;
      const id = current.id;
      const body = $('#d-reply').value.trim();
      if (!body) { setFormMsg($('#detail-msg'), 'Saisissez un message.', 'err'); return; }
      const btn = $('#btn-reply');
      btn.disabled = true;
      setFormMsg($('#detail-msg'), 'Envoi…');
      try {
        await apiPost('admin.ticket.reply', {
          ticket_id: id,
          body,
          author_type: 'support',
          author_name: AGENT_NAME,
        });
        await load();
        await openDetail(id);
        setFormMsg($('#detail-msg'), 'Réponse envoyée.', 'ok');
      } catch (e) {
        setFormMsg($('#detail-msg'), 'Erreur : ' + (e && e.message ? e.message : e), 'err');
      } finally {
        btn.disabled = false;
      }
    });

    // ── Changement de statut ──────────────────────────────────────────────────
    $('#d-status').addEventListener('change', async (e) => {
      if (!current) return;
      const id = current.id;
      const status = e.target.value;
      const sel = e.target;
      sel.disabled = true;
      setFormMsg($('#manage-msg'), 'Mise à jour du statut…');
      try {
        await apiPost('admin.ticket.status', { ticket_id: id, status });
        await load();
        await openDetail(id);
        setFormMsg($('#manage-msg'), 'Statut mis à jour.', 'ok');
      } catch (err) {
        setFormMsg($('#manage-msg'), 'Erreur : ' + (err && err.message ? err.message : err), 'err');
        setSelectValue(sel, current.status); // on remet l'ancienne valeur
      } finally {
        sel.disabled = false;
      }
    });

    // ── Changement de priorité ────────────────────────────────────────────────
    $('#d-priority').addEventListener('change', async (e) => {
      if (!current) return;
      const id = current.id;
      const priority = e.target.value;
      const sel = e.target;
      sel.disabled = true;
      setFormMsg($('#manage-msg'), 'Mise à jour de la priorité…');
      try {
        await apiPost('admin.ticket.priority', { ticket_id: id, priority });
        await load();
        await openDetail(id);
        setFormMsg($('#manage-msg'), 'Priorité mise à jour.', 'ok');
      } catch (err) {
        setFormMsg($('#manage-msg'), 'Erreur : ' + (err && err.message ? err.message : err), 'err');
        setSelectValue(sel, current.priority);
      } finally {
        sel.disabled = false;
      }
    });

    // ── Go ────────────────────────────────────────────────────────────────────
    load();
  })();
  </script>
</body>
</html>
