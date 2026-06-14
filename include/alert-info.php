<?php
// Assurez-vous que $alert_type est défini, sinon utilisez 'info' par défaut.
if (!isset($alert_type)) {
    $alert_type = 'info';
}
?>
<div class="alert <?php echo htmlspecialchars($alert_type); ?> bar">
    <div class="alert-title">
        <?php 
        // Si vous souhaitez définir un titre personnalisé, initialisez $alert_title avant d'inclure ce fichier.
        echo isset($alert_title) ? htmlspecialchars($alert_title) : 'Information';
        ?>
    </div>
    <div class="alert-message">
        <?php echo isset($info) ? htmlspecialchars($info) : ''; ?>
    </div>
</div>
