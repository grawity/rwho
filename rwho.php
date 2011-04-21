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

function build_query($items) {
	$query = array();
	foreach ($items as $key => $value) {
		if ($value === null or !strlen($value))
			$query[] = urlencode($key);
		else
			$query[] = urlencode($key)."=".urlencode($value);
	}
	return implode("&", $query);
}

function mangle_query($add, $remove=null) {
	parse_str($_SERVER["QUERY_STRING"], $query);

	if ($add !== null)
		foreach ($add as $key => $value)
			$query[$key] = $value;

	if ($remove !== null)
		foreach ($remove as $key)
			unset($query[$key]);

	return build_query($query);
}


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

		unset($row["rowid"]);

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
		print "\t<td colspan=\"".html::$columns."\" class=\"comment\">"
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
			$user = htmlspecialchars($row["user"]);
			$uid = intval($row["uid"]);
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

			if (query::$detailed) {
				print "\t<td>"
					.(strlen(query::$user) ? $user
						: "<a href=\"?user=$user\">$user</a>")
					."</td>\n";
				print "\t<td>$uid</td>\n";
			} else {
				if ($k == 0)
					print "\t<td rowspan=\"".count($data)."\">"
						.(strlen(query::$user) ? $user
							: "<a href=\"?user=$user\">$user</a>")
						."</td>\n";
			}

			print "\t<td>"
				.(strlen(query::$host) ? $host
					: "<a href=\"?host=$fqdn\" title=\"$fqdn\">$host</a>")
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
	<noscript>
		<meta http-equiv="Refresh" content="10">
	</noscript>
	<meta name="robots" content="noindex, nofollow">
	<link rel="stylesheet" href="rwho.css">

	<script type="text/javascript">
	var update_interval = 1500;
	var json_args = "<?php echo addslashes(mangle_query(array("fmt" => "json"))) ?>";
	var html_columns = <?php echo html::$columns ?>;
	</script>
	<script type="text/javascript" src="rwho-xhr.js"></script>
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
<?php
html::header("user", 15);
if (query::$detailed)
	html::header("uid", 5);
html::header("host", 10);
html::header("line", 8);
html::header("address", 40);
?>
</thead>

<tfoot>
	<td colspan="<?php echo html::$columns ?>">
<?php if (strlen(query::$user) or strlen(query::$host)): ?>
		<a href="?">Back to all sessions</a>
<?php elseif (query::$detailed): ?>
		<a href="?">Back to normal view</a>
<?php else: ?>
		<a href="?full">Expanded view</a>
<?php endif; ?>
		or output as
		<a href="?<?php echo H(mangle_query(array("fmt" => "json"))) ?>">JSON</a>,
		<a href="?<?php echo H(mangle_query(array("fmt" => "xml"))) ?>">XML</a>
	</td>
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

<?php
} // query::$format == "html"
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
