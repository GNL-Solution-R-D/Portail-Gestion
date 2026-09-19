<?php
/* =====================================================================
   Racine du portail gestion -> page de connexion.
   ---------------------------------------------------------------------
   URL canonique : /connexion (sans extension, cf. .htaccess qui réécrit
   vers pages/connexion.php).

   NE JAMAIS pointer ici vers /connexion.php : si quelque chose sert cette
   URL en 302 on obtient une redirection vers elle-même. Les sondes
   Kubernetes suivent au maximum 10 redirections, échouent, et la liveness
   redémarre le conteneur en boucle (CrashLoopBackOff).
   ===================================================================== */

header('Location: /connexion', true, 302);
exit;
