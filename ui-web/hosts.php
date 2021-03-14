<?php
namespace RWho;
require_once("util.inc.php");
require_once(__DIR__."/application.inc.php");
require_once(__DIR__."/../lib-php/util.php");

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
	global $app;

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
		$host = Util\strip_domain($fqdn);

		if ($row["is_stale"])
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
			.\RWho\Util\interval($row["last_update"])
			."</td>\n";

		print "</tr>\n";
	}
}

function handle_hosts_request($app) {
	$has_query = true;
	$format = @$_GET["fmt"] ?? "html";

	$data = $app->client->retrieve_hosts();

	if ($format == "html") {
		$page_title = "Active hosts";
		html::$refresh = 5;
		require("html-header.inc.php");
		require("html-body-hosts.inc.php");
		require("html-footer.inc.php");
	}
	elseif ($format == "html-xhr") {
		output_html($data);
	}
	elseif ($format == "json") {
		output_json($data);
	}
	elseif ($format == "xml") {
		output_xml($data);
	}
	else {
		header("Content-Type: text/plain; charset=utf-8", true, 406);
		die("Unsupported output format.\n");
	}
}

$app = new \RWho\Web\RWhoWebApp();

handle_hosts_request($app);
?>
