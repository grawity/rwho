<?php
namespace RWho;
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/database.php");

class Client {
	function __construct($config) {
		$this->config = $config;
		$this->db = new \RWho\Database($config);

		// maximum age before which the entry will be considered stale
		// (e.g. the host temporarily down for some reason)
		// default is 1 minute more than the rwhod periodic update time
		$this->config->set_default("expire.mark-stale", "11m");

		// maximum age before which the entry will be considered dead
		// and not displayed in host list
		$this->config->set_default("expire.host-dead", "1d");
	}

	// retrieve(str? $user, str? $host) -> utmp_entry[]
	// Retrieve all currently known sessions for given query.
	// Both parameters optional.

	function retrieve($q_user, $q_host, $q_filter=true) {
		$dead_ts = time() - $this->config->get_rel_time("expire.host-dead");

		$sql = "SELECT * FROM utmp";
		$conds = array();
		if (strlen($q_user)) $conds[] = "user=:user";
		if (strlen($q_host)) $conds[] = "(host=:host OR host LIKE :parthost)";
		$conds[] = "updated >= $dead_ts";
		$sql .= " WHERE ".implode(" AND ", $conds);

		$st = $this->db->dbh->prepare($sql);
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
		$stale_ts = time() - $this->config->get_rel_time("expire.mark-stale");
		$dead_ts = time() - $this->config->get_rel_time("expire.host-dead");

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

		$st = $this->db->dbh->prepare($sql);
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
		$st = $this->db->dbh->prepare($sql);
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
		$stale_ts = time() - $this->config->get_rel_time("expire.mark-stale");
		$sql = "SELECT COUNT(DISTINCT user) AS count
			FROM utmp
			WHERE updated >= $stale_ts";
		return $this->__single_field_query($sql, "count");
	}

	// count_conns() -> int
	// Count all connections (utmp records).

	function count_conns() {
		$stale_ts = time() - $this->config->get_rel_time("expire.mark-stale");
		$sql = "SELECT COUNT(user) AS count
			FROM utmp
			WHERE updated >= $stale_ts";
		return $this->__single_field_query($sql, "count");
	}

	// count_hosts() -> int
	// Count all currently active hosts, with or without users.

	function count_hosts() {
		$stale_ts = time() - $this->config->get_rel_time("expire.mark-stale");
		$sql = "SELECT COUNT(host) AS count
			FROM hosts
			WHERE last_update >= $stale_ts";
		return $this->__single_field_query($sql, "count");
	}

	// is_stale(int $time) -> bool
	// Check whether the given timestamp should be considered "stale".

	function is_stale($timestamp) {
		$stale_ts = time() - $this->config->get_rel_time("expire.mark-stale");
		return $timestamp < $stale_ts;
	}

	// _find_user_plan_file(str $user, str $host) -> str?
	// Find the .plan file for a global user:
	// * Will return null if user doesn't exist or has 0 < uid < 25000.
	// * Will look for ~USER/.plan and /var/lib/plan/$USER in that order.
	// $host is ignored in current implementation.

	function _find_user_plan_file($user, $host) {
		if (!function_exists("posix_getpwnam"))
			return null;

		$pw = @posix_getpwnam($user);
		if (!$pw || ($pw["uid"] < MIN_UID && $pw["uid"] != 0))
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
		$ldap_dnf = $this->config->get("finger.ldap.user_dn", "");
		$ldap_attr = $this->config->get("finger.ldap.plan_attr", "planFile");

		if (!strlen($ldap_uri) || !strlen($ldap_dnf))
			return null;

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

		$res = @ldap_read($ldaph, $ldap_dn, "(objectClass=*)", array($ldap_attr));
		if (!$res)
			return null;

		$data = ldap_get_entries($ldaph, $res);
		if (!$data || !$data["count"])
			return null;

		$attr = strtolower($ldap_attr);
		if (isset($data[0][$attr])) {
			$text = $data[0][$attr][0];
			$text = rtrim($text, "\r\n");
			return $text;
		} else {
			return null;
		}
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
