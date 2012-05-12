<?php
namespace RWho;
error_reporting(E_ALL^E_NOTICE);

require __DIR__."/../lib-php/librwho.php";

class query {
	static $format;
}

class html {
	static $columns = 0;

	static function header($title, $width=0) {
		if ($width)
			echo "\t<th style=\"min-width: {$width}ex\">$title</th>\n";
		else
			echo "\t<th>$title</th>\n";

		self::$columns++;
	}
}

function H($str) { return htmlspecialchars($str); }

function output_json($data) {
	$d = array();
	foreach ($data as $row) {
		$d[] = array(
			"host"		=> $row["host"],
			"address"	=> $row["last_addr"],
			"users"		=> $row["users"],
			"entries"	=> $row["entries"],
			"updated"	=> $row["last_update"],
		);
	}

	header("Content-Type: text/plain; charset=utf-8");
	print json_encode(array(
		"time"		=> time(),
		"maxage"	=> MAX_AGE,
		"hosts"		=> $d,
	))."\n";
}

function output_xml($data) {
	header("Content-Type: application/xml");

	$doc = new \DOMDocument("1.0", "utf-8");
	$doc->formatOutput = true;

	$root = $doc->appendChild($doc->createElement("rwho"));

	$root->appendChild($doc->createAttribute("time"))
		->appendChild($doc->createTextNode(date("c")));

	foreach ($data as $row) {
		$rowx = $root->appendChild($doc->createElement("host"));

		unset($row["hostid"]);

		$rowx->appendChild($doc->createAttribute("name"))
			->appendChild($doc->createTextNode($row["host"]));

		$rowx->appendChild($doc->createElement("address"))
			->appendChild($doc->createTextNode($row["last_addr"]));

		$rowx->appendChild($doc->createElement("users"))
			->appendChild($doc->createTextNode($row["users"]));

		$rowx->appendChild($doc->createElement("entries"))
			->appendChild($doc->createTextNode($row["entries"]));

		$date = date("c", $row["last_update"]);
		$rowx->appendChild($doc->createElement("updated"))
			->appendChild($doc->createTextNode($date));
	}

	print $doc->saveXML();
}

function output_html($data) {
	if (!count($data)) {
		print "<tr>\n";
		print "\t<td colspan=\"".html::$columns."\" class=\"comment\">"
			."No active hosts."
			."</td>\n";
		print "</tr>\n";
		return;
	}

	foreach ($data as $k => $row) {
		$fqdn = htmlspecialchars($row["host"]);
		$host = strip_domain($fqdn);

		print "<tr>\n";

		print "\t<td>"
			."<a href=\"./?host=$fqdn\" title=\"$fqdn\">$host</a>"
			."</td>\n";

		print "\t<td>"
			.$fqdn
			."</td>\n";

		print "\t<td>"
			.$row["users"]
			."</td>\n";

		print "\t<td>"
			.$row["entries"]
			."</td>\n";

		print "\t<td>"
			.interval($row["last_update"])
			."</td>\n";

		print "</tr>\n";
	}
}

query::$format = isset($_GET["fmt"]) ? $_GET["fmt"] : "html";

$data = retrieve_hosts();

if (query::$format == "html") {
?>
<!DOCTYPE html>
<head>
	<title>Active hosts</title>
	<meta charset="utf-8">
	<noscript>
		<meta http-equiv="Refresh" content="10">
	</noscript>
	<meta name="robots" content="noindex, nofollow">
	<link rel="stylesheet" href="rwho.css">
</head>

<h1>Active hosts</h1>

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
	<td colspan="<?php echo html::$columns ?>">
		<a href="./">Back to all sessions</a>
		or output as
		<a href="?<?php echo H(mangle_query(array("fmt" => "json"))) ?>">JSON</a>,
		<a href="?<?php echo H(mangle_query(array("fmt" => "xml"))) ?>">XML</a>
	</td>
</tr>
</tfoot>

<?php output_html($data); ?>
</table>

<p>Hosts idle longer than <?= MAX_AGE ?> seconds are not shown.</p>

<script type="text/javascript">
var settings = {
	page: "host",
	interval: 5,
	args: "<?= addslashes(mangle_query(array("fmt" => "json"))) ?>",
	html_columns: <?= html::$columns ?>,
};
</script>
<script type="text/javascript" src="xhr.js"></script>

<?php @include "footer.html"; ?>

<?php
} // query::$format == "html"
elseif (query::$format == "json") {
	output_json($data);
}
elseif (query::$format == "xml") {
	output_xml($data);
}
elseif (query::$format == "html-xhr") {
	output_html($data);
}
else {
	header("Content-Type: text/plain; charset=utf-8", true, 406);
	print "Unsupported output format.\n";
}
?>
