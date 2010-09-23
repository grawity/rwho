#!/usr/bin/php
<?php

const DB_PATH = "/home/grawity/lib/cgi-data/rwho.db";

function finger_parse($input) {
	$input = rtrim($input, "\r\n");
	if ($input === "/W" or substr($input, 0, 3) === "/W ") {
		$query = substr($input, 3);
		$detail = true;
	} else {
		$query = $input;
		$detail = false;
	}
	return array($query, $detail);
}

function prettyprint($data) {
	$fmt = "%-12s %-12s %-8s %s\r\n";
	printf($fmt, "USER", "HOST", "LINE", "FROM");

	$last = array("user" => null);
	foreach ($data as $row) {
		printf($fmt,
			// only display usernames once
			$row["user"] !== $last["user"] ? $row["user"] : "",
			$row["host"],
			$row["line"],
			strlen($row["rhost"]) ? $row["rhost"] : "(local)"
			);
		$last = $row;
	}
}

$input = fgets(STDIN)
	or die();

list($query, $detail) = finger_parse($input);

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

$db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY)
	or die("error: could not open rwho database\r\n");

$sql = "SELECT * FROM utmp";
$cond = array();
if (strlen($q_user))
	$cond[] = "user=:user";
if (strlen($q_host))
	$cond[] = "host=:host";
if (count($cond))
	$sql .= " WHERE ".implode(" AND ", $cond);
$sql .= " ORDER BY user, host, line, time DESC";

$st = $db->prepare($sql);
$st->bindValue(":user", $q_user);
$st->bindValue(":host", $q_host);
$res = $st->execute();
$data = array();
while ($row = $res->fetchArray(SQLITE3_ASSOC))
	$data[] = $row;

prettyprint($data);
