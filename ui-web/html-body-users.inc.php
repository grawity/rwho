<?php
$link_user = !strlen($user);
$link_host = !strlen($host);
$columns = $detailed ? 5 : 4;
?>
<div id="rwho-table-wrapper">
<table id="rwho-sessions">
	<thead>
	<tr>
		<th style="min-width: 15ex">User</th>
<?php if ($detailed) { ?>
		<th style="min-width: 5ex">ID</th>
<?php } ?>
		<th style="min-width: 10ex">Host</th>
		<th style="min-width: 8ex">Line</th>
		<th style="min-width: 30ex">Address</th>
	</tr>
	</thead>

	<tfoot>
	<tr>
		<td colspan="<?= $columns ?>">
<?php if ($detailed) { ?>
			<a href="<?= htmlspecialchars($normal_url) ?>">Normal view</a>
<?php } else { ?>
			<a href="<?= htmlspecialchars($expand_url) ?>">Expanded view</a>
<?php } ?>
			or output as
			<a href="<?= htmlspecialchars($json_url) ?>">JSON</a>,
			<a href="<?= htmlspecialchars($xml_url) ?>">XML</a>,
<?php if (!empty($finger_url)) { ?>
			<a href="<?= htmlspecialchars($finger_url) ?>">text</a>,
<?php } ?>
<?php if (strlen($user) || strlen($host)) { ?>
			or <a href="?">back to all sessions</a>,
<?php } ?>
			or
			<a href="hosts">list hosts</a>
		</td>
	</tr>
	</tfoot>

<?php foreach ($data_by_user as $userdata) { ?>
<?php foreach ($userdata as $k => $row) { ?>
<?php
$h_user = htmlspecialchars($row["user"]);
$h_uid = htmlspecialchars($row["uid"]);
$h_fqdn = htmlspecialchars($row["fqdn"]);
$h_host = htmlspecialchars($row["host"]);
$h_line = htmlspecialchars($row["line"]);
$h_rhost = strlen($row["rhost"]) ? htmlspecialchars($row["rhost"]) : "(local)";
?>
	<tr<?= $row["is_stale"] ? " class=\"stale\"" : "" ?>>
<?php if ($detailed || $k == 0) { ?>
		<td rowspan="<?= $detailed ? 1 : count($userdata) ?>">
<?php if ($link_user) { ?>
			<a href="?user=<?= $h_user ?>"><?= $h_user ?></a>
<?php } else { ?>
			<?= $h_user ?>

<?php } ?>
		</td>
<?php } ?>
<?php if ($detailed) { ?>
		<td><?= $h_uid ?></td>
<?php } ?>
		<td>
<?php if ($link_host) { ?>
			<a href="?host=<?= $h_fqdn ?>" title="<?= $h_fqdn ?>"><?= $h_host ?></a>
<?php } else { ?>
			<?= $h_host ?>

<?php } ?>
		</td>
		<td><?= $row["is_summary"] ? "($h_line ttys)" : $h_line ?></td>
		<td><?= $h_rhost ?></td>
	</tr>
<?php } ?>
<?php } ?>

<?php if (strlen($plan)) { ?>
	<tr>
		<td colspan="<?= $columns ?>">
			<pre class="plan"><div class="plan-head">~/.plan:</div></pre>
			<pre class="plan"><div class="plan-body"><?= htmlspecialchars($plan) ?></div></pre>
		</td>
	</tr>
<?php } ?>

<?php if (!$data_by_user) { ?>
	<tr>
		<td colspan="<?= $columns ?>" class="comment">Nobody is logged in.</td>
	</tr>
<?php } ?>
</table>
</div>
