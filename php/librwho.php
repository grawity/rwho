<?php
namespace RWho;

require __DIR__."/../config.php";

if (!defined("MAX_AGE"))
	// maximum age before which the entry will be considered stale
	// default is 1 minute more than the rwhod periodic update time
	define("MAX_AGE", 11*60);

// parse_query(str? $query) -> str $user, str $host
// Split a "user", "user@host", or "@host" query to components.

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

// retrieve(str? $user, str? $host) -> utmp_entry[]
// Retrieve all currently known sessions for given query.
// Both parameters optional.

function retrieve($q_user, $q_host) {
	$db = new \PDO(DB_PATH, DB_USER, DB_PASS)
		or die("error: could not open rwho database\r\n");

	$sql = "SELECT * FROM utmp";
	$conds = array();
	if (strlen($q_user)) $conds[] = "user=:user";
	if (strlen($q_host)) $conds[] = "(host=:host OR host LIKE :parthost)";
	if (count($conds))
		$sql .= " WHERE ".implode(" AND ", $conds);
	$sql .= " ORDER BY user, host, line, time DESC";

	$st = $db->prepare($sql);
	if (strlen($q_user)) $st->bindValue(":user", $q_user);
	if (strlen($q_host)) {
		$st->bindValue(":host", $q_host);
		$st->bindValue(":parthost", "$q_host.%");
	}
	if (!$st->execute())
		return null;

	$data = array();
	while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
		$row["is_summary"] = false;
		$data[] = $row;
	}
	return $data;
}

// summarize(utmp_entry[] $data) -> utmp_entry[]
// Sort utmp data by username and group by host. Resulting entries
// will have no more than one entry for any user@host pair.

function summarize($utmp) {
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
				$from = $entry["rhost"];
				$from = preg_replace('/:S\.\d+$/', '', $from);
				#$from = preg_replace('/\..+$/', '', $from);
				@$byfrom[$from][] = $entry["line"];
				@$updated[$from] = max($updated[$from], $entry["updated"]);
				$uid = $entry["uid"];
			}
			ksort($byfrom);
			foreach ($byfrom as $from => &$lines) {
				$out[] = array(
					"user" => $user,
					"uid" => $uid,
					"host" => $host,
					"line" => count($lines) == 1
						? $lines[0] : count($lines),
					"rhost" => $from,
					"is_summary" => count($lines) > 1,
					"updated" => $updated[$from],
					);
			}
		}
	}
	return $out;
}

// retrieve_hosts() -> host_entry[]
// Retrieve all currently active hosts, with user and connection counts.

function retrieve_hosts() {
	$db = new \PDO(DB_PATH, DB_USER, DB_PASS)
		or die("error: could not open rwho database\r\n");

	$max_ts = time() - MAX_AGE;

	$sql = "SELECT
			hosts.*,
			COUNT(DISTINCT utmp.user) AS users,
			COUNT(utmp.user) AS entries
		FROM hosts
		LEFT OUTER JOIN utmp
		ON hosts.host = utmp.host
		WHERE last_update >= $max_ts
		GROUP BY host";

	$st = $db->prepare($sql);
	if (!$st->execute()) {
		var_dump($st->errorInfo());
		return null;
	}

	$data = array();
	while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
		$data[] = $row;
	}
	return $data;
}

// Internal use only:
// __single_field_query(str $sql, str $field) -> mixed
// Return a single column from the first field of a SQL SELECT result.
// Useful for 'SELECT COUNT(x) AS count' kind of queries.

function __single_field_query($sql, $field) {
	$db = new \PDO(DB_PATH, DB_USER, DB_PASS)
		or die("error: could not open rwho database\r\n");

	$st = $db->prepare($sql);
	if (!$st->execute()) {
		var_dump($st->errorInfo());
		return null;
	}

	while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
		return $row[$field];
	}
}

// count_users() -> int
// Count unique user names on all utmp records.

function count_users() {
	$max_ts = time() - MAX_AGE;
	$sql = "SELECT COUNT(DISTINCT user) AS count
		FROM utmp
		WHERE updated >= $max_ts";
	return __single_field_query($sql, "count");
}

// count_conns() -> int
// Count all connections (utmp records).

function count_conns() {
	$max_ts = time() - MAX_AGE;
	$sql = "SELECT COUNT(user) AS count
		FROM utmp
		WHERE updated >= $max_ts";
	return __single_field_query($sql, "count");
}

// count_hosts() -> int
// Count all currently active hosts, with or without users.

function count_hosts() {
	$max_ts = time() - MAX_AGE;
	$sql = "SELECT COUNT(host) AS count
		FROM hosts
		WHERE last_update >= $max_ts";
	return __single_field_query($sql, "count");
}

function is_stale($timestamp) {
	return $timestamp < time() - MAX_AGE;
}

// strip_domain(str $fqdn) -> str $hostname
// Return the leftmost component of a dotted domain name.

function strip_domain($fqdn) {
	$pos = strpos($fqdn, ".");
	return $pos === false ? $fqdn : substr($fqdn, 0, $pos);
}

// Cluenet internal use only:
// user_is_global(str $user) -> bool
// Check whether given username belongs to the Cluenet UID range.
// The name->uid conversion is done using system facilities.

function user_is_global($user) {
	$pwent = posix_getpwnam($user);
	return $pwent ? $pwent["uid"] > 25000 : false;
}

// interval(unixtime $start, unixtime? $end) -> str
// Convert the difference between two timestamps (in seconds), or
// between given Unix timestamp and current time, to a human-readable
// time interval: "X days", "Xh Ym", "Xm Ys", "X secs"

function interval($start, $end = null) {
	if ($end === null)
		$end = time();
	$diff = $end - $start;
	$diff -= $s = $diff % 60; $diff /= 60;
	$diff -= $m = $diff % 60; $diff /= 60;
	$diff -= $h = $diff % 24; $diff /= 24;
	$d = $diff;
	switch (true) {
		case $d > 1:		return "{$d} days";
		case $h > 0:		return "{$h}h {$m}m";
		case $m > 1:		return "{$m}m {$s}s";
		default:		return "{$s} secs";
	}
}

return true;
