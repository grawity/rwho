<?php
namespace RWho;
?>
<?php if ($data !== false) { ?>
<!-- user session table -->

<table id="rwho-sessions">
<thead>
<tr>
	<th style="min-width: 15ex">user</th>
	<?php if ($detailed) { ?>
	<th style="min-width: 5ex">uid</th>
	<?php } ?>
	<th style="min-width: 10ex">host</th>
	<th style="min-width: 8ex">line</th>
	<th style="min-width: 30ex">addess</th>
</tr>
</thead>

<tfoot>
<tr>
	<td colspan="<?= $detailed ? 5 : 4 ?>">
<?php if (strlen($user) or strlen($host)) { ?>
		<a href="?">Back to all sessions</a>
<?php } elseif ($detailed) { ?>
		<a href="?">Back to normal view</a>
<?php } else { ?>
		<a href="?full">Expanded view</a>
<?php } ?>
		or output as
		<a href="?<?= htmlspecialchars($json_url) ?>">JSON</a>,
		<a href="?<?= htmlspecialchars($xml_url) ?>">XML</a>,
<?php if (!empty($finger_url)) { ?>
		<a href="<?= htmlspecialchars($finger_url) ?>">text</a>,
<?php } ?>
		or
		<a href="hosts.php">list hosts</a>
	</td>
</tr>
</tfoot>

<?php output_html($data, $plan); ?>
</table>

<?php } else { // data === false ?>
<p>Could not retrieve <code>rwho</code> information.</p>
<?php }; ?>
