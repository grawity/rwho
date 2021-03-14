<?php
namespace RWho\Web;
require_once(__DIR__."/application.inc.php");
require_once(__DIR__."/../lib-php/util.php");

class HostListPage extends RWhoWebApp {
	function output_json($data) {
		$json = [
			"time" => time(),
			"hosts" => [],
		];
		foreach ($data as $row) {
			$json["hosts"][] = [
				"host" => $row["fqdn"],
				"address" => $row["last_addr"],
				"users" => $row["users"],
				"entries" => $row["entries"],
				"updated" => $row["last_update"],
			];
		}

		header("Content-Type: text/plain; charset=utf-8");
		print json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	function output_xml($data) {
		$doc = new \DOMDocument("1.0", "utf-8");
		$doc->formatOutput = true;

		$root = $doc->appendChild($doc->createElement("rwho"));
		$root->appendChild($doc->createAttribute("time"))
			->appendChild($doc->createTextNode(date("c")));

		foreach ($data as $row) {
			$el = $root->appendChild($doc->createElement("host"));

			$el->appendChild($doc->createAttribute("name"))
				->appendChild($doc->createTextNode($row["fqdn"]));

			$el->appendChild($doc->createElement("address"))
				->appendChild($doc->createTextNode($row["last_addr"]));

			$el->appendChild($doc->createElement("users"))
				->appendChild($doc->createTextNode($row["users"]));

			$el->appendChild($doc->createElement("entries"))
				->appendChild($doc->createTextNode($row["entries"]));

			$date = date("c", $row["last_update"]);
			$el->appendChild($doc->createElement("updated"))
				->appendChild($doc->createTextNode($date));
		}

		header("Content-Type: application/xml; charset=utf-8");
		print $doc->saveXML();
	}

	function output_html_full($data, $params) {
		extract($params);

		$page_title = "Active hosts";
		$page_css = $this->config->get("web.stylesheet", null);
		$xhr_refresh = 3;
		$xhr_url = mangle_query(["fmt" => "html-xhr"]);
		$xml_url = mangle_query(["fmt" => "xml"]);
		$json_url = mangle_query(["fmt" => "json"]);
		$finger_url = $this->make_finger_addr("*", null, false);

		require("html-header.inc.php");
		require("html-body-hosts.inc.php");
		require("html-footer.inc.php");
	}

	function output_html_xhr($data, $params) {
		extract($params);

		require("html-body-hosts.inc.php");
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
		$params = compact("has_query");

		if ($format == "html") {
			$this->output_html_full($data, $params);
		} elseif ($format == "html-xhr") {
			$this->output_html_xhr($data, $params);
		} elseif ($format == "json") {
			$this->output_json($data);
		} elseif ($format == "xml") {
			$this->output_xml($data);
		} else {
			header("Content-Type: text/plain; charset=utf-8", true, 406);
			die("Unsupported output format.\n");
		}
	}
}

$app = new HostListPage();
$app->handle_request();
?>
