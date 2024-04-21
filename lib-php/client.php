<?php
namespace RWho;
require_once(__DIR__."/../lib-php/database.php");
require_once(__DIR__."/../lib-php/util.php");

class Client {
	function __construct($config, $db=null) {
		$this->config = $config;
		$this->db = $db ?? new \RWho\Database($config);

		// maximum age before which the entry will be considered stale
		// (e.g. the host temporarily down for some reason)
		// default is 1 minute more than the rwhod periodic update time
		$this->_stale_age = $this->config->get_rel_time("expire.mark_stale", 11*60);

		// maximum age before which the entry will be considered dead
		// and not displayed in host list
		$this->_dead_age = $this->config->get_rel_time("expire.host_dead", 1*86400);
	}

	// retrieve(str? $user, str? $host) -> utmp_entry[]
	// Retrieve all currently known sessions for given query.
	// Both parameters optional.

	function retrieve($user, $host, $hide_rhost=true) {
		$stale_ts = time() - $this->_stale_age;
		$dead_ts = time() - $this->_dead_age;
		$data = $this->db->utmp_query($user, $host, $dead_ts);
		foreach ($data as &$row) {
			$row["is_summary"] = false;
			$row["is_stale"] = ($row["updated"] < $stale_ts);
			if ($hide_rhost) {
				$row["rhost"] = "none";
			}
		}
		usort($data, function($a, $b) {
			return strnatcmp($a["user"], $b["user"])
			    ?: strnatcmp($a["host"], $b["host"])
			    ?: strnatcmp($a["line"], $b["line"])
			    ?: strnatcmp($a["rhost"], $b["rhost"])
			    ;
		});
		return $data;
	}

	// retrieve_hosts() -> host_entry[]
	// Retrieve all currently active hosts, with user and connection counts.

	function retrieve_hosts($all=true) {
		$stale_ts = time() - $this->_stale_age;
		$dead_ts = time() - $this->_dead_age;
		$minimum_ts = $all ? $dead_ts : $stale_ts;
		$data = $this->db->host_query($minimum_ts);
		foreach ($data as &$row) {
			$row["is_stale"] = ($row["last_update"] < $stale_ts);
		}
		return $data;
	}

	// count_users() -> int
	// Count unique user names on all utmp records.

	function count_users() {
		$stale_ts = time() - $this->_stale_age;
		return $this->db->utmp_count(true, $stale_ts);
	}

	// count_lines() -> int
	// Count all connections (utmp records).

	function count_lines() {
		$stale_ts = time() - $this->_stale_age;
		return $this->db->utmp_count(false, $stale_ts);
	}

	// count_hosts() -> int
	// Count all currently active hosts, with or without users.

	function count_hosts() {
		$stale_ts = time() - $this->_stale_age;
		return $this->db->host_count($stale_ts);
	}

	// is_stale(int $time) -> bool
	// Check whether the given timestamp should be considered "stale".

	function is_stale($timestamp) {
		$stale_ts = time() - $this->_stale_age;
		return $timestamp < $stale_ts;
	}

	// purge_dead() -> int
	// Delete rows belonging to dead hosts

	function purge_dead() {
		$dead_ts = time() - $this->_dead_age;
		return $this->db->delete_old($dead_ts);
	}

	// _find_user_plan_file(str $user, str $host) -> str?
	// Find the .plan file for a global user:
	// * Will return null if user doesn't exist or has 0 < uid < 25000.
	// * Will look for ~USER/.plan and /var/lib/plan/$USER in that order.
	// $host is ignored in current implementation.

	function _find_user_plan_file($user, $host) {
		$min_uid = intval($this->config->get("finger.plan_min_uid", 1000));

		if (!function_exists("posix_getpwnam"))
			return null;

		$pw = @posix_getpwnam($user);
		if (!$pw || ($pw["uid"] < $min_uid && $pw["uid"] != 0))
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

	// _read_user_plan_ldap(str $user, str $host) -> str?
	// Look up the .plan file's contents from a given user's LDAP entry.
	// $host is ignored in current implementation.

	function _read_user_plan_ldap($user, $host) {
		if (!function_exists("ldap_connect"))
			return null;

		$ldap_uri = $this->config->get("finger.ldap.uri", "");
		$base_dn = $this->config->get("finger.ldap.base_dn", "");
		$filter = $this->config->get("finger.ldap.filter", "(&(objectClass=posixAccount)(uid=%s))");
		$plan_attr = $this->config->get("finger.ldap.plan_attr", "planFile");

		if (!strlen($ldap_uri))
			return null;

		if (!strlen($base_dn) || !strlen($filter))
			return null;

		$ldaph = ldap_connect($ldap_uri);
		if (!$ldaph)
			return null;

		$ok = ldap_set_option($ldaph, LDAP_OPT_PROTOCOL_VERSION, 3);
		if (!$ok)
			return null;

		$ok = ldap_bind($ldaph, null, null);
		if (!$ok)
			return null;

		$user = ldap_escape($user, "", \LDAP_ESCAPE_FILTER);
		$filter = sprintf($filter, $user);
		$res = @ldap_search($ldaph, $base_dn, $filter, [$plan_attr], 0, 1);
		if (!$res)
			return null;

		$data = ldap_get_entries($ldaph, $res);
		if (!$data || !$data["count"])
			return null;

		$attr = strtolower($plan_attr);
		if (isset($data[0][$attr])) {
			$text = $data[0][$attr][0];
			$text = rtrim($text, "\r\n");
			return $text;
		} else {
			return null;
		}
	}

	// summarize(utmp_entry[] $data) -> utmp_entry[]
	// Sort utmp data by username and group by host. Resulting entries
	// will have no more than one entry for any user@host pair.

	function summarize($utmp) {
		$out = [];
		$byuser = [];
		foreach ($utmp as &$entry) {
			$byuser[$entry["user"]][$entry["host"]][] = $entry;
		}
		foreach ($byuser as $user => &$byhost) {
			foreach ($byhost as $host => &$sessions) {
				$byfrom = [];
				$updated = [];
				$stale = [];
				foreach ($sessions as $entry) {
					$from = \RWho\Util\normalize_host($entry["rhost"]);
					if ($from === "(detached)")
						continue;
					@$byfrom[$from][] = $entry["line"];
					@$updated[$from] = max($updated[$from], $entry["updated"]);
					@$stale[$from] = @$stale[$from] || $entry["is_stale"];
					$uid = $entry["uid"];
				}
				ksort($byfrom);
				foreach ($byfrom as $from => &$lines) {
					$out[] = [
						"user" => $user,
						"uid" => $uid,
						"host" => $host,
						"line" => count($lines) == 1
							? $lines[0] : count($lines),
						"rhost" => $from,
						"updated" => $updated[$from],
						"is_summary" => count($lines) > 1,
						"is_stale" => $stale[$from],
					];
				}
			}
		}
		return $out;
	}

	// get_plan_file(str $user, str $host) -> str?
	// Get the text of user's .plan file, first from filesystem, then from LDAP

	function get_plan_file($user, $host) {
		$path = $this->_find_user_plan_file($user, $host);
		if ($path !== null) {
			$fh = @fopen($path, "r");
			if ($fh) {
				$text = fread($fh, 65536);
				$text = rtrim($text, "\r\n");
				fclose($fh);
				return $text;
			}
		}

		$text = $this->_read_user_plan_ldap($user, $host);
		if ($text !== null) {
			return $text;
		}

		return null;
	}
}
