<?php
namespace RWho;
?>
<?php if ($data !== false) { ?>
<!-- user session table -->

<table id="rwho-sessions">
<thead>
<tr>
<?php
html::header("user", 15);
if ($detailed)
	html::header("uid", 5);
html::header("host", 10);
html::header("line", 8);
html::header("address", 30);
?>
</tr>
</thead>

<tfoot>
<tr>
	<td colspan="<?= html::$columns ?>">
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
