<?php
define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

$user = $_GET["user"];
$host = $_GET["host"];
$full = !isset($_GET["summary"]);

$data = retrieve($user, $host);
if (!$full)
	$data = prep_summarize($data);

foreach ($data as &$row) {
	unset($row["rowid"]);
	//unset($row["is_summary"]);
}

header("Content-Type: text/plain; charset=utf-8");
print json_encode($data)."\n";
