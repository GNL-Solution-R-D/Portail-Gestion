<?php
/* Modale « Planifier une maintenance » (maquette Modale-Maintenance).
   Ouverte par tout bouton [data-gs-open="maintModal"] ; envoi par
   assets/js/gnl-staff.js → action maintenance.create (n8n). */
?>
  <div class="gs-modal" id="maintModal" role="dialog" aria-modal="true" aria-labelledby="maintTitle" hidden>
    <form class="gs-dialog gs-dialog--wide" id="maintForm" novalidate>
      <div class="gs-dialog__head">
        <div>
          <h2 class="ec-h2" id="maintTitle">Planifier une maintenance</h2>
          <p class="ec-muted ec-small">Les clients concernés sont prévenus et le bandeau s'affiche dans leur Espace Client.</p>
        </div>
        <button type="button" class="ec-icon-btn" data-gs-close aria-label="Fermer">✕</button>
      </div>
      <div class="gs-dialog__body gs-grid">
        <label class="gs-field gs-span2">Titre *
          <input type="text" name="title" required maxlength="160" placeholder="Mise à jour du cluster web">
        </label>
        <fieldset class="gs-field gs-span2"><legend>Type</legend>
          <div class="gs-seg">
            <label><input type="radio" name="type" value="planifiee" checked><span>Planifiée</span></label>
            <label><input type="radio" name="type" value="urgente"><span>Urgente</span></label>
          </div>
        </fieldset>
        <fieldset class="gs-field gs-span2"><legend>Services concernés</legend>
          <div class="gs-checks" id="maintServices"><p class="ec-muted ec-small">Chargement des services…</p></div>
          <p class="ec-muted ec-small" id="maintReach"></p>
        </fieldset>
        <label class="gs-field">Début *
          <input type="datetime-local" name="starts_at" required>
        </label>
        <label class="gs-field">Fin prévue *
          <input type="datetime-local" name="ends_at" required>
        </label>
        <label class="gs-field gs-span2">Impact pour les clients
          <select name="impact">
            <option value="none">Aucune coupure prévue</option>
            <option value="restarts" selected>Redémarrages possibles (quelques minutes)</option>
            <option value="outage">Services indisponibles pendant le créneau</option>
          </select>
        </label>
        <label class="gs-field gs-span2">Message affiché aux clients
          <textarea name="message" rows="3" maxlength="600">Aucune coupure prévue, redémarrages possibles.</textarea>
        </label>
        <div class="gs-span2 gs-preview">
          <p class="ec-kicker">APERÇU DU BANDEAU</p>
          <p class="ec-banner" id="maintPreview"></p>
        </div>
        <label class="gs-check gs-span2"><input type="checkbox" name="notify" checked> <span>Prévenir les clients par e-mail et notification</span></label>
      </div>
      <div class="gs-dialog__foot">
        <button type="button" class="ec-btn ec-btn--ghost" data-gs-close>Annuler</button>
        <button type="submit" class="ec-btn ec-btn--primary">Planifier la maintenance</button>
      </div>
    </form>
  </div>
