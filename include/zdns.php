                <?php foreach ($dns_zones as $zone => $records) : ?>
                    <div class="containered"> 
                        <h3>Zone DNS : <?php echo htmlspecialchars($zone); ?></h3>
                        <table class="dns-zone">
                            <tr>
                                <th>Domaine</th>
                                <th>TTL</th>
                                <th>Type</th>
                                <th>Cible</th>
                            </tr>
                            <?php foreach ($records as $record) : ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($record['name']); ?></td>
                                    <td><?php echo htmlspecialchars($record['ttl']); ?></td>
                                    <td><?php echo htmlspecialchars($record['type']); ?></td>
                                    <td><?php echo htmlspecialchars($record['content']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endforeach; ?>
