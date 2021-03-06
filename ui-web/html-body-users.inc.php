<?php
namespace RWho;

$finger_url = query::make_finger_addr();
?>
<?php if ($data !== false) { ?>
<!-- user session table -->

<table id="rwho-sessions">
<thead>
<tr>
<?php
html::header("user", 15);
if (query::$detailed)
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
<?php if (strlen(query::$user) or strlen(query::$host)) { ?>
		<a href="?">Back to all sessions</a>
<?php } elseif (query::$detailed) { ?>
		<a href="?">Back to normal view</a>
<?php } else { ?>
		<a href="?full">Expanded view</a>
<?php } ?>
		or output as
		<a href="?<?= H(mangle_query(["fmt" => "json"])) ?>">JSON</a>,
		<a href="?<?= H(mangle_query(["fmt" => "xml"])) ?>">XML</a>,
<?php if (!empty($finger_url)) { ?>
		<a href="<?= H($finger_url) ?>">text</a>,
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
