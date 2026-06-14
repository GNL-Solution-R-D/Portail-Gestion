<?php

declare(strict_types=1);


require_once '../include/session_bootstrap.php';
require_once '../include/lang.php';

if (!isset($_SESSION['user'])) {
    header('Location: /connexion');
    exit;
}

require_once '../config_loader.php';
require_once '../include/account_sessions.php';

if (accountSessionsIsCurrentSessionRevoked($pdo, (int) $_SESSION['user']['id'])) {
    accountSessionsDestroyPhpSession();
    header('Location: /connexion?error=' . urlencode(t('Cette session a été déconnectée depuis vos paramètres.')));
    exit;
}

accountSessionsTouchCurrent($pdo, (int) $_SESSION['user']['id']);

$namespace = $_SESSION['user']['k8s_namespace']
    ?? $_SESSION['user']['k8sNamespace']
    ?? $_SESSION['user']['namespace_k8s']
    ?? $_SESSION['user']['k8s_ns']
    ?? $_SESSION['user']['namespace']
    ?? '';

$deployment = $_GET['deployment'] ?? '';
$pod = $_GET['pod'] ?? '';
$container = $_GET['container'] ?? '';

if (!is_string($deployment)) $deployment = '';
if (!is_string($pod)) $pod = '';
if (!is_string($container)) $container = '';

?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= t('Logs') ?><?= $deployment ? ' - ' . htmlspecialchars($deployment) : '' ?></title>
  <link rel="stylesheet" href="../assets/styles/connexion-style.css" />
  <style>
    .wrap{max-width:1200px;margin:0 auto;padding:24px;}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;}
  </style>
</head>
<body class="bg-surface text-foreground">
  <?php if (file_exists('../include/header.php')) include('../include/header.php'); ?>

  <div class="wrap">
    <div class="mb-6">
      <div class="flex flex-wrap items-center gap-3 justify-between">
        <div>
          <a class="text-muted-foreground hover:text-foreground" href="<?= $deployment ? '/deployment?deployment=' . urlencode($deployment) : '/dashboard' ?>">← Retour</a>
          <h1 class="text-2xl font-bold mt-3"><?= t('Logs') ?></h1>
          <p class="text-muted-foreground"><?= t('Namespace:') ?> <span class="mono"><?= htmlspecialchars((string)$namespace) ?></span></p>
        </div>
      </div>
    </div>

    <div class="bg-background rounded-xl border p-6">
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[240px]">
          <label class="text-sm font-medium"><?= t('Déploiement') ?></label>
          <input id="deploymentInput" class="mt-1 w-full border rounded-md px-3 py-2 bg-background" value="<?= htmlspecialchars($deployment) ?>" placeholder="ex: slapia-web" />
          <p class="text-xs text-muted-foreground mt-1"><?= t('Utilisé pour lister les pods et éviter de parcourir tout le namespace.') ?></p>
        </div>

        <div class="flex-1 min-w-[220px]">
          <label class="text-sm font-medium"><?= t('Pod') ?></label>
          <select id="podSelect" class="mt-1 w-full border rounded-md px-3 py-2 bg-background">
            <option value=""><?= t('Chargement…') ?></option>
          </select>
        </div>

        <div class="flex-1 min-w-[220px]">
          <label class="text-sm font-medium"><?= t('Container') ?></label>
          <select id="containerSelect" class="mt-1 w-full border rounded-md px-3 py-2 bg-background">
            <option value="">—</option>
          </select>
        </div>

        <div class="min-w-[160px]">
          <label class="text-sm font-medium"><?= t('Lignes') ?></label>
          <select id="tailSelect" class="mt-1 w-full border rounded-md px-3 py-2 bg-background">
            <option value="200">200</option>
            <option value="500">500</option>
            <option value="1000">1000</option>
            <option value="2000">2000</option>
          </select>
        </div>

        <div class="flex items-center gap-3">
          <label class="inline-flex items-center gap-2 text-sm">
            <input id="timestampsChk" type="checkbox" class="accent-current" checked />
            <?= t('timestamps') ?>
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input id="followChk" type="checkbox" class="accent-current" />
            <?= t('follow (poll)') ?>
          </label>
        </div>

        <div class="flex items-center gap-2">
          <button id="refreshBtn" class="border rounded-md px-4 py-2 text-sm hover:bg-secondary"><?= t('Rafraîchir') ?></button>
          <button id="copyBtn" class="border rounded-md px-4 py-2 text-sm hover:bg-secondary"><?= t('Copier') ?></button>
          <button id="downloadBtn" class="border rounded-md px-4 py-2 text-sm hover:bg-secondary"><?= t('Télécharger') ?></button>
        </div>
      </div>

      <div id="statusMsg" class="text-sm text-muted-foreground mt-3"></div>

      <pre id="logPre" class="mono text-xs overflow-auto p-4 rounded-lg bg-muted mt-4" style="max-height: 70vh; white-space: pre;"><?= t('Sélectionne un pod…') ?></pre>
    </div>
  </div>

  <script>
    (function(){
      const deploymentInput = document.getElementById('deploymentInput');
      const podSelect = document.getElementById('podSelect');
      const containerSelect = document.getElementById('containerSelect');
      const tailSelect = document.getElementById('tailSelect');
      const timestampsChk = document.getElementById('timestampsChk');
      const followChk = document.getElementById('followChk');
      const refreshBtn = document.getElementById('refreshBtn');
      const copyBtn = document.getElementById('copyBtn');
      const downloadBtn = document.getElementById('downloadBtn');
      const statusMsg = document.getElementById('statusMsg');
      const logPre = document.getElementById('logPre');

      const presetPod = <?= json_encode($pod) ?>;
      const presetContainer = <?= json_encode($container) ?>;
      const presetDeployment = <?= json_encode($deployment) ?>;

      const escapeHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');

      const apiBase = new URL('../data/k8s_api.php', window.location.href);

      async function fetchJson(url){
        const res = await fetch(url.toString(), {credentials:'same-origin'});
        const raw = await res.text();
        let data = null;
        try { data = JSON.parse(raw); } catch(_) {}
        if(!data || typeof data !== 'object'){
          throw new Error(`Réponse non-JSON (${res.status}). URL: ${new URL(url).pathname}. ` + raw.slice(0,200).replace(/\s+/g,' '));
        }
        if(!res.ok || !data.ok){
          throw new Error(data.error || ('HTTP ' + res.status));
        }
        return data;
      }

      let podsCache = [];
      let followTimer = null;

      function setStatus(txt){
        statusMsg.textContent = txt;
      }

      function fillPods(pods){
        podsCache = Array.isArray(pods) ? pods : [];
        podSelect.innerHTML = '';
        if(!podsCache.length){
          podSelect.innerHTML = '<option value="">Aucun pod</option>';
          containerSelect.innerHTML = '<option value="">—</option>';
          return;
        }

        for(const p of podsCache){
          const opt = document.createElement('option');
          opt.value = p.name;
          opt.textContent = p.name;
          podSelect.appendChild(opt);
        }

        if(presetPod && podsCache.some(p => p.name === presetPod)){
          podSelect.value = presetPod;
        }

        fillContainers();
      }

      function fillContainers(){
        const podName = podSelect.value;
        const podObj = podsCache.find(p => p.name === podName);
        const containers = (podObj && Array.isArray(podObj.containers)) ? podObj.containers : [];
        containerSelect.innerHTML = '';
        if(!containers.length){
          containerSelect.innerHTML = '<option value="">(aucun)</option>';
          return;
        }
        for(const c of containers){
          const opt = document.createElement('option');
          opt.value = c.name;
          opt.textContent = c.name;
          containerSelect.appendChild(opt);
        }
        if(presetContainer && containers.some(c => c.name === presetContainer)){
          containerSelect.value = presetContainer;
        }
      }

      async function loadPods(){
        const dep = deploymentInput.value.trim();
        if(!dep){
          setStatus(<?= json_encode(t('Renseigne un déploiement pour lister les pods.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
          podSelect.innerHTML = '<option value="">—</option>';
          containerSelect.innerHTML = '<option value="">—</option>';
          return;
        }
        setStatus(<?= json_encode(t('Chargement des pods…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        const u = new URL(apiBase);
        u.searchParams.set('action','list_pods_for_deployment');
        u.searchParams.set('deployment', dep);
        const data = await fetchJson(u);
        fillPods(data.pods || []);
        setStatus(<?= json_encode(t('Pods chargés.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
      }

      async function loadLogs(){
        const podName = podSelect.value;
        const dep = deploymentInput.value.trim();
        if(!dep){
          logPre.textContent = <?= json_encode(t('Déploiement manquant.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          return;
        }
        if(!podName){
          logPre.textContent = <?= json_encode(t('Sélectionne un pod.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          return;
        }
        const cont = containerSelect.value || '';
        const tail = parseInt(tailSelect.value, 10) || 200;
        const u = new URL(apiBase);
        u.searchParams.set('action','pod_logs_tail');
        u.searchParams.set('pod', podName);
        if(cont) u.searchParams.set('container', cont);
        u.searchParams.set('tail', String(tail));
        u.searchParams.set('timestamps', timestampsChk.checked ? '1' : '0');

        setStatus(<?= json_encode(t('Chargement des logs…'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        const data = await fetchJson(u);
        logPre.textContent = data.text || '';
        setStatus('OK');

        // Keep URL in sync (nice for sharing)
        const newUrl = new URL(window.location.href);
        newUrl.searchParams.set('deployment', dep);
        newUrl.searchParams.set('pod', podName);
        if(cont) newUrl.searchParams.set('container', cont);
        else newUrl.searchParams.delete('container');
        history.replaceState({}, '', newUrl.pathname + '?' + newUrl.searchParams.toString());
      }

      function startFollow(){
        stopFollow();
        followTimer = setInterval(() => {
          loadLogs().catch(e => setStatus('Erreur: ' + (e && e.message ? e.message : String(e))));
        }, 2000);
      }

      function stopFollow(){
        if(followTimer){
          clearInterval(followTimer);
          followTimer = null;
        }
      }

      // Évite de laisser un timer tourner si l’utilisateur change de page.
      window.addEventListener('beforeunload', stopFollow);

      // Events
      deploymentInput.addEventListener('change', () => {
        loadPods().then(loadLogs).catch(e => setStatus('Erreur: ' + (e && e.message ? e.message : String(e))));
      });
      podSelect.addEventListener('change', () => {
        fillContainers();
        loadLogs().catch(e => setStatus('Erreur: ' + (e && e.message ? e.message : String(e))));
      });
      containerSelect.addEventListener('change', () => {
        loadLogs().catch(e => setStatus('Erreur: ' + (e && e.message ? e.message : String(e))));
      });
      refreshBtn.addEventListener('click', () => {
        loadLogs().catch(e => setStatus('Erreur: ' + (e && e.message ? e.message : String(e))));
      });
      followChk.addEventListener('change', () => {
        if(followChk.checked) startFollow();
        else stopFollow();
      });
      copyBtn.addEventListener('click', async () => {
        try{
          await navigator.clipboard.writeText(logPre.textContent || '');
          setStatus(<?= json_encode(t('Copié.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        }catch(e){
          setStatus('Impossible de copier: ' + (e && e.message ? e.message : String(e)));
        }
      });
      downloadBtn.addEventListener('click', () => {
        const dep = deploymentInput.value.trim() || 'deployment';
        const podName = podSelect.value || 'pod';
        const cont = containerSelect.value || 'container';
        const blob = new Blob([logPre.textContent || ''], {type: 'text/plain;charset=utf-8'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `${dep}_${podName}_${cont}.log`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
      });

      // Boot
      (async function(){
        try{
          if(presetDeployment) deploymentInput.value = presetDeployment;
          await loadPods();
          if(podSelect.value){
            await loadLogs();
          } else {
            logPre.textContent = <?= json_encode(t('Aucun pod.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          }
          if(followChk.checked) startFollow();
        } catch(e){
          setStatus('Erreur: ' + (e && e.message ? e.message : String(e)));
          logPre.textContent = (e && e.message) ? e.message : String(e);
        }
      })();
    })();
  </script>
</body>
</html>