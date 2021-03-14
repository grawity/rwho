<?php
$link_user = !strlen($user);
$link_host = !strlen($host);
$columns = $detailed ? 5 : 4;
?>
<?php if ($data_by_user) { ?>
	<?php foreach ($data_by_user as $userdata) { ?>
		<?php foreach ($userdata as $k => $row) { ?>
			<?php
			$h_user = htmlspecialchars($row["user"]);
			$h_uid = htmlspecialchars($row["uid"]);
			$h_fqdn = htmlspecialchars($row["fqdn"]);
			$h_host = htmlspecialchars($row["host"]);
			$h_line = htmlspecialchars($row["line"]);
			$h_rhost = strlen($row["rhost"])
				? htmlspecialchars($row["rhost"])
				: "(local)";
			?>

			<tr class="<?= $row["is_stale"] ? "stale" : "" ?>">
				<!-- User -->
				<?php if ($detailed) { ?>
				<td>
					<?php if ($link_user) { ?>
					<a href="?user=<?= $h_user ?>"><?= $h_user ?></a>
					<?php } else { ?>
					<?= $h_user ?>
					<?php } ?>
				</td>
				<?php } elseif ($k == 0) { ?>
				<td rowspan="<?= count($userdata) ?>">
					<?php if ($link_user) { ?>
					<a href="?user=<?= $h_user ?>"><?= $h_user ?></a>
					<?php } else { ?>
					<?= $h_user ?>
					<?php } ?>
				</td>
				<?php } ?>
				<!-- UID -->
				<?php if ($detailed) { ?>
				<td><?= $h_uid ?></td>
				<?php } ?>
				<!-- Host -->
				<td>
					<?php if ($link_host) { ?>
					<a href="?host=<?= $h_fqdn ?>" title="fqdn"><?= $h_host ?></a>
					<?php } else { ?>
					<?= $h_host ?>
					<?php } ?>
				</td>
				<!-- Line -->
				<td><?= $row["is_summary"] ? "($h_line ttys)" : $h_line ?></td>
				<!-- Remote host -->
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
<?php } else { ?>
	<tr>
		<td colspan="<?= $columns ?>" class="comment">Nobody is logged in.</td>
	</tr>
<?php } ?>
