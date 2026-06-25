<?php

declare(strict_types=1);

/**
 * pages/gestion-ticket.php
 *
 * Console SUPPORT (équipe GNL) : voir tous les tickets clients, répondre en tant
 * que support, et changer statut / priorité.
 *
 * Sécurité : l'accès est doublement protégé —
 *   1) ici (redirection si l'utilisateur n'est pas support) ;
 *   2) dans data/portail_api.php via require_support() sur chaque action support.*.
 * Les critères ci-dessous doivent rester alignés avec TICKET_SUPPORT_* du proxy.
 */

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

// Ce site est dédié au support : l'accès est géré en amont (authentification du
// portail support). Aucune restriction de rôle supplémentaire ici. Le proxy garde
// require_support(), neutralisé par TICKET_SUPPORT_SITE = true côté data/portail_api.php.

if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf'];

// Nom affiché de l'agent (civilité pointée + prénom + nom) pour la signature support.
$gtCivMap  = ['m' => 'M.', 'mr' => 'M.', 'monsieur' => 'M.', 'mme' => 'Mme', 'madame' => 'Mme', 'mlle' => 'Mlle', 'dr' => 'Dr', 'me' => 'Me'];
$gtU       = $_SESSION['user'];
$gtCiv     = $gtCivMap[strtolower(trim((string) ($gtU['civilite'] ?? $gtU['civility'] ?? '')))] ?? '';
$agentName = trim(preg_replace('/\s+/', ' ', trim($gtCiv . ' ' . ($gtU['prenom'] ?? '') . ' ' . ($gtU['nom'] ?? ''))));
if ($agentName === '') {
    $agentName = trim((string) ($gtU['username'] ?? $gtU['email'] ?? 'Support GNL'));
}

$pageTitle = 'Gestion des tickets - Support GNL';

// Barre de recherche du header
$showSearch        = true;
$searchInputId     = 'gestionSearchInput';
$searchPlaceholder = 'Rechercher (objet, client, référence…)';
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

    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;height:38px;border-radius:.6rem;border:1px solid var(--border);padding:0 .9rem;font-size:.875rem;font-weight:500;cursor:pointer;transition:background .15s ease,opacity .15s ease;background:var(--background);color:inherit;}
    .btn:hover{background:var(--secondary);}
    .btn:disabled{opacity:.55;cursor:not-allowed;}
    .btn-primary{background:var(--primary);color:var(--primary-foreground);border-color:transparent;}
    .btn-primary:hover{background:var(--primary);opacity:.9;}
    .btn-ghost{border-color:transparent;background:transparent;}
    .btn-ghost:hover{background:var(--secondary);}
    .btn-sm{height:32px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem;}
    .icon{width:1rem;height:1rem;flex:0 0 1rem;display:block;}

    .filterbar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;}
    .tabs{display:flex;flex-wrap:wrap;gap:.3rem;}
    .tab{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--border);border-radius:999px;padding:.32rem .7rem;font-size:.8rem;cursor:pointer;background:var(--background);transition:all .15s ease;}
    .tab[aria-selected="true"]{background:var(--primary);color:var(--primary-foreground);border-color:transparent;}
    .tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:1.3rem;height:1.15rem;padding:0 .35rem;border-radius:999px;font-size:.7rem;font-weight:700;background:var(--secondary);color:var(--muted-foreground,#64748b);}
    .tab[aria-selected="true"] .tab-count{background:color-mix(in srgb, var(--primary-foreground) 22%, transparent);color:var(--primary-foreground);}
    .select{height:36px;border-radius:.6rem;border:1px solid var(--border);background:var(--background);padding:0 .6rem;font-size:.83rem;color:inherit;font-family:inherit;}

    .tickets-table-wrap{overflow-x:auto;}
    .tickets-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px;}
    .tickets-table th,.tickets-table td{padding:.8rem 1rem;border-bottom:1px solid rgba(148,163,184,.22);font-size:.9rem;text-align:left;vertical-align:middle;}
    .tickets-table th{text-transform:uppercase;letter-spacing:.04em;font-size:.7rem;color:var(--muted-foreground,#64748b);white-space:nowrap;}
    .tickets-table tbody tr{cursor:pointer;}
    .tickets-table tbody tr:hover{background:rgba(148,163,184,.08);}
    .tickets-table .ref{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:.82rem;color:var(--muted-foreground,#64748b);white-space:nowrap;}
    .tickets-table .subj{font-weight:600;}
    .cell-client{display:flex;flex-direction:column;line-height:1.15;}
    .cell-client small{color:var(--muted-foreground,#64748b);font-size:.74rem;}

    .badge{display:inline-flex;align-items:center;justify-content:center;border-radius:.5rem;padding:.18rem .55rem;font-size:.74rem;font-weight:600;white-space:nowrap;}

    .state-msg{padding:1.25rem 1.5rem;font-size:.9rem;color:var(--muted-foreground,#64748b);}
    .state-error{margin:0 1.5rem;border-radius:.6rem;border:1px solid rgba(248,113,113,.4);background:rgba(248,113,113,.08);padding:.8rem 1rem;font-size:.875rem;color:#b91c1c;}

    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;padding:5vh 1rem;z-index:120;overflow-y:auto;}
    .modal-overlay.is-open{display:flex;}
    .modal{background:var(--card);color:var(--card-foreground);width:100%;max-width:780px;border-radius:.9rem;border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,.25);}
    .modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);}
    .modal-body{padding:1.1rem 1.25rem;}
    .modal-foot{display:flex;gap:.6rem;justify-content:flex-end;align-items:center;padding:1rem 1.25rem;border-top:1px solid var(--border);flex-wrap:wrap;}
    .modal-foot .spacer{flex:1 1 auto;}

    .field{display:flex;flex-direction:column;gap:.35rem;}
    .field label{font-size:.82rem;font-weight:600;}
    .field textarea{width:100%;border-radius:.6rem;border:1px solid var(--border);background:var(--background);padding:.55rem .7rem;font-size:.9rem;color:inherit;font-family:inherit;min-height:90px;resize:vertical;}

    .thread{display:flex;flex-direction:column;gap:.55rem;max-height:42vh;overflow-y:auto;padding-right:.25rem;margin-bottom:1rem;}
    .msg{max-width:80%;border:1px solid var(--border);border-radius:.95rem;padding:.55rem .8rem;background:var(--background);}
    .msg.them{align-self:flex-start;background:rgba(148,163,184,.16);border-color:rgba(148,163,184,.30);border-bottom-left-radius:.3rem;}
    .msg.mine{align-self:flex-end;background:rgba(34,197,94,.16);border-color:rgba(34,197,94,.32);border-bottom-right-radius:.3rem;}
    .msg-meta{display:flex;justify-content:space-between;align-items:center;gap:.9rem;font-size:.72rem;color:var(--muted-foreground,#64748b);margin-bottom:.25rem;}
    .msg-author{font-weight:700;color:inherit;display:inline-flex;align-items:center;}
    .msg-body{font-size:.88rem;white-space:pre-wrap;word-break:break-word;}

    /* Badge certifié support */
    .certif{display:inline-flex;vertical-align:middle;margin-left:.3rem;color:#2563eb;}
    .certif svg{width:.98rem;height:.98rem;display:block;}
    .reply-as{display:inline-flex;align-items:center;gap:.35rem;font-size:.78rem;color:var(--muted-foreground,#64748b);margin-bottom:.4rem;}
    .reply-as b{color:inherit;}

    .meta-row{display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;font-size:.82rem;color:var(--muted-foreground,#64748b);margin-bottom:1rem;}
    .meta-row b{color:inherit;}

    .ctrl-row{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-bottom:1rem;}
    .ctrl-row .field{min-width:160px;}

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

          <div class="px-6">
            <h1 class="text-xl font-bold">Gestion des tickets</h1>
            <p class="text-sm text-muted-foreground mt-1">Console support : tickets de tous les clients, réponses et suivi des statuts.</p>
          </div>

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
              <select id="prio-filter" class="select" title="Filtrer par priorité">
                <option value="all">Toutes priorités</option>
                <option value="urgente">Urgente</option>
                <option value="haute">Haute</option>
                <option value="normale">Normale</option>
                <option value="basse">Basse</option>
              </select>
            </div>
          </div>

          <div id="error-box" class="state-error" hidden></div>

          <div class="tickets-table-wrap px-2 md:px-6">
            <table class="tickets-table">
              <thead>
                <tr>
                  <th>Référence</th>
                  <th>Objet</th>
                  <th>Client</th>
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

          <div class="state-msg" id="empty-row" hidden>Aucun ticket ne correspond à ce filtre.</div>
        </div>
      </div>
    </main>
  </div>

  <!-- ═══════════════════ MODALE : DÉTAIL / TRAITEMENT ═══════════════════ -->
  <div class="modal-overlay" id="modal-detail" role="dialog" aria-modal="true" aria-labelledby="modal-detail-title">
    <div class="modal">
      <div class="modal-head">
        <div style="min-width:0;">
          <h2 class="text-lg font-semibold" id="modal-detail-title">Ticket</h2>
          <span class="ref" id="d-ref"></span>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Fermer">✕</button>
      </div>
      <div class="modal-body">
        <div class="meta-row" id="d-meta"></div>
        <div class="thread" id="d-thread"></div>

        <!-- Traitement : statut + priorité -->
        <div class="ctrl-row">
          <div class="field">
            <label for="d-status">Statut</label>
            <select id="d-status" class="select">
              <option value="ouvert">Ouvert</option>
              <option value="en_cours">En cours</option>
              <option value="en_attente">En attente</option>
              <option value="resolu">Résolu</option>
              <option value="ferme">Fermé</option>
            </select>
          </div>
          <div class="field">
            <label for="d-priority">Priorité</label>
            <select id="d-priority" class="select">
              <option value="basse">Basse</option>
              <option value="normale">Normale</option>
              <option value="haute">Haute</option>
              <option value="urgente">Urgente</option>
            </select>
          </div>
          <button type="button" class="btn" id="btn-update">Mettre à jour</button>
        </div>

        <div class="field">
          <div class="reply-as">Vous répondez en tant que <b><?= htmlspecialchars($agentName, ENT_QUOTES, 'UTF-8') ?></b>
            <span class="certif" id="reply-certif" title="Support certifié GNL Solution"></span>
          </div>
          <label for="d-reply" class="sr-only">Réponse au client</label>
          <textarea id="d-reply" maxlength="5000" placeholder="Votre réponse…"></textarea>
        </div>
        <div class="form-msg" id="detail-msg" hidden></div>
      </div>
      <div class="modal-foot">
        <span class="spacer"></span>
        <button type="button" class="btn" data-close>Fermer</button>
        <button type="button" class="btn btn-primary" id="btn-reply">Envoyer la réponse</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    'use strict';

    const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const API = new URL('../data/portail_api.php', window.location.href);

    // Badge certifié (sceau + coche) pour les messages du support.
    // Côté du visiteur (agent support) : à droite, style WhatsApp.
    const OWN_SIDE = 'support';
    const CERTIF = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1l2.6 1.9 3.2-.2 1 3L22.4 9l-1 3 1 3-2.6 1.9-1 3-3.2-.2L12 23l-2.6-1.9-3.2.2-1-3L2.6 15l1-3-1-3 2.6-1.9 1-3 3.2.2L12 1z"/><path d="M10.6 14.3l-2-2-1.2 1.2 3.2 3.2 5.4-5.4-1.2-1.2-4.2 4.2z" fill="#fff"/></svg>';

    let tickets = [];
    let filterStatus = 'all';
    let filterPrio = 'all';
    let searchTerm = '';
    let current = null;

    const $ = (sel, root) => (root || document).querySelector(sel);
    const esc = (s) => String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    function setFormMsg(el, text, kind) {
      if (!el) return;
      if (!text) { el.hidden = true; el.textContent = ''; return; }
      el.hidden = false; el.textContent = text;
      el.className = 'form-msg' + (kind ? ' ' + kind : '');
    }
    function showError(text) {
      const box = $('#error-box');
      if (!text) { box.hidden = true; box.textContent = ''; return; }
      box.hidden = false; box.textContent = text;
    }

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
      try { data = JSON.parse(raw); } catch (_) {}
      if (!ct.includes('application/json') || !data) {
        throw new Error('Réponse inattendue (' + res.status + '). ' + raw.slice(0, 160).replace(/\s+/g, ' '));
      }
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    async function load() {
      showError('');
      try {
        const data = await apiGet('support.ticket.list');
        tickets = Array.isArray(data.tickets) ? data.tickets : [];
        renderCounts();
        render();
      } catch (e) {
        tickets = [];
        $('#tickets-body').innerHTML = '';
        $('#empty-row').hidden = true;
        showError('Impossible de charger les tickets : ' + (e && e.message ? e.message : e));
      }
    }

    function renderCounts() {
      const c = (k) => tickets.filter((t) => t.status === k).length;
      $('#c-total').textContent = tickets.length;
      $('#c-ouvert').textContent = c('ouvert');
      $('#c-en_cours').textContent = c('en_cours');
      $('#c-en_attente').textContent = c('en_attente');
      $('#c-resolu').textContent = c('resolu');
      $('#c-ferme').textContent = c('ferme');
    }

    function applyFilters() {
      return tickets.filter((t) => {
        if (filterStatus !== 'all' && t.status !== filterStatus) return false;
        if (filterPrio !== 'all' && t.priority !== filterPrio) return false;
        if (searchTerm) {
          const hay = (t.ref + ' ' + t.subject + ' ' + (t.created_by || '') + ' ' + (t.structure || '') + ' ' + t.category + ' ' + (t.message || '')).toLowerCase();
          if (!hay.includes(searchTerm)) return false;
        }
        return true;
      });
    }

    function render() {
      const body = $('#tickets-body');
      const rows = applyFilters();
      if (!tickets.length) {
        body.innerHTML = '<tr><td colspan="8" class="state-msg">Aucun ticket pour le moment.</td></tr>';
        $('#empty-row').hidden = true;
        return;
      }
      if (!rows.length) { body.innerHTML = ''; $('#empty-row').hidden = false; return; }
      $('#empty-row').hidden = true;
      body.innerHTML = rows.map((t) => `
        <tr data-id="${esc(t.id)}">
          <td class="ref">${esc(t.ref)}</td>
          <td class="subj">${esc(t.subject)}</td>
          <td><span class="cell-client">${esc(t.created_by || '—')}${t.structure ? '<small>' + esc(t.structure) + '</small>' : ''}</span></td>
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

    $('#status-tabs').addEventListener('click', (ev) => {
      const btn = ev.target.closest('.tab');
      if (!btn) return;
      filterStatus = btn.getAttribute('data-status');
      $('#status-tabs').querySelectorAll('.tab').forEach((b) => b.setAttribute('aria-selected', b === btn ? 'true' : 'false'));
      render();
    });
    $('#prio-filter').addEventListener('change', (ev) => { filterPrio = ev.target.value; render(); });

    const headerSearch = document.getElementById('gestionSearchInput');
    if (headerSearch) {
      headerSearch.addEventListener('input', (ev) => { searchTerm = (ev.target.value || '').trim().toLowerCase(); render(); });
    }

    // Modale
    function openModal(id) { $('#' + id).classList.add('is-open'); document.body.style.overflow = 'hidden'; }
    function closeModal(el) { el.classList.remove('is-open'); document.body.style.overflow = ''; }
    document.querySelectorAll('.modal-overlay').forEach((ov) => {
      ov.addEventListener('click', (e) => { if (e.target === ov) closeModal(ov); });
      ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeModal(ov)));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.is-open').forEach(closeModal);
    });

    const findTicket = (id) => tickets.find((t) => String(t.id) === String(id)) || null;

    async function openDetail(id) {
      openModal('modal-detail');
      $('#d-reply').value = '';
      setFormMsg($('#detail-msg'), '');
      const base = findTicket(id);
      current = base ? Object.assign({}, base) : { id: id };
      renderDetail();
      try {
        const data = await apiGet('support.ticket.detail', { id });
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
        `<span><b>Client :</b> ${esc(t.created_by || '—')}${t.structure ? ' · ' + esc(t.structure) : ''}</span>` +
        `<span><b>Catégorie :</b> ${esc(t.category || '—')}${subTxt ? ' · ' + esc(subTxt) : ''}</span>` +
        (t.deployments ? `<span><b>Déploiement(s) :</b> ${esc(t.deployments)}</span>` : '') +
        (t.domains ? `<span><b>Domaine(s) :</b> ${esc(t.domains)}</span>` : '') +
        `<span><b>Créé le :</b> ${esc(t.created_at || '—')}</span>`;

      const msgs = Array.isArray(t.messages) ? t.messages : [];
      const thread = [];
      if (msgs.length) {
        msgs.forEach((m) => thread.push(bubble(m)));
      } else if (t.message) {
        thread.push(bubble({ author: t.created_by || 'Client', author_type: 'client', body: t.message, created_at: t.created_full || t.created_at }));
      }
      $('#d-thread').innerHTML = thread.join('') || '<div class="state-msg" style="padding:0;">Aucun message.</div>';
      const thr = $('#d-thread'); thr.scrollTop = thr.scrollHeight;

      if (t.status) $('#d-status').value = t.status;
      if (t.priority) $('#d-priority').value = t.priority;
    }

    function bubble(m) {
      const support = m.author_type === 'support';
      const mine = m.author_type === OWN_SIDE;
      const when = m.created_label || m.created_at || '';
      const full = m.created_at || '';
      const badge = support ? `<span class="certif" title="Support certifié GNL Solution">${CERTIF}</span>` : '';
      return `<div class="msg ${mine ? 'mine' : 'them'}">
        <div class="msg-meta"><span class="msg-author">${esc(m.author)}${badge}</span><span title="${esc(full)}">${esc(when)}</span></div>
        <div class="msg-body">${esc(m.body)}</div>
      </div>`;
    }

    $('#btn-reply').addEventListener('click', async () => {
      if (!current) return;
      const id = current.id;
      const body = $('#d-reply').value.trim();
      if (!body) { setFormMsg($('#detail-msg'), 'Saisissez un message.', 'err'); return; }
      const btn = $('#btn-reply'); btn.disabled = true;
      setFormMsg($('#detail-msg'), 'Envoi…');
      try {
        await apiPost('support.ticket.reply', { ticket_id: id, body });
        await load();
        await openDetail(id);
        setFormMsg($('#detail-msg'), 'Réponse envoyée.', 'ok');
      } catch (e) {
        setFormMsg($('#detail-msg'), 'Erreur : ' + (e && e.message ? e.message : e), 'err');
      } finally { btn.disabled = false; }
    });

    $('#btn-update').addEventListener('click', async () => {
      if (!current) return;
      const id = current.id;
      const status = $('#d-status').value;
      const priority = $('#d-priority').value;
      const btn = $('#btn-update'); btn.disabled = true;
      setFormMsg($('#detail-msg'), 'Mise à jour…');
      try {
        await apiPost('support.ticket.update', { ticket_id: id, status, priority });
        await load();
        await openDetail(id);
        setFormMsg($('#detail-msg'), 'Ticket mis à jour.', 'ok');
      } catch (e) {
        setFormMsg($('#detail-msg'), 'Erreur : ' + (e && e.message ? e.message : e), 'err');
      } finally { btn.disabled = false; }
    });

    const rc = document.getElementById('reply-certif');
    if (rc) rc.innerHTML = CERTIF;

    load();
  })();
  </script>
</body>
</html>