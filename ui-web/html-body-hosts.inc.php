<?php namespace RWho; ?>
<h1><?= h(html::$title) ?></h1>

<table id="sessions">
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
		<a href="?<?= H(mangle_query(array("fmt" => "json"))) ?>">JSON</a>,
		<a href="?<?= H(mangle_query(array("fmt" => "xml"))) ?>">XML</a>
	</td>
</tr>
</tfoot>

<?php output_html($data); ?>
</table>

<p>Hosts idle longer than <?= Config::get("expire") ?> seconds are not shown.</p>
