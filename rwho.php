<?php
define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

class query {
	static $user;
	static $host;
	static $detailed;
}

function H($str) { return htmlspecialchars($str); }

function pretty_html($data) {
	$byuser = array();
	foreach ($data as $row)
		$byuser[$row["user"]][] = $row;

	//ksort($byuser);

	foreach ($byuser as $data) {
		foreach ($data as $k => $row) {
			$user = htmlspecialchars($row["user"]);
			$host = htmlspecialchars($row["host"]);
			$line = htmlspecialchars($row["line"]);
			$rhost = strlen($row["rhost"])
				? htmlspecialchars($row["rhost"])
				: "<i>(local)</i>";

			print "<tr>\n";
			if ($k == 0)
				print "\t<td rowspan=\"".count($data)."\">"
					.(strlen(query::$user) ? $user
						: "<a href=\"?user=$user\">$user</a>")
					."</td>\n";

			print "\t<td>"
				.(strlen(query::$host) ? $host
					: "<a href=\"?host=$host\">$host</a>")
				."</td>\n";
			print "\t<td>$line</td>\n";
			print "\t<td>$rhost</td>\n";

			print "</tr>\n";
		}
	}
}

query::$user = $_GET["user"];
query::$host = $_GET["host"];
query::$detailed = strlen(query::$user) || strlen(query::$host)
	|| isset($_GET["full"]);

$data = retrieve(query::$user, query::$host);

if (!query::$detailed)
	$data = prep_summarize($data);

?>
<!DOCTYPE html>
<head>
	<meta charset="utf-8">
	<title>Users logged in</title>
	<style>
	body {
		font-family: "Tahoma", sans-serif;
	}

	a {
		color: #44d;
		text-decoration: none;
	}

	table#sessions {
/*
		font-family: monospace;
*/
		border-collapse: collapse;
	}

	table#sessions thead,
	table#sessions tfoot {
		background: #eee;
	}

	table#sessions td,
	table#sessions th {
		border-width: 1px 0;
		border-style: solid;
		border-color: #aaa;
		padding: 3px;
		vertical-align: top;
	}
	</style>
</head>

<?php if ($data): ?>
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
	<th style="min-width: 15ex">user</th>
	<th style="min-width: 10ex">host</th>
	<th style="min-width: 8ex">line</th>
	<th style="min-width: 40ex">address</th>
</thead>

<?php if (strlen(query::$user) or strlen(query::$host)): ?>
<tfoot>
	<td colspan="4">
		<a href="?">Back to all sessions</a>
	</td>
</tfoot>
<?php endif; ?>

<?php pretty_html($data); ?>
</table>

<?php if (strlen(query::$user) and user_is_global(query::$user)): ?>
<p><a href="http://search.cluenet.org/?q=<?php echo H(query::$user) ?>">See <?php echo H(query::$user) ?>'s Cluenet profile.</a></p>
<?php endif; ?>

<?php else: ?>
<!-- error message -->

<p>Could not retrieve <code>rwho</code> information.</p>

<?php endif; ?>
