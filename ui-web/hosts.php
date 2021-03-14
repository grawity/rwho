<?php
namespace RWho;
require_once(__DIR__."/application.inc.php");
require_once(__DIR__."/../lib-php/util.php");

class HostListPage extends \RWho\Web\RWhoWebApp {
	function output_json($data) {
		$json = [
			"time" => time(),
			"hosts" => [],
		];
		foreach ($data as $row) {
			$json["hosts"][] = [
				"host" => $row["host"],
				"address" => $row["last_addr"],
				"users" => $row["users"],
				"entries" => $row["entries"],
				"updated" => $row["last_update"],
			];
		}

		header("Content-Type: text/plain; charset=utf-8");
		print json_encode($json);
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

	function handle_request() {
		$has_query = true;
		$format = @$_GET["fmt"] ?? "html";

		$data = $this->client->retrieve_hosts();
		foreach ($data as &$row) {
			$row["fqdn"] = $row["host"];
			$row["host"] = \RWho\Util\strip_domain($row["fqdn"]);
			$row["last_update_age"] = \RWho\Util\interval($row["last_update"]);
		}

		if ($format == "html") {
			$page_title = "Active hosts";
			$page_css = $this->config->get("web.stylesheet", null);
			$xhr_refresh = 3;
			$xhr_url = Web\mangle_query(["fmt" => "html-xhr"]);
			$xml_url = Web\mangle_query(["fmt" => "xml"]);
			$json_url = Web\mangle_query(["fmt" => "json"]);
			$finger_url = $this->make_finger_addr("*", null, false);

			require("html-header.inc.php");
			require("html-body-hosts.inc.php");
			require("html-footer.inc.php");
		}
		elseif ($format == "html-xhr") {
			require("html-body-hosttable.inc.php");
		}
		elseif ($format == "json") {
			$this->output_json($data);
		}
		elseif ($format == "xml") {
			$this->output_xml($data);
		}
		else {
			header("Content-Type: text/plain; charset=utf-8", true, 406);
			die("Unsupported output format.\n");
		}
	}
}

$app = new HostListPage();
$app->handle_request();
?>
