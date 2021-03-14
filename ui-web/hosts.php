<?php
namespace RWho;
error_reporting(E_ALL^E_NOTICE);

require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/../lib-php/client.php");
require_once("util.inc.php");

class query {
	static $present;
	static $format;
}

function output_json($data) {
	$d = [];
	foreach ($data as $row) {
		$d[] = [
			"host" => $row["host"],
			"address" => $row["last_addr"],
			"users" => $row["users"],
			"entries" => $row["entries"],
			"updated" => $row["last_update"],
		];
	}

	header("Content-Type: text/plain; charset=utf-8");
	print json_encode([
		"time" => time(),
		"hosts" => $d,
	])."\n";
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

		if (is_stale($row["last_update"]))
			print "<tr class=\"stale\">\n";
		else
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

query::$present = true;
query::$format = isset($_GET["fmt"]) ? $_GET["fmt"] : "html";

$data = $client->retrieve_hosts();

if (query::$format == "html") {
	html::$title = "Active hosts";
	html::$refresh = 5;
	require("html-header.inc.php");
	require("html-body-hosts.inc.php");
	require("html-footer.inc.php");
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
