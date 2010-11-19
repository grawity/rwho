<?php
namespace RWho;
error_reporting(E_ALL^E_NOTICE);

define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

class query {
	static $user;
	static $host;
	static $detailed;
	static $format;
}

function H($str) { return htmlspecialchars($str); }

function output_json($data) {
	foreach ($data as &$row)
		unset($row["rowid"]);

	header("Content-Type: text/plain; charset=utf-8");
	print json_encode(array(
		"query" => array(
			"user" => query::$user,
			"host" => query::$host,
			"summary" => !query::$detailed,
		),
		"utmp" => $data,
	))."\n";
}

function output_xml($data) {
	header("Content-Type: application/xml");

	$doc = new \DOMDocument("1.0", "utf-8");
	$doc->formatOutput = true;

	$root = $doc->appendChild($doc->createElement("rwho"));
	if (strlen(query::$user))
		$root->appendChild($doc->createAttribute("user"))
			->appendChild($doc->createTextNode(query::$user));
	if (strlen(query::$host))
		$root->appendChild($doc->createAttribute("host"))
			->appendChild($doc->createTextNode(query::$host));
	if (!query::$detailed)
		$root->appendChild($doc->createAttribute("summary"))
			->appendChild($doc->createTextNode("true"));

	foreach ($data as $row) {
		$rowx = $root->appendChild($doc->createElement("row"));

		$date = date("c", $row["updated"]);
		$rowx->appendChild($doc->createAttribute("updated"))
			->appendChild($doc->createTextNode($date));
		unset($row["updated"]);

		if ($row["is_summary"])
			$rowx->appendChild($doc->createAttribute("summary"))
				->appendChild($doc->createTextNode("true"));
		unset($row["is_summary"]);

		foreach ($row as $k => $v)
			$rowx->appendChild($doc->createElement($k))
				->appendChild($doc->createTextNode($v));
	}

	print $doc->saveXML();
}

function pretty_html($data) {
	if (!count($data)) {
		print "<tr>\n";
		print "\t<td colspan=\"4\" style=\"font-style: italic\">"
			."Nobody is logged in."
			."</td>\n";
		print "</tr>\n";
		return;
	}

	$byuser = array();
	foreach ($data as $row)
		$byuser[$row["user"]][] = $row;

	//ksort($byuser);

	foreach ($byuser as $data) {
		foreach ($data as $k => $row) {
			if (!query::$detailed and time()-$row["updated"] > 86400)
				continue;
			$user = htmlspecialchars($row["user"]);
			$fqdn = htmlspecialchars($row["host"]);
			$host = strip_domain($fqdn);
			$line = htmlspecialchars($row["line"]);
			$rhost = strlen($row["rhost"])
				? htmlspecialchars($row["rhost"])
				: "<i>(local)</i>";

			if (is_stale($row["updated"]))
				print "<tr class=\"stale\">\n";
			else
				print "<tr>\n";

			if ($k == 0)
				print "\t<td rowspan=\"".count($data)."\">"
					.(strlen(query::$user) ? $user
						: "<a href=\"?user=$user\">$user</a>")
					."</td>\n";

			print "\t<td>"
				.(strlen(query::$host) ? $host
					: "<a href=\"?host=$fqdn\">$host</a>")
				."</td>\n";
			print "\t<td>"
				.($row["is_summary"] ? "($line ttys)" : $line)
				."</td>\n";
			print "\t<td>$rhost</td>\n";

			print "</tr>\n";
		}
	}
}

query::$user = $_GET["user"];
query::$host = $_GET["host"];
query::$detailed = strlen(query::$user) || strlen(query::$host)
	|| isset($_GET["full"]);
query::$format = isset($_GET["fmt"]) ? $_GET["fmt"] : "html";

$data = retrieve(query::$user, query::$host);

if (!query::$detailed)
	$data = summarize($data);

if (query::$format == "html") {
?>
<!DOCTYPE html>
<head>
	<title>Users logged in</title>
	<meta charset="utf-8">
	<meta http-equiv="Refresh" content="10">
	<meta name="robots" content="noindex, nofollow">
	<link rel="stylesheet" href="rwho.css">
</head>

<?php if ($data !== false): ?>
<!-- user session table -->

<h1><?php
echo strlen(query::$user)
	? "<strong>".H(query::$user)."</strong>"
	: "All users";
echo " on ";
echo strlen(query::$host)
	? H(query::$host)
	: "all servers";
?></h1>

<table id="sessions">
<thead>
	<th style="min-width: 15ex">user</th>
	<th style="min-width: 10ex">host</th>
	<th style="min-width: 8ex">line</th>
	<th style="min-width: 40ex">address</th>
</thead>

<tfoot>
<?php if (strlen(query::$user) or strlen(query::$host)): ?>
	<td colspan="4">
		<a href="?">Back to all sessions</a>
	</td>
<?php elseif (!query::$detailed): ?>
	<td colspan="4">
		<a href="?full">Expanded view</a>
	</td>
<?php endif; ?>
</tfoot>

<?php pretty_html($data); ?>
</table>

<?php if (strlen(query::$user) and user_is_global(query::$user)) { ?>
<p><a href="http://search.cluenet.org/?q=<?php echo H(query::$user) ?>">See <?php echo H(query::$user) ?>'s Cluenet profile.</a></p>
<?php } ?>


<?php else: ?>
<!-- error message -->

<p>Could not retrieve <code>rwho</code> information.</p>

<?php endif; ?>

<p class="footer">
Refreshed on <?php echo strftime("%Y-%m-%d %H:%M:%S") ?>
</p>
<?php
}
elseif (query::$format == "json") {
	output_json($data);
}
elseif (query::$format == "xml") {
	output_xml($data);
}
else {
	header("Content-Type: text/plain; charset=utf-8", true, 406);
	print "Unsupported output format.\n";
}
?>
