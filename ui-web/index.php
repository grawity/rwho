<?php
namespace RWho;
require_once("util.inc.php");
require_once(__DIR__."/application.inc.php");
require_once(__DIR__."/../lib-php/util.php");

class query {
	static $user;
	static $host;
	static $detailed;
}

function output_json($data) {
	global $app;

	foreach ($data as &$row) {
		unset($row["rowid"]);
		if ($app->client->is_stale($row["updated"])) {
			$row["stale"] = true;
		}
	}

	header("Content-Type: text/plain; charset=utf-8");
	print json_encode([
		"time" => time(),
		"query" => [
			"user" => query::$user,
			"host" => query::$host,
			"summary" => !query::$detailed,
		],
		"utmp" => $data,
	])."\n";
}

function output_xml($data) {
	global $app;

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

		$date = date("c", $row["updated"]);
		$rowx->appendChild($doc->createAttribute("updated"))
			->appendChild($doc->createTextNode($date));

		if ($app->client->is_stale($row["updated"]))
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

function output_html($data, $plan) {
	global $app;

	$columns = 4; /* user+host+line+address */
	if (query::$detailed)
		$columns += 1; /* uid */

	if (!count($data)) {
		print "<tr>\n";
		print "\t<td colspan=\"".$columns."\" class=\"comment\">"
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
			$host = Util\strip_domain($fqdn);
			$line = htmlspecialchars($row["line"]);
			$rhost = strlen($row["rhost"])
				? htmlspecialchars($row["rhost"])
				: "(local)";

			if ($app->client->is_stale($row["updated"]))
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

	if (strlen($plan)) {
		print "<tr>\n";
		print "\t<td colspan=\"".$columns."\">";
		print "\t\t<pre class=\"plan\"><div class=\"plan-head\">~/.plan:</div><br><div class=\"plan-body\">".H($plan)."</div></pre>\n";
		print "\t</td>\n";
		print "</tr>\n";
	}
}

function handle_users_request($app) {
	$user = @$_GET["user"] ?? "";
	$host = @$_GET["host"] ?? "";
	$has_query = (strlen($user) || strlen($host));
	if (@$_GET["full"])
		$detailed = true;
	elseif (@$_GET["summary"])
		$detailed = false;
	else
		$detailed = (strlen($user) || (strlen($host) && !is_wildcard($host)));
	$format = @$_GET["fmt"] ?? "html";

	/* TODO */
	query::$user = $user;
	query::$host = $host;
	query::$detailed = $detailed;

	$data = $app->client->retrieve($user, $host, $app->should_filter());
	if (!$detailed)
		$data = $app->client->summarize($data);

	$plan = null;
	if (strlen($user))
		$plan = $app->client->get_plan_file($user, $host);

	if ($format == "html") {
		$title = strlen($user) ? "<em>".htmlspecialchars($user)."</em>" : "All users";
		$title .= " on ";
		$title .= strlen($host) ? "<em>".htmlspecialchars($host)."</em>" : "all servers";

		html::$title = $title;
		html::$refresh = 3;
		require("html-header.inc.php");
		require("html-body-users.inc.php");
		require("html-footer.inc.php");
	}
	elseif ($format == "html-xhr") {
		output_html($data, $plan);
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

handle_users_request($app);
?>
