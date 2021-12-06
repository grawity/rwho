<div id="rwho-table-wrapper">
<table id="rwho-sessions">
	<thead>
	<tr>
		<th style="min-width: 9ex">Host</th>
		<th style="min-width: 20ex">Domain</th>
		<th style="min-width: 7ex">Users</th>
		<th style="min-width: 7ex">Lines</th>
		<th style="min-width: 7ex">Updated</th>
	</tr>
	</thead>

	<tfoot>
	<tr>
		<td colspan="5">
			Back to <a href="./">all sessions</a>
		</td>
	</tr>
	</tfoot>

<?php foreach ($data as $row) { ?>
	<tr<?= $row["is_stale"] ? " class=\"stale\"" : "" ?>>
		<td><a href="./?host=<?= htmlspecialchars($row["fqdn"]) ?>"><?= htmlspecialchars($row["host"]) ?></a></td>
		<td><?= htmlspecialchars($row["fqdn"]) ?></td>
		<td><?= $row["users"] ? htmlspecialchars($row["users"]) : "" ?></td>
		<td><?= $row["entries"] ? htmlspecialchars($row["entries"]) : "" ?></td>
		<td><?= htmlspecialchars($row["last_update_age"]) ?></td>
	</tr>
<?php } ?>

<?php if (!$data) { ?>
	<tr>
		<td colspan="5" class="comment">No active hosts.</td>
	</tr>
<?php } ?>
</table>
</div>
