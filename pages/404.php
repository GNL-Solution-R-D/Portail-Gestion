<?php
// Page 404 (ErrorDocument dans .htaccess et routeur). Gabarit : include/status_page.php
require_once __DIR__ . '/../include/status_page.php';
gnl_status_page(404, t('Erreur 404'), t('Page introuvable'),
    t("La page demandée n'existe pas ou a été déplacée. Vérifiez l'adresse ou revenez au tableau de bord."),
    [[t('Retour au tableau de bord'), '/dashboard', true], [t('Contacter le support'), '/tickets', false]]);
