<div id="rwho-table-wrapper">
<table id="rwho-sessions">
	<thead>
	<tr>
		<th style="min-width: 9ex">host</th>
		<th style="min-width: 20ex">fqdn</th>
		<th style="min-width: 7ex">users</th>
		<th style="min-width: 7ex">lines</th>
		<th style="min-width: 7ex">updated</th>
	</tr>
	</thead>

	<tfoot>
	<tr>
		<td colspan="5">
			<a href="./">Back to all sessions</a>
			or output as
			<a href="<?= htmlspecialchars($json_url) ?>">JSON</a>,
<?php if (!empty($finger_url)) { ?>
			<a href="<?= htmlspecialchars($xml_url) ?>">XML</a>,
			<a href="<?= htmlspecialchars($finger_url) ?>">text</a>
<?php } else { ?>
			<a href="<?= htmlspecialchars($xml_url) ?>">XML</a>
<?php } ?>
		</td>
	</tr>
	</tfoot>

<?php foreach ($data as $row) { ?>
	<tr class="<?= $row["is_stale"] ? "stale" : "" ?>">
		<td><a href="?host=<?= htmlspecialchars($row["fqdn"]) ?>"><?= htmlspecialchars($row["host"]) ?></a></td>
		<td><?= htmlspecialchars($row["fqdn"]) ?></td>
		<td><?= htmlspecialchars($row["users"]) ?></td>
		<td><?= htmlspecialchars($row["entries"]) ?></td>
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
