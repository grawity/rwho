<?php

require __DIR__."/config.inc";

if (!defined("MAX_AGE"))
	// maximum age before which the entry will be considered stale
	// default is 1 minute more than the rwhod periodic update time
	define("MAX_AGE", 11*60);

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
		$row["is_summary"] = false;
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
			$updated = array();
			foreach ($sessions as $entry) {
				if (preg_match('/^(.+):S\.\d+$/', $entry["rhost"], $m)) {
					$from = "(screen) {$m[1]}";
				} else {
					$from = $entry["rhost"];
				}
				@$byfrom[$from][] = $entry["line"];
				@$updated[$from] = max($updated[$from], $entry["updated"]);
			}
			ksort($byfrom);
			foreach ($byfrom as $from => &$lines) {
				$out[] = array(
					"user" => $user,
					"host" => $host,
					"line" => count($lines) == 1
						? $lines[0] : count($lines),
					"rhost" => strlen($from)
						? $from : "(local)",
					"is_summary" => count($lines) > 1,
					"updated" => $updated[$from],
					);
			}
		}
	}
	return $out;
}

function is_stale($timestamp) {
	return $timestamp < time() - MAX_AGE;
}

function pretty_text($data) {
	$fmt = "%-12s %-12s %-8s %1s %s\r\n";
	printf($fmt, "USER", "HOST", "LINE", "", "FROM");

	$last = array("user" => null);
	foreach ($data as $row) {
		printf($fmt,
			$row["user"] !== $last["user"] ? $row["user"] : "",
			$row["host"],
			$row["is_summary"] ? "{".$row["line"]."}" : $row["line"],
			is_stale($row["updated"]) ? "?" : "",
			$row["rhost"]);
		$last = $row;
	}
}

function user_is_global($user) {
	$pwent = posix_getpwnam($user);
	return $pwent ? $pwent["uid"] > 25000 : false;
}

if (!defined("RWHO_LIB")) {
	header("Content-Type: text/plain; charset=utf-8");
	$data = retrieve(null, null);
	if ($data)
		pretty_text($data);
	else
		print "error: Failed to retrieve rwho data.\n";
}
