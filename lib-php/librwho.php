<?php
namespace RWho;

class Config {
	static $data = array();

	static function parse($file) {
		$fh = fopen($file, "r");
		if (!$fh)
			return;
		while (($line = fgets($fh)) !== false) {
			$line = rtrim($line);
			if (!strlen($line))
				continue;
			elseif ($line[0] === ";" || $line[0] === "#")
				continue;
			elseif (preg_match('/^\s+(.*)$/', $line, $m)) {
				list ($_, $val) = $m;
				if (isset($key))
					self::$data[$key] .= $line;
			}
			elseif (preg_match('/^(\S+)\s*=\s*(.*)$/', $line, $m)) {
				list ($_, $key, $val) = $m;
				self::$data[$key] = $val;
			}
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
		if ($v === "true" || $v === "yes")
			return true;
		elseif ($v === "false" || $v === "no")
			return false;
		else
			return (bool) $v;
	}

	static function getlist($key) {
		$v = self::$data[$key];
		$l = preg_split("/\s+/", $v);
		return $l;
	}
}

class DB {
	static $dbh;

	static function connect() {
		if (!isset(self::$dbh)) {
			self::$dbh = new \PDO(Config::get("db.pdo_driver"),
						Config::get("db.username"),
						Config::get("db.password"));
			// <http://stackoverflow.com/a/60496/49849> says that PDO emulates
			// prepared statements internally by default
			self::$dbh->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
			//self::$dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		}
		return self::$dbh;
	}
}

Config::$data = array(
	// maximum age before which the entry will be considered stale
	// (e.g. the host temporarily down for some reason)
	// default is 1 minute more than the rwhod periodic update time
	"expire" => 11*60,
	// maximum age before which the entry will be considered dead
	// and not displayed in host list
	"expire.host-dead" => 86400,
	"finger.log" => false,
	"privacy.allow_addr" => "",
	"privacy.allow_anonymous" => true,
	"privacy.hide_rhost" => false,
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

function retrieve($q_user, $q_host, $q_filter=true) {
	$db = DB::connect();

	$dead_ts = time() - Config::get("expire.host-dead");

	$sql = "SELECT * FROM utmp";
	$conds = array();
	if (strlen($q_user)) $conds[] = "user=:user";
	if (strlen($q_host)) $conds[] = "(host=:host OR host LIKE :parthost)";
	$conds[] = "updated >= $dead_ts";
	$sql .= " WHERE ".implode(" AND ", $conds);
	$sql .= " ORDER BY user, host, line, time DESC";

	$st = $db->prepare($sql);
	if (strlen($q_user)) $st->bindValue(":user", $q_user);
	if (strlen($q_host)) {
		$w_host = str_replace("_", "\\_", $q_host);
		if (strpos($w_host, "%") === false)
			$w_host .= ".%";

		$st->bindValue(":host", $q_host);
		$st->bindValue(":parthost", $w_host);
	}
	if (!$st->execute())
		return null;

	$data = array();
	while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
		$row["is_summary"] = false;
		$data[] = $row;
	}

	if ($q_filter)
		foreach ($data as &$row)
			$row["rhost"] = "none";

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
				$from = normalize_host($entry["rhost"]);
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

function retrieve_hosts($all=true) {
	$db = DB::connect();

	$stale_ts = time() - Config::get("expire");
	$dead_ts = time() - Config::get("expire.host-dead");

	$ignore_ts = $all ? $dead_ts : $stale_ts;

	$sql = "SELECT
			hosts.*,
			COUNT(DISTINCT utmp.user) AS users,
			COUNT(utmp.user) AS entries
		FROM hosts
		LEFT OUTER JOIN utmp
		ON hosts.host = utmp.host
		WHERE last_update >= $ignore_ts
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
	$host = preg_replace('/^(\d+):S\.\d+$/', '$1? (screen)', $host);
	$host = preg_replace('/^(.+):S\.\d+$/', '$1 (screen)', $host);

	# strip .screen from X11 display
	$host = preg_replace('/(:\d+)\.\d+$/', '$1', $host);

	$host = preg_replace('/^:\d+$/', '$0 (X11)', $host);

	# strip [pid] from mosh name
	$host = preg_replace('/^mosh \[\d+\]$/', '(detached)', $host);
	$host = preg_replace('/ via mosh \[\d+\]$/', '', $host);

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

// find_user_plan_file(str $user, str $host) -> str?
// Find the .plan file for a global user:
// * Will return null if user doesn't exist or has 0 < uid < 25000.
// * Will look for ~USER/.plan and /var/lib/plan/$USER in that order.
// $host is ignored in current implementation.

function find_user_plan_file($user, $host) {
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

// read_user_plan_ldap(str $user, str $host) -> str?
// Look up the .plan file's contents from a given user's LDAP entry.
// $host is ignored in current implementation.

function read_user_plan_ldap($user, $host) {
	if (!function_exists("ldap_connect"))
		return null;

	$ldap_uri = Config::get("finger.ldap.uri");
	$ldap_dnf = Config::get("finger.ldap.dn");
	$ldap_attr = Config::get("finger.ldap.attr");

	if (!strlen($ldap_uri) || !strlen($ldap_dnf))
		return null;
	if (!strlen($ldap_attr))
		$ldap_attr = "planFile";

	$ldap_dn = sprintf($ldap_dnf, $user);

	$ldaph = ldap_connect($ldap_uri);
	if (!$ldaph)
		return null;

	$ok = ldap_set_option($ldaph, LDAP_OPT_PROTOCOL_VERSION, 3);
	if (!$ok)
		return null;

	$ok = ldap_bind($ldaph, null, null);
	if (!$ok)
		return null;

	$res = ldap_read($ldaph, $ldap_dn, "(objectClass=*)", array($ldap_attr));
	if (!$res)
		return null;

	$data = ldap_get_entries($ldaph, $res);
	if (!$data || !$data["count"])
		return null;

	$attr = strtolower($ldap_attr);
	if (!isset($data[0][$attr])) {
		$text = $data[0][$attr][0];
		$text = rtrim($text, "\r\n");
		return $text;
	} else {
		return null;
	}
}

// read_user_plan(str $user, str $host) -> str?
// Get the text of user's .plan file, first from LDAP, then from filesystem.

function read_user_plan($user, $host) {
	$text = read_user_plan_ldap($user, $host);
	if ($text !== null) {
		return $text;
	}

	$path = find_user_plan_file($user, $host);
	if ($path !== null) {
		$fh = @fopen($path, "r");
		if ($fh) {
			$text = fread($fh, 65536);
			$text = rtrim($text, "\r\n");
			fclose($fh);
			return $text;
		}
	}

	return null;
}

// ip_expand(str $addr) -> str?
// Expand a string IP address to binary representation.
// v6-mapped IPv4 addresses will be converted to IPv4.

function ip_expand($addr) {
	$addr = @inet_pton($addr);
	if ($addr === false || $addr === -1)
		return null;
	if (strlen($addr) == 16) {
		if (substr($addr, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF"
		||  substr($addr, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00")
			return substr($addr, 12);
	}
	return $addr;
}

// ip_cidr(str $host, str $cidrmask) -> bool
// Check if $host belongs to the network $mask (specified in CIDR format)
// If $cidrmask does not contain /prefixlen, an exact match will be done.

function ip_cidr($host, $mask) {
	@list ($net, $len) = explode("/", $mask, 2);

	$host = ip_expand($host);
	$net = ip_expand($net);

	if ($host === null || $net === null || strlen($host) !== strlen($net))
		return false;

	$nbits = strlen($net) * 8;

	if ($len === null || $len == $nbits)
		return $host === $net;
	elseif ($len == 0)
		return true;
	elseif ($len < 0 || $len > $nbits)
		return false;

	$host = unpack("C*", $host);
	$net = unpack("C*", $net);

	for ($i = 1; $i <= count($net) && $len > 0; $i++) {
		$bits = $len >= 8 ? 8 : $len;
		$bmask = (0xFF << 8 >> $bits) & 0xFF;
		if (($host[$i] & $bmask) != ($net[$i] & $bmask))
			return false;
		$len -= 8;
	}
	return true;
}

// _strip_addr(str $addr) -> str

function _strip_addr($addr) {
	if (substr($addr, 0, 7) === "::ffff:")
		$addr = substr($addr, 7);
	return $addr;
}

// get_rhost() -> str?
// Get the remote host of current connection.

function get_rhost() {
	$h = @$_SERVER["REMOTE_ADDR"];
	if (isset($h)) return _strip_addr($h);

	$h = getenv("REMOTE_HOST");
	if ($h !== false) return _strip_addr($h);

	$h = getenv("REMOTEHOST");
	if ($h !== false) return strip_addr($h);

	if (defined("STDIN")) {
		$h = stream_socket_get_name(constant("STDIN"), true);
		if ($h !== false) return strip_addr($h);
	}
}

return true;
