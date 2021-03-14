<?php
namespace RWho;

const MIN_UID = 1000;

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
				if ($from === "(detached)")
					continue;
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

// strip_domain(str $fqdn) -> str $hostname
// Return the leftmost component of a dotted domain name.

function strip_domain($fqdn) {
	$pos = strpos($fqdn, ".");
	return $pos === false ? $fqdn : substr($fqdn, 0, $pos);
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

	# strip (pid).%w from tmux name
	$host = preg_replace('/^tmux\(\d+\)\.%\d+$/', '(tmux)', $host);

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

// stripsuffix(str $str, str $suffix) -> str
// Remove an exact suffix if present.

function stripsuffix($str, $suffix) {
	$len = -strlen($suffix);
	if (substr_compare($str, $suffix, $len) === 0)
		return substr($str, 0, $len);
	else
		return $str;
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
	if ($h !== false) return _strip_addr($h);

	if (defined("STDIN")) {
		$h = stream_socket_get_name(constant("STDIN"), true);
		if ($h !== false) return _strip_addr($h);
	}
}

return true;
