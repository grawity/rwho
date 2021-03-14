<?php
namespace RWho;
require_once(__DIR__."/application.inc.php");
require_once(__DIR__."/../lib-php/util.php");

function group_by_user($data) {
	$grouped = [];
	foreach ($data as $row) {
		$row["fqdn"] = $row["host"];
		$row["host"] = \RWho\Util\strip_domain($row["fqdn"]);
		$grouped[$row["user"]][] = $row;
	}
	return $grouped;
}

class UserListPage extends \RWho\Web\RWhoWebApp {
	function output_json($data, $params) {
		extract($params);

		$json = [
			"time" => time(),
			"query" => [
				"user" => $user,
				"host" => $host,
				"summary" => !$detailed,
			],
			"utmp" => [],
		];
		foreach ($data as $row) {
			$json["utmp"][] = [
				"user" => $row["user"],
				"uid" => $row["uid"],
				"host" => $row["host"],
				"line" => $row["line"],
				"rhost" => $row["rhost"],
				"stale" => $row["is_stale"],
				"summary" => $row["is_summary"],
			];
		}

		header("Content-Type: text/plain; charset=utf-8");
		print json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	function output_xml($data, $params) {
		extract($params);

		$doc = new \DOMDocument("1.0", "utf-8");
		$doc->formatOutput = true;

		$root = $doc->appendChild($doc->createElement("rwho"));
		$root->appendChild($doc->createAttribute("time"))
			->appendChild($doc->createTextNode(date("c")));

		$el = $root->appendChild($doc->createElement("query"));
		if (strlen($host))
			$el->appendChild($doc->createElement("host"))
				->appendChild($doc->createTextNode($host));
		if (strlen($user))
			$el->appendChild($doc->createElement("user"))
				->appendChild($doc->createTextNode($user));
		if (!$detailed)
			$el->appendChild($doc->createElement("summary"))
				->appendChild($doc->createTextNode("true"));

		foreach ($data as $row) {
			$el = $root->appendChild($doc->createElement("row"));

			$date = date("c", $row["updated"]);
			$el->appendChild($doc->createAttribute("updated"))
				->appendChild($doc->createTextNode($date));

			if ($row["is_stale"])
				$el->appendChild($doc->createAttribute("stale"))
					->appendChild($doc->createTextNode("true"));

			if ($row["is_summary"])
				$el->appendChild($doc->createAttribute("summary"))
					->appendChild($doc->createTextNode("true"));

			foreach (["user", "uid", "host", "line", "rhost"] as $k)
				$el->appendChild($doc->createElement($k))
					->appendChild($doc->createTextNode($row[$k]));
		}

		header("Content-Type: application/xml; charset=utf-8");
		print $doc->saveXML();
	}

	function output_html_full($data, $params) {
		extract($params);

		$data_by_user = group_by_user($data);

		// XXX: Doesn't make sense to use <em> because this also goes in <title>!
		$page_title = strlen($user) ? "<em>".htmlspecialchars($user)."</em>" : "All users";
		$page_title .= " on ";
		$page_title .= strlen($host) ? "<em>".htmlspecialchars($host)."</em>" : "all servers";
		$page_css = $this->config->get("web.stylesheet", null);

		$xhr_refresh = 3;
		$xhr_url = Web\mangle_query(["fmt" => "html-xhr"]);
		$xml_url = Web\mangle_query(["fmt" => "xml"]);
		$json_url = Web\mangle_query(["fmt" => "json"]);
		$finger_url = $this->make_finger_addr($user, $host, $detailed);

		require("html-header.inc.php");
		require("html-body-users.inc.php");
		require("html-footer.inc.php");
	}

	function output_html_xhr($data, $params) {
		extract($params);

		$data_by_user = group_by_user($data);

		require("html-body-usertable.inc.php");
	}

	function handle_request() {
		$user = @$_GET["user"] ?? "";
		$host = @$_GET["host"] ?? "";
		$has_query = (strlen($user) || strlen($host));
		if (isset($_GET["full"]))
			$detailed = true;
		elseif (isset($_GET["summary"]))
			$detailed = false;
		else
			$detailed = (strlen($user) || strlen($host));
		$format = @$_GET["fmt"] ?? "html";

		$data = $this->client->retrieve($user, $host, $this->should_filter());
		if (!$detailed)
			$data = $this->client->summarize($data);

		$plan = null;
		if (strlen($user))
			$plan = $this->client->get_plan_file($user, $host);

		$params = compact("has_query", "user", "host", "detailed", "plan");

		if ($format == "html") {
			$this->output_html_full($data, $params);
		}
		elseif ($format == "html-xhr") {
			$this->output_html_xhr($data, $params);
		}
		elseif ($format == "json") {
			$this->output_json($data, $params);
		}
		elseif ($format == "xml") {
			$this->output_xml($data, $params);
		}
		else {
			header("Content-Type: text/plain; charset=utf-8", true, 406);
			die("Unsupported output format.\n");
		}
	}
}

$app = new UserListPage();
$app->handle_request();
?>
