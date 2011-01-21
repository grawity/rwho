#!/usr/bin/php
<?php
define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

function finger_handle_query($input) {
	list ($query, $detailed) = finger_parse($input);
	list ($q_user, $q_host) = RWho\parse_query($query);
	$data = RWho\retrieve($q_user, $q_host);
	if (!count($data))
		die("Nobody is logged in.\r\n");
	if (!$detailed)
		$data = RWho\summarize($data);
	output($data, $detailed);
}

function finger_parse($input) {
	$input = rtrim($input, "\r\n");
	if ($input === "/W" or substr($input, 0, 3) === "/W ") {
		$query = substr($input, 3);
		$detailed = true;
	} else {
		$query = $input;
		$detailed = false;
	}
	return array($query, $detailed);
}

function output($data, $detailed=false) {
	$fmt = $detailed
		? "%-12s %1s %-22s %-10s %s\r\n"
		: "%-12s %1s %-12s %-10s %s\r\n";
	printf($fmt, "USER", "", "HOST", "LINE", "FROM");

	$last = array("user" => null);
	foreach ($data as $row) {
		$flag = "";
		if (RWho\is_stale($row["updated"]))
			$flag = "?";
		elseif ($row["uid"] == 0)
			$flag = "#";
		elseif ($row["uid"] < 25000)
			$flag = "<";

		printf($fmt,
			$row["user"] !== $last["user"] ? $row["user"] : "",
			$flag,
			$detailed ? $row["host"] : RWho\strip_domain($row["host"]),
			$row["is_summary"] ? "{".$row["line"]."}" : $row["line"],
			strlen($row["rhost"]) ? $row["rhost"] : "-");
		$last = $row;
	}
}

if (isset($_SERVER["REQUEST_URI"])) {
	header("Content-Type: text/plain");
	$input = $_SERVER["QUERY_STRING"];
	$input = urldecode($input);
} else {
	$input = fgets(STDIN)
		or die();
}
finger_handle_query($input);
