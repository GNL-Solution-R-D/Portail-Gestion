/**
 * Inject project-based deployment shortcuts into the sidebar submenu.
 */

(async function(){
  const host = document.getElementById('k8s-deployments');
  if(!host) return;

  host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Chargement…</div>';

  const apiBase = (() => {
    if (typeof window !== 'undefined' && window.PROJECTS_MENU_API_URL) {
      return new URL(String(window.PROJECTS_MENU_API_URL), window.location.href);
    }

    const inPagesDir = window.location.pathname.includes('/pages/');
    const fallbackPath = inPagesDir ? '../data/projects_menu_api.php' : './data/projects_menu_api.php';
    return new URL(fallbackPath, window.location.href);
  })();

  try{
    const res = await fetch(apiBase.toString(), { credentials: 'same-origin' });
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    const raw = await res.text();
    let data = null;
    try { data = JSON.parse(raw); } catch(_) { /* ignore */ }

    if(!ct.includes('application/json') || !data){
      throw new Error(buildNonJsonError(res.status, apiBase.pathname, raw));
    }
    if(!res.ok || !data.ok){
      throw new Error(data.error || ('HTTP ' + res.status));
    }

    const projects = Array.isArray(data.projects) ? data.projects : [];
    if(projects.length === 0){
      host.innerHTML = '<div class="text-muted-foreground text-xs px-2.5 py-1">Aucun déploiement accessible</div>';
      return;
    }

    host.innerHTML = projects.map(project => {
      const projectName = String(project.name || '').trim();
      const deploymentSubtag = String(project.deployment_subtag || '').trim();

      if (!projectName || !deploymentSubtag) {
        return '';
      }

      const href = 'https://espace-client.gnl-solution.fr/deployment?deployment=' + encodeURIComponent(deploymentSubtag);
      return `
        <a class="text-muted-foreground hover:text-foreground hover:bg-secondary flex items-center rounded-md px-2.5 py-2 transition-colors pl-10" href="${escapeHtml(href)}">
          <span class="font-medium truncate">${escapeHtml(projectName)}</span>
        </a>
      `;
    }).join('');
  }catch(e){
    host.innerHTML = `<div class="text-red-600 text-xs px-2.5 py-1">Services: ${escapeHtml(e && e.message ? e.message : String(e))}</div>`;
  }

  function buildNonJsonError(status, path, raw){
    const compact = String(raw || '').replace(/\s+/g, ' ').trim();

    if(/failed opening required/i.test(compact) || /failed to open stream/i.test(compact)) {
      return `API projets indisponible (${status}). Vérifie la configuration serveur de ${path}.`;
    }

    if(/<\/?(html|body|br|b)\b/i.test(compact)) {
      return `API projets indisponible (${status}). Le serveur a renvoyé une page HTML au lieu de JSON.`;
    }

    return `Réponse API invalide (${status}) sur ${path}.`;
  }

  function escapeHtml(s){
    return String(s)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }
})();
