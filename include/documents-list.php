<?php if (empty($sections)): ?>
      <p>Aucun document pour l'instant.</p>
    <?php else: ?>
      <?php foreach ($sections as $sectionName => $docs): ?>
        <div class="section">
          <div class="section-title"><?php echo htmlspecialchars($sectionName); ?></div>
          <table class="document-table">
<thead>
  <tr>
    <th class="col-nom">Nom</th>
    <th class="col-taille">Taille</th>
    <th class="col-download">Action</th>
  </tr>
</thead>
<tbody>
  <?php foreach ($docs as $doc): ?>
    <tr>
      <td class="col-nom"><?php echo htmlspecialchars($doc['name']); ?></td>
      <td class="col-taille"><?php echo htmlspecialchars($doc['size']); ?></td>
      <td class="col-download"><a href="<?php echo htmlspecialchars($doc['path']); ?>">Telecharger</a></td>
    </tr>
  <?php endforeach; ?>
</tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

