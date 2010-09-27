<?php

require __DIR__."/config.inc";

function parse_query($query) {
	$user = null;
	$host = null;
	if (strlen($query)) {
		if (preg_match('|^(.*)@(.+)$|', $query, $m)) {
			$user = $m[1];
			$host = $m[2];
		} else {
			$user = $query;
		}
	}
	return array($user, $host);
}

function retrieve($q_user, $q_host) {
	$db = new PDO(DB_PATH, DB_USER, DB_PASS)
		or die("error: could not open rwho database\r\n");

	$sql = "SELECT * FROM utmp";
	$conds = array();
	if (strlen($q_user)) $conds[] = "user=:user";
	if (strlen($q_host)) $conds[] = "host=:host";
	if (count($conds))
		$sql .= " WHERE ".implode(" AND ", $conds);
	$sql .= " ORDER BY user, host, line, time DESC";

	$st = $db->prepare($sql);
	if (strlen($q_user)) $st->bindValue(":user", $q_user);
	if (strlen($q_host)) $st->bindValue(":host", $q_host);
	if (!$st->execute())
		return null;

	$data = array();
	while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
		$row["_summary"] = false;
		$data[] = $row;
	}
	return $data;
}

function prep_summarize($utmp) {
	$out = array();
	$byuser = array();
	foreach ($utmp as &$entry) {
		$byuser[$entry["user"]][$entry["host"]][] = $entry;
	}
	foreach ($byuser as $user => &$byhost) {
		foreach ($byhost as $host => &$sessions) {
			$byfrom = array();
			foreach ($sessions as $entry) {
				if (preg_match('/^(.+):S\.\d+$/', $entry["rhost"], $m)) {
					$byfrom["(screen) {$m[1]}"][] = $entry["line"];
				} else {
					$byfrom[$entry["rhost"]][] = $entry["line"];
				}
				ksort($byfrom);
			}
			foreach ($byfrom as $from => &$lines) {
				$out[] = array(
					"user" => $user,
					"host" => $host,
					"line" => count($lines) == 1
						? $lines[0] : count($lines),
					"rhost" => strlen($from)
						? $from : "(local)",
					"_summary" => count($lines) > 1,
					);
			}
		}
	}
	return $out;
}

function pretty_text($data) {
	$fmt = "%-12s %-12s %-8s %s\r\n";
	printf($fmt, "USER", "HOST", "LINE", "FROM");

	$last = array("user" => null);
	foreach ($data as $row) {
		printf($fmt,
			$row["user"] !== $last["user"] ? $row["user"] : "",
			$row["host"],
			$row["_summary"] ? "{".$row["line"]."}" : $row["line"],
			$row["rhost"]);
		$last = $row;
	}
}

function user_is_global($user) {
	$pwent = posix_getpwnam($user);
	return $pwent ? $pwent["uid"] > 25000 : false;
}

if (!defined("RWHO_LIB")) {
	$data = retrieve(null, null);
	if ($data)
		pretty_text($data);
	else
		print "error: Failed to retrieve rwho data.\n";
}
