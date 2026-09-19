<?php
/* =====================================================================
   GNL Solution — FILTRE PAR ORGANISATION  (include/org_filter.php)
   ---------------------------------------------------------------------
   Les raccourcis de la page /entreprises ouvrent /facture, /commande,
   /abonnements, /gestion-ticket et /deployment avec ?org=<uuid> :
   l'UID Keycloak de l'organisation (ex. couturemania =
   3df7b7a6-329d-4375-b3b1-a4619de4f5eb).

   Ce fichier fait deux choses, et seulement si ce paramètre est présent
   ET bien formé :

     1. il installe un habillage de window.fetch qui rajoute « org » aux
        requêtes vers data/portail_api.php ;
     2. il affiche un bandeau « Filtré sur <entreprise> » avec un lien
        pour retirer le filtre.

   POURQUOI HABILLER fetch PLUTÔT QUE MODIFIER CHAQUE APPEL
   --------------------------------------------------------
   Les pages concernées contiennent des dizaines d'appels à
   portail_api.php (deployment.php à elle seule en a une vingtaine).
   Les reprendre un par un, c'est autant d'occasions d'en oublier un —
   et un appel oublié renvoie les données de TOUS les clients au milieu
   d'une page censée être filtrée, sans que rien ne le signale.
   L'habillage est délibérément étroit : il n'agit que sur les URL qui
   pointent vers portail_api.php, n'écrase jamais un « org » déjà
   présent, et ne touche ni à la méthode, ni au corps, ni aux en-têtes.

   Côté serveur, c'est data/portail_api.php (gnl_org_filter()) qui relit
   ce paramètre, revalide la forme UUID et le transmet à n8n sous la clé
   « organization_uid ». Sans filtre, aucune clé n'est ajoutée au payload.

   Ce fichier n'émet du HTML que lorsqu'un filtre est actif.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../config_loader.php';

/**
 * UID de l'organisation filtrée, ou '' s'il n'y a pas de filtre.
 *
 * La forme UUID est exigée : c'est ce que Keycloak produit, et cela évite
 * de promener une chaîne arbitraire venue de l'URL jusque dans un workflow
 * n8n ou dans le HTML. Un paramètre mal formé est traité comme absent.
 */
if (!function_exists('gnlOrgFilterUid')) {
    function gnlOrgFilterUid(): string
    {
        static $uid = null;
        if ($uid !== null) {
            return $uid;
        }

        $raw = trim((string) ($_GET['org'] ?? ''));
        $uid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw)
            ? strtolower($raw)
            : '';

        return $uid;
    }
}

/**
 * Nom affichable de l'organisation filtrée.
 *
 * Lu auprès de Keycloak plutôt que pris dans l'URL : le nom vient ainsi de
 * la source de vérité et ne peut pas être maquillé par un lien trafiqué.
 * Best-effort — si l'Admin REST est indisponible, on retombe sur l'UID,
 * qui reste un repère exact.
 */
if (!function_exists('gnlOrgFilterLabel')) {
    function gnlOrgFilterLabel(): string
    {
        static $label = null;
        if ($label !== null) {
            return $label;
        }

        $uid = gnlOrgFilterUid();
        if ($uid === '') {
            return $label = '';
        }

        $label = $uid;
        try {
            require_once __DIR__ . '/keycloak_esp_client.php';
            $r = kcEspOrganizationById($uid);
            if ($r['ok'] && is_array($r['org']) && trim((string) $r['org']['label']) !== '') {
                $label = trim((string) $r['org']['label']);
            }
        } catch (Throwable $e) {
            error_log('[GNL ORG-FILTER] libellé indisponible pour ' . $uid . ' : ' . $e->getMessage());
        }

        return $label;
    }
}

/** URL de la page courante débarrassée du paramètre « org ». */
if (!function_exists('gnlOrgFilterClearUrl')) {
    function gnlOrgFilterClearUrl(): string
    {
        $path  = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $query = $_GET;
        unset($query['org']);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $path . ($qs !== '' ? '?' . $qs : '');
    }
}

/* ---------------------------------------------------------------------
   Rien à faire sans filtre : la page se comporte exactement comme avant.
   --------------------------------------------------------------------- */
if (gnlOrgFilterUid() === '') {
    return;
}
?>
<style>
  .gnl-org-filter{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin:0 0 1rem;padding:.7rem 1rem;border-radius:.75rem;border:1px solid rgba(148,163,184,.4);background:color-mix(in srgb, currentColor 5%, transparent);font-size:.875rem;}
  .gnl-org-filter b{font-weight:600;}
  .gnl-org-filter a{margin-left:auto;font-weight:600;text-decoration:none;color:inherit;opacity:.75;}
  .gnl-org-filter a:hover{opacity:1;text-decoration:underline;}
  .gnl-org-filter svg{flex:none;}
</style>
<script>
(function () {
  var F = {
    uid:      <?= json_encode(gnlOrgFilterUid(), JSON_UNESCAPED_SLASHES) ?>,
    label:    <?= json_encode(gnlOrgFilterLabel(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    clearUrl: <?= json_encode(gnlOrgFilterClearUrl(), JSON_UNESCAPED_SLASHES) ?>,
    apiFile:  'portail_api.php'
  };
  window.GNL_ORG_FILTER = F;

  /* --- 1) fetch : ajoute « org » aux seuls appels vers portail_api.php --- */
  var nativeFetch = window.fetch;
  if (typeof nativeFetch === 'function') {
    window.fetch = function (input, init) {
      try {
        var isRequest = (typeof Request !== 'undefined') && (input instanceof Request);
        var url = isRequest ? input.url : String(input);

        if (url.indexOf(F.apiFile) !== -1) {
          var u = new URL(url, window.location.href);
          if (!u.searchParams.has('org')) {
            u.searchParams.set('org', F.uid);
            // Un Request est immuable : on le reconstruit en conservant
            // méthode, corps, en-têtes et credentials d'origine.
            input = isRequest ? new Request(u.toString(), input) : u.toString();
          }
        }
      } catch (e) {
        /* URL exotique : on laisse passer l'appel tel quel. */
      }
      return nativeFetch.call(this, input, init);
    };
  }

  /* --- 2) bandeau « Filtré sur … » ------------------------------------- */
  function banner() {
    var wrap = document.createElement('div');
    wrap.className = 'gnl-org-filter';
    wrap.setAttribute('role', 'status');

    var icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>';
    var esc  = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    }); };

    wrap.innerHTML = icon +
      '<span>Filtré sur l\'entreprise <b>' + esc(F.label) + '</b></span>' +
      '<a href="' + esc(F.clearUrl) + '">Retirer le filtre</a>';

    var host = document.querySelector('main.dashboard-main > div') ||
               document.querySelector('main.dashboard-main') ||
               document.body;
    host.insertBefore(wrap, host.firstChild);
  }

  if (document.readyState !== 'loading') banner();
  else document.addEventListener('DOMContentLoaded', banner);
})();
</script>
