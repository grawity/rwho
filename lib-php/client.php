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
}
