<?php
namespace RWho;
require_once(__DIR__."/application.inc.php");
require_once(__DIR__."/../lib-php/util.php");

function output_json($data, $user, $host, $detailed) {
	global $app;

	foreach ($data as &$row) {
		$row["stale"] = $row["is_stale"];
		$row["summary"] = $row["is_summary"];
		unset($row["rowid"]);
		unset($row["is_stale"]);
		unset($row["is_summary"]);
	}

	header("Content-Type: text/plain; charset=utf-8");
	print json_encode([
		"time" => time(),
		"query" => [
			"user" => $user,
			"host" => $host,
			"summary" => !$detailed,
		],
		"utmp" => $data,
	])."\n";
}

function output_xml($data, $user, $host, $detailed) {
	global $app;

	header("Content-Type: application/xml");

	$doc = new \DOMDocument("1.0", "utf-8");
	$doc->formatOutput = true;

	$root = $doc->appendChild($doc->createElement("rwho"));

	$root->appendChild($doc->createAttribute("time"))
		->appendChild($doc->createTextNode(date("c")));

	$query = $root->appendChild($doc->createElement("query"));

	if (strlen($host))
		$query->appendChild($doc->createElement("host"))
			->appendChild($doc->createTextNode($host));
	if (strlen($user))
		$query->appendChild($doc->createElement("user"))
			->appendChild($doc->createTextNode($user));
	if (!$detailed)
		$query->appendChild($doc->createElement("summary"))
			->appendChild($doc->createTextNode("true"));

	foreach ($data as $row) {
		$rowx = $root->appendChild($doc->createElement("row"));

		$date = date("c", $row["updated"]);
		$rowx->appendChild($doc->createAttribute("updated"))
			->appendChild($doc->createTextNode($date));

		if ($row["is_stale"])
			$rowx->appendChild($doc->createAttribute("stale"))
				->appendChild($doc->createTextNode("true"));

		if ($row["is_summary"])
			$rowx->appendChild($doc->createAttribute("summary"))
				->appendChild($doc->createTextNode("true"));

		unset($row["rowid"]);
		unset($row["updated"]);
		unset($row["is_summary"]);

		foreach ($row as $k => $v)
			$rowx->appendChild($doc->createElement($k))
				->appendChild($doc->createTextNode($v));
	}

	print $doc->saveXML();
}

function output_html($data, $plan, $user, $host, $detailed) {
	global $app;

	$columns = 4; /* user+host+line+address */
	if ($detailed)
		$columns += 1; /* uid */

	if (!count($data)) {
		print "<tr>\n";
		print "\t<td colspan=\"".$columns."\" class=\"comment\">"
			."Nobody is logged in."
			."</td>\n";
		print "</tr>\n";
		return;
	}

	$byuser = [];
	foreach ($data as $row)
		$byuser[$row["user"]][] = $row;

	//ksort($byuser);

	foreach ($byuser as $data) {
		foreach ($data as $k => $row) {
			$user = htmlspecialchars($row["user"]);
			$uid = intval($row["uid"]);
			$fqdn = htmlspecialchars($row["host"]);
			$host = Util\strip_domain($fqdn);
			$line = htmlspecialchars($row["line"]);
			$rhost = strlen($row["rhost"])
				? htmlspecialchars($row["rhost"])
				: "(local)";

			if ($row["is_stale"])
				print "<tr class=\"stale\">\n";
			else
				print "<tr>\n";

			$linkuser = !strlen($user);
			$linkhost = !strlen($host);

			if ($detailed) {
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

			if ($detailed)
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

	if (strlen($plan)) {
		print "<tr>\n";
		print "\t<td colspan=\"".$columns."\">";
		print "\t\t<pre class=\"plan\"><div class=\"plan-head\">~/.plan:</div><br><div class=\"plan-body\">".htmlspecialchars($plan)."</div></pre>\n";
		print "\t</td>\n";
		print "</tr>\n";
	}
}

class UserListPage extends \RWho\Web\RWhoWebApp {
	public $user;
	public $host;
	public $detailed;

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

		if ($format == "html") {
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
		elseif ($format == "html-xhr") {
			output_html($data, $plan, $user, $host, $detailed);
		}
		elseif ($format == "json") {
			output_json($data, $user, $host, $detailed);
		}
		elseif ($format == "xml") {
			output_xml($data, $user, $host, $detailed);
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
