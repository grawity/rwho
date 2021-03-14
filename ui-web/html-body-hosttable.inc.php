<?php if ($data) { ?>
<?php foreach ($data as $row) { ?>
	<tr class="<?= $row["is_stale"] ? "stale" : "" ?>">
	</tr>
	<td><a href="?host=<?= htmlspecialchars($row["fqdn"]) ?>"><?= htmlspecialchars($row["host"]) ?></a></td>
	<td><?= htmlspecialchars($row["fqdn"]) ?></td>
	<td><?= htmlspecialchars($row["users"]) ?></td>
	<td><?= htmlspecialchars($row["entries"]) ?></td>
	<td><?= htmlspecialchars($row["last_update_age"]) ?></td>
<?php } ?>
<?php } else { ?>
	<tr>
		<td colspan="5" class="comment">No active hosts.</td>
	</tr>
<?php } ?>