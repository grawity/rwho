<?php namespace RWho; ?>
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
		<a href="?<?= htmlspecialchars($json_url) ?>">JSON</a>,
<?php if (!empty($finger_url)) { ?>
		<a href="?<?= htmlspecialchars($xml_url) ?>">XML</a>,
		<a href="<?= htmlspecialchars($finger_url) ?>">text</a>
<?php } else { ?>
		<a href="?<?= htmlspecialchars($xml_url) ?>">XML</a>
<?php } ?>
	</td>
</tr>
</tfoot>

<?php output_html($data); ?>
</table>
