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
}
