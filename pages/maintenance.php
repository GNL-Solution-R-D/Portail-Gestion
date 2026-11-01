<?php
// Page de maintenance planifiée. Gabarit : include/status_page.php
require_once __DIR__ . '/../include/status_page.php';
header('Retry-After: 3600');
gnl_status_page(503, t('Maintenance'), t('Mise à niveau du système en cours'),
    t('Notre équipe effectue actuellement une maintenance planifiée. Vos services hébergés continuent de fonctionner ; seul l\'Espace Client est momentanément indisponible.'),
    [[t('État des services'), 'https://gnl-solution.com/stats', true]]);
