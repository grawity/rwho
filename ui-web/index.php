<?php
namespace RWho;
error_reporting(E_ALL^E_NOTICE);

require __DIR__."/../lib-php/librwho.php";

class query {
	static $user;
	static $host;
	static $detailed;
	static $format;
}

class html {
	static $columns = 0;
	static $title;

	static function header($title, $width=0) {
		if ($width)
			echo "\t<th style=\"min-width: {$width}ex\">$title</th>\n";
		else
			echo "\t<th>$title</th>\n";

		self::$columns++;
	}
}

function H($str) { return htmlspecialchars($str); }

function make_finger_addr() {
	if (!Config::has("finger.host"))
		return null;
	$q = (string) query::$user;
	if (strlen(query::$host))
		$q .= "@".query::$host;
	$q .= "@".Config::get("finger.host");
	if (query::$detailed and !(strlen(query::$user) or strlen(query::$host)))
		$q = "/W ".$q;
	return "//nullroute.eu.org/finger/?q=".urlencode($q);
}

function output_json($data) {
	foreach ($data as &$row) {
		unset($row["rowid"]);
		if (is_stale($row["updated"])) {
			$row["stale"] = true;
		}
	}

	header("Content-Type: text/plain; charset=utf-8");
	print json_encode(array(
		"time"		=> time(),
		"query"		=> array(
			"user"		=> query::$user,
			"host"		=> query::$host,
			"summary"	=> !query::$detailed,
		),
		"maxage"	=> Config::get("expire"),
		"utmp"		=> $data,
	))."\n";
}

function output_xml($data) {
	header("Content-Type: application/xml");

	$doc = new \DOMDocument("1.0", "utf-8");
	$doc->formatOutput = true;

	$root = $doc->appendChild($doc->createElement("rwho"));

	$root->appendChild($doc->createAttribute("time"))
		->appendChild($doc->createTextNode(date("c")));

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

function output_html($data) {
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
				: "(local)";

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
	html::$title = "Users logged in";
	require "html-header.inc.php";
	require "html-body-users.inc.php";
	@include "html-footer.inc.php";
}
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
