#!/usr/bin/php
<?php

const DB_PATH = "/home/grawity/lib/cgi-data/rwho.db";

// read user input

$input = fgets(STDIN);
if (!strlen($input)) {
	die("Error.\n");
}
$input = rtrim($input);

// strip off /W

if ($input == "/W" or substr($input, 0, 3) == "/W ") {
	$query = substr($input, 3);
	$detail = true;
} else {
	$query = $input;
	$detail = false;
}

$q_user = null;
$q_host = null;

if (strlen($query)) {
	if (preg_match('|^(.*)@(.+)$|', $query, $m)) {
		$q_user = $m[1];
		$q_host = $m[2];
	} else {
		$q_user = $query;
	}
}

$db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);

$sql = "SELECT * FROM utmp";

$cond = array();
if (strlen($q_user))
	$cond[] = "user=:user";
if (strlen($q_host))
	$cond[] = "host=:host";
if (count($cond))
	$sql = $sql." WHERE ".implode(" AND ", $cond);

$st = $db->prepare($sql);
$st->bindValue(":user", $q_user);
$st->bindValue(":host", $q_host);

$fmt = "%-12s %-12s %-6s %s\n";
printf($fmt, "USER", "HOST", "LINE", "FROM");

$res = $st->execute();
$data = array();
while ($row = $res->fetchArray(SQLITE3_ASSOC))
	$data[] = $row;

usort($data, function($a, $b) {
	$s = strcmp($a["user"], $b["user"]);
	if (!$s) $s = strcmp($a["host"], $b["host"]);
	if (!$s) $s = strcmp($a["line"], $b["line"]);
	return $s;
	});

$last = array("user" => null);
foreach ($data as $row) {
	printf($fmt,
		($row["user"] != $last["user"] ? $row["user"] : ""),
		$row["host"], $row["line"], $row["rhost"]);
	$last = $row;
}
