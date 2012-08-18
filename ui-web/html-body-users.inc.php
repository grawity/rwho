<?php namespace RWho; ?>
<?php if ($data !== false) { ?>
<!-- user session table -->

<h1><?php
echo strlen(query::$user)
	? "<em>".H(query::$user)."</em>"
	: "All users";
echo " on ";
echo strlen(query::$host)
	? "<em>".H(query::$host)."</em>"
	: "all servers";
?></h1>

<table id="rwho-sessions">
<thead>
<tr>
<?php
html::header("user", 15);
if (query::$detailed)
	html::header("uid", 5);
html::header("host", 10);
html::header("line", 8);
html::header("address", 40);
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
		<a href="?<?= H(mangle_query(array("fmt" => "json"))) ?>">JSON</a>,
		<a href="?<?= H(mangle_query(array("fmt" => "xml"))) ?>">XML</a>,
<?php if (Config::has("finger.host")) { ?>
		<a href="<?= H(make_finger_addr()) ?>">text</a>,
<?php } ?>
		or
		<a href="hosts.php">list hosts</a>
	</td>
</tr>
</tfoot>

<?php output_html($data); ?>
</table>

<?php if (strlen(query::$user) and user_is_global(query::$user)) { ?>
<p><a href="http://search.cluenet.org/?q=<?= H(query::$user) ?>">See <?= H(query::$user) ?>'s Cluenet profile.</a></p>
<?php } ?>

<?php } else { // data === false ?>
<p>Could not retrieve <code>rwho</code> information.</p>
<?php }; ?>
