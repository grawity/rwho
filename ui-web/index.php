<?php
namespace RWho;
error_reporting(E_ALL^E_NOTICE);

require __DIR__."/../lib-php/librwho.php";
require "util.inc.php";

class query {
	static $user;
	static $host;
	static $detailed;
	static $format;
}

function is_wildcard($str) {
	return strlen($str) && (strpos($str, "%") !== false);
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

	$query = $root->appendChild($doc->createElement("query"));

	if (strlen(query::$host))
		$query->appendChild($doc->createElement("host"))
			->appendChild($doc->createTextNode(query::$host));
	if (strlen(query::$user))
		$query->appendChild($doc->createElement("user"))
			->appendChild($doc->createTextNode(query::$user));
	if (!query::$detailed)
		$query->appendChild($doc->createElement("summary"))
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

			$linkuser = !strlen(query::$user);
			$linkhost = !strlen(query::$host) || is_wildcard(query::$host);

			if (query::$detailed) {
				print "\t<td>"
					.($linkuser
						? "<a href=\"?user=$user\">$user</a>"
						: $user)
					."</td>\n";
			} else {
				if ($k == 0)
					print "\t<td rowspan=\"".count($data)."\">"
						.($linkuser
							? "<a href=\"?user=$user\">$user</a>"
							: $user)
						."</td>\n";
			}

			if (query::$detailed)
				print "\t<td>$uid</td>\n";

			print "\t<td>"
				.($linkhost
					? "<a href=\"?host=$fqdn\" title=\"$fqdn\">$host</a>"
					: $host)
				."</td>\n";
			print "\t<td>"
				.($row["is_summary"] ? "($line ttys)" : $line)
				."</td>\n";
			print "\t<td>$rhost</td>\n";

			print "</tr>\n";
		}
	}
}

function should_filter() {
	$anon = true;
	$rhost = get_rhost();
	foreach (Config::getlist("privacy.allow_addr") as $addr)
		if (ip_cidr($rhost, $addr)) {
			$anon = false;
			break;
		}

	if ($anon && Config::getbool("privacy.hide_rhost"))
		return true;
	return false;
}

query::$user = $_GET["user"];
query::$host = $_GET["host"];
query::$detailed = (strlen(query::$user)
			|| (strlen(query::$host) && !is_wildcard(query::$host))
			|| isset($_GET["full"]))
		&& !isset($_GET["summary"]);
query::$format = isset($_GET["fmt"]) ? $_GET["fmt"] : "html";

$data = retrieve(query::$user, query::$host, should_filter());

if (!query::$detailed)
	$data = summarize($data);

if (query::$format == "html") {
	html::$title = "Users logged in";
	html::$refresh = 3;
	require "html-header.inc.php";
	require "html-body-users.inc.php";
	@include "html-footer.inc.php";
}
elseif (query::$format == "html-xhr") {
	output_html($data);
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
