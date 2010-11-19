#!/usr/bin/php
<?php
namespace RWho;

define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

function finger_handle() {
	$input = fgets(STDIN)
		or die();
	list ($query, $detailed) = finger_parse($input);
	list ($q_user, $q_host) = parse_query($query);
	$data = retrieve($q_user, $q_host);
	if (!count($data))
		die("Nobody is logged in.\r\n");
	if (!$detailed)
		$data = summarize($data);
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
		? "%-12s %1s %-22s %-8s %s\r\n"
		: "%-12s %1s %-12s %-8s %s\r\n";
	printf($fmt, "USER", "", "HOST", "LINE", "FROM");

	$last = array("user" => null);
	foreach ($data as $row) {
		$flag = "";
		if (is_stale($row["updated"]))
			$flag = "?";
		elseif ($row["uid"] == 0)
			$flag = "#";
		elseif ($row["uid"] < 25000)
			$flag = "<";

		printf($fmt,
			$row["user"] !== $last["user"] ? $row["user"] : "",
			$flag,
			$detailed ? $row["host"] : strip_domain($row["host"]),
			$row["is_summary"] ? "{".$row["line"]."}" : $row["line"],
			strlen($row["rhost"]) ? $row["rhost"] : "-");
		$last = $row;
	}
}

finger_handle();
