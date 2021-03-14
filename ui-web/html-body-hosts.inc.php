<?php namespace RWho; ?>
<table id="rwho-sessions">
<thead>
<tr>
<?php
html::header("name", 9);
html::header("fqdn", 20);
html::header("users", 7);
html::header("lines", 7);
html::header("updated", 7);
?>
</tr>
</thead>

<tfoot>
<tr>
	<td colspan="<?= html::$columns ?>">
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
