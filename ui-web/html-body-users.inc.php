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
			<a href="<?= htmlspecialchars($normal_url) ?>">Show summary</a>
<?php } else { ?>
			<a href="<?= htmlspecialchars($expand_url) ?>">Show details</a>
<?php } ?>
<?php if (strlen($user) || strlen($host)) { ?>
			or back to <a href="?">all sessions</a>,
<?php } ?>
			or view the <a href="hosts">host list</a>
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
		<td<?= $detailed ? "" : " rowspan=\"".count($userdata)."\"" ?>>
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
		<th colspan="<?= $columns ?>">
			~<?= htmlspecialchars($user) ?>/.plan:
		</th>
	</tr>
	<tr>
		<td colspan="<?= $columns ?>">
			<pre class="plan"><?= htmlspecialchars($plan) ?></pre>
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
