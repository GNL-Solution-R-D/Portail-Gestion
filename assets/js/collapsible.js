/*!
 * Dépliants du menu (data-slot="collapsible-*") — contrôleur unique.
 *
 * Remplace les copies inline qui existaient dans chaque page. Ces copies
 * posaient un bug visible surtout sous Firefox :
 *   - à la fermeture, la hauteur passait de « auto » à « Npx » puis à « 0px »
 *     dans le requestAnimationFrame suivant, sans reflow forcé entre les deux.
 *     Firefox fusionne les deux changements (auto → 0, non animable) : pas de
 *     transition, donc pas de « transitionend », et l'écouteur onEndClose
 *     restait accroché ;
 *   - à l'ouverture suivante, la fin de l'animation déclenchait aussi cet
 *     écouteur orphelin → content.hidden = true → le menu se refermait d'un coup.
 *
 * Ici : reflow forcé avant chaque animation, un seul écouteur actif par
 * dépliant (l'ancien est toujours retiré), filtre sur ev.target et minuterie
 * de secours si « transitionend » ne vient jamais (prefers-reduced-motion,
 * onglet en arrière-plan, contenu vide…).
 */
(function () {
  'use strict';

  var DURATION_MS = 220;   // doit correspondre à la transition CSS .collapsible-content
  var FALLBACK_MS = DURATION_MS + 120;

  function findContent(btn) {
    var targetId = btn.getAttribute('aria-controls');
    var content = targetId ? document.getElementById(targetId) : null;
    if (!content) {
      var parent = btn.closest('[data-slot="collapsible"]');
      if (parent) content = parent.querySelector('[data-slot="collapsible-content"]');
    }
    return content;
  }

  function setState(btn, content, open) {
    var state = open ? 'open' : 'closed';
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('data-state', state);
    content.setAttribute('data-state', state);
  }

  function init(btn) {
    if (btn.dataset.collapsibleReady === '1') return;
    var content = findContent(btn);
    if (!content) return;
    btn.dataset.collapsibleReady = '1';

    btn.classList.add('collapsible-trigger');
    content.classList.add('collapsible-content');
    var chev = btn.querySelector('.lucide-chevron-right');
    if (chev) chev.classList.add('collapsible-chevron');

    // Animation en cours (écouteur + minuterie) : on l'annule toujours avant
    // d'en lancer une autre, pour ne jamais laisser d'écouteur orphelin.
    var pending = null;
    function cancelPending() {
      if (!pending) return;
      content.removeEventListener('transitionend', pending.onEnd);
      clearTimeout(pending.timer);
      pending = null;
    }
    function afterTransition(done) {
      cancelPending();
      var p = {};
      function finish() {
        if (pending !== p) return;
        cancelPending();
        done();
      }
      p.onEnd = function (ev) {
        if (ev.target !== content || ev.propertyName !== 'height') return;
        finish();
      };
      p.timer = setTimeout(finish, FALLBACK_MS);
      pending = p;
      content.addEventListener('transitionend', p.onEnd);
    }

    function open() {
      setState(btn, content, true);
      content.hidden = false;
      content.classList.add('is-open');
      // Point de départ : hauteur actuelle (0 si fermé, valeur intermédiaire
      // si on rouvre pendant une fermeture).
      content.style.height = content.getBoundingClientRect().height + 'px';
      void content.offsetHeight;                       // reflow forcé
      content.style.height = content.scrollHeight + 'px';
      afterTransition(function () { content.style.height = 'auto'; });
    }

    function close() {
      setState(btn, content, false);
      content.classList.remove('is-open');
      content.style.height = content.getBoundingClientRect().height + 'px';
      void content.offsetHeight;                       // reflow forcé
      content.style.height = '0px';
      afterTransition(function () { content.hidden = true; });
    }

    // État initial, sans animation.
    if (btn.getAttribute('aria-expanded') === 'true') {
      setState(btn, content, true);
      content.hidden = false;
      content.classList.add('is-open');
      content.style.height = 'auto';
    } else {
      setState(btn, content, false);
      content.hidden = true;
      content.classList.remove('is-open');
      content.style.height = '0px';
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (btn.getAttribute('aria-expanded') === 'true') close(); else open();
    });
  }

  function initAll(root) {
    (root || document).querySelectorAll('[data-slot="collapsible-trigger"]').forEach(init);
  }

  window.GnlCollapsible = { init: initAll };

  if (document.readyState !== 'loading') initAll();
  else document.addEventListener('DOMContentLoaded', function () { initAll(); });
})();
