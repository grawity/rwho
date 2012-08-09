<?php
namespace RWho;

class Config {
	static $data = array();

	static function parse($file) {
		$fh = fopen($file, "r");
		if (!$fh)
			return;
		while (($line = fgets($fh)) !== false) {
			if (!strlen($line))
				continue;
			if ($line[0] === ";" || $line[0] === "#")
				continue;
			list ($key, $val) = explode("=", $line, 2);
			$key = trim($key);
			$val = trim($val);
			self::$data[$key] = $val;
		}
		fclose($fh);
	}

	static function has($key) {
		return isset(self::$data[$key]);
	}

	static function set($key, $value) {
		return self::$data[$key] = $value;
	}

	static function get($key) {
		return self::$data[$key];
	}

	static function getbool($key) {
		$v = self::$data[$key];
		return $v === "true" ? true
			: $v === "false" ? false
			: $v === "yes" ? true
			: $v === "no" ? false
			: (bool) $v;
	}
}

class DB {
	static $dbh;

	static function connect() {
		if (!isset(self::$dbh)) {
			self::$dbh = new \PDO(Config::get("db.pdo_driver"),
						Config::get("db.username"),
						Config::get("db.password"));
		}
		return self::$dbh;
	}
}

Config::$data = array(
	// maximum age before which the entry will be considered stale
	// default is 1 minute more than the rwhod periodic update time
	"expire" => 11*60,
	"finger.log" => false,
);
Config::parse(__DIR__."/../rwho.conf");

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
	if ($user === null)
		$user = '';
	if ($host === null)
		$host = '';
	return array($user, $host);
}

// retrieve(str? $user, str? $host) -> utmp_entry[]
// Retrieve all currently known sessions for given query.
// Both parameters optional.

function retrieve($q_user, $q_host) {
	$db = DB::connect();

	$sql = "SELECT utmp.*, names.name
		FROM utmp
		LEFT JOIN names
		ON	utmp.host = names.host
			AND utmp.rawuser = names.user";
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
			$names = array();

			foreach ($sessions as $entry) {
				$from = normalize_host($entry["rhost"]);
				@$byfrom[$from][] = $entry["line"];
				@$updated[$from] = max($updated[$from], $entry["updated"]);
				if (!isset($names[$user]))
					$names[$user] = $entry["name"];
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
					"name" => $names[$user],
					);
			}
		}
	}
	return $out;
}

// retrieve_hosts() -> host_entry[]
// Retrieve all currently active hosts, with user and connection counts.

function retrieve_hosts() {
	$db = DB::connect();

	$max_ts = time() - Config::get("expire");

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
	$db = DB::connect();

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
	$max_ts = time() - Config::get("expire");
	$sql = "SELECT COUNT(DISTINCT user) AS count
		FROM utmp
		WHERE updated >= $max_ts";
	return __single_field_query($sql, "count");
}

// count_conns() -> int
// Count all connections (utmp records).

function count_conns() {
	$max_ts = time() - Config::get("expire");
	$sql = "SELECT COUNT(user) AS count
		FROM utmp
		WHERE updated >= $max_ts";
	return __single_field_query($sql, "count");
}

// count_hosts() -> int
// Count all currently active hosts, with or without users.

function count_hosts() {
	$max_ts = time() - Config::get("expire");
	$sql = "SELECT COUNT(host) AS count
		FROM hosts
		WHERE last_update >= $max_ts";
	return __single_field_query($sql, "count");
}

function is_stale($timestamp) {
	return $timestamp < time() - Config::get("expire");
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

// normalize_host(str $host) -> str
// Normalize the "remote host" value for use as array key in summarize()

function normalize_host($host) {
	# strip .window from GNU Screen name
	#$host = preg_replace('/(:S)\.\d+$/', '$1', $host);
	$host = preg_replace('/^(.+):S\.\d+$/', '$1 (screen)', $host);

	# strip .screen from X11 display
	$host = preg_replace('/(:\d+)\.\d+$/', '$1', $host);

	# strip [pid] from mosh name
	$host = preg_replace('/^mosh \[\d+\]$/', '(mosh)', $host);
	$host = preg_replace('/ via mosh \[\d+\]$/', ' (mosh)', $host);

	return $host;
}

// interval(unixtime $start, unixtime? $end) -> str
// Convert the difference between two timestamps (in seconds), or
// between given Unix timestamp and current time, to a human-readable
// time interval: "X days", "Xh Ym", "Xm Ys", "X secs"

function interval($start, $end = null) {
	if ($end === null)
		$end = time();
	if ($start > $end)
		list($start, $end) = array($end, $start);

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

// http_build_query(dict<str,str> $items) -> str $query
// Format an assoc array of query items to a query string

function http_build_query($items) {
	$query = array();
	foreach ($items as $key => $value) {
		if ($value === null or !strlen($value))
			$query[] = urlencode($key);
		else
			$query[] = urlencode($key)."=".urlencode($value);
	}
	return implode("&", $query);
}

// mangle_query(dict<str,str> $add, list<str> $remove) -> str $query
// Add and remove given items from current query string

function mangle_query($add, $remove=null) {
	parse_str($_SERVER["QUERY_STRING"], $query);

	if ($add !== null)
		foreach ($add as $key => $value)
			$query[$key] = $value;

	if ($remove !== null)
		foreach ($remove as $key)
			unset($query[$key]);

	return http_build_query($query);
}

// canonicalize_utmp_user(utmp_entry& $entry) -> utmp_entry
// canonicalize_user(str $user, int $uid, str $host) -> str $user
// Strip off the sssd @domain suffix, for summaries.

function canonicalize_utmp_user(&$entry) {
	$entry['user'] = canonicalize_user($entry['user'],
					$entry['uid'], $entry['host']);
	return $entry;
}

function canonicalize_user($user, $uid, $host) {
	$pos = strpos($user, "@");
	if ($pos !== false)
		$user = substr($user, 0, $pos);
	/*
	if (false)		;
	elseif ($uid > 42000)	$user = stripsuffix($user, "@nullroute");
	elseif ($uid > 25999)	;
	elseif ($uid > 25000)	$user = stripsuffix($user, "@cluenet");
	*/
	return $user;
}

// stripsuffix(str $str, str $suffix) -> str
// Remove an exact suffix if present.

function stripsuffix($str, $suffix) {
	$len = -strlen($suffix);
	if (substr_compare($str, $suffix, $len) === 0)
		return substr($str, 0, $len);
	else
		return $str;
}

// find_user_plan(str $user, str $host) -> str?
// Find the .plan file for a global user:
// * Will return null if user doesn't exist or has 0 < uid < 25000.
// * Will look for ~USER/.plan and /var/lib/plan/$USER in that order.
// $host is ignored in current implementation.

function find_user_plan($user, $host) {
	if (!function_exists("posix_getpwnam"))
		return null;

	$pw = @posix_getpwnam($user);
	if (!$pw || ($pw["uid"] < 25000 && $pw["uid"] != 0))
		return null;

	$dir = $pw["dir"];
	$path = @realpath("$dir/.plan");
	if ($path && @is_file($path) && @is_readable($path))
		return $path;

	$path = "/var/lib/plan/{$pw["name"]}";
	if (@is_file($path) && @is_readable($path))
		return $path;

	return null;
}

return true;
