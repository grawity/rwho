<?php
namespace RWho;

class Database {
	public $dbh;

	function __construct($config) {
		$dsn = $config->get("db.pdo_driver");
		$user = $config->get("db.username");
		$pass = $config->get("db.password");
		$options = [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_EMULATE_PREPARES => false,
		];
		if (empty($dsn)) {
			throw new \Exception("Database PDO DSN missing from configuration");
		}
		if (!empty($tmp = $config->get("db.tls_ca"))) {
			$options[\PDO::MYSQL_ATTR_SSL_CA] = $tmp;
		}
		if (!empty($tmp = $config->get("db.tls_cert"))) {
			$options[\PDO::MYSQL_ATTR_SSL_CERT] = $tmp;
		}
		if (!empty($tmp = $config->get("db.tls_key"))) {
			$options[\PDO::MYSQL_ATTR_SSL_KEY] = $tmp;
		}
		$this->dbh = new \PDO($dsn, $user, $pass, $options);
	}

	function begin() {
		$this->dbh->beginTransaction();
	}

	function commit() {
		$this->dbh->commit();
	}

	/* "Active hosts" table */

	function host_query($updated_after=0) {
		$sql = "SELECT
				hosts.*,
				COUNT(DISTINCT utmp.user) AS users,
				COUNT(utmp.user) AS entries
			FROM hosts
			LEFT OUTER JOIN utmp
			ON hosts.host = utmp.host
			WHERE last_update >= :time
			GROUP BY host";
		$st = $this->dbh->prepare($sql);
		$st->bindValue(":time", $updated_after);
		$st->execute();
		return $st->fetchAll(\PDO::FETCH_ASSOC);
	}

	function host_update($host, $addr) {
		$st = $this->dbh->prepare("
			INSERT INTO hosts (host, last_update, last_addr)
			VALUES (:host, :time, :addr)
			ON DUPLICATE KEY UPDATE last_update=:xtime, last_addr=:xaddr
		");
		$time = time();
		$st->bindValue(":host", $host);
		$st->bindValue(":time", $time);
		$st->bindValue(":addr", $addr);
		$st->bindValue(":xtime", $time);
		$st->bindValue(":xaddr", $addr);
		$st->execute();
	}

	function host_delete($host) {
		$st = $this->dbh->prepare("DELETE FROM hosts WHERE host=:host");
		$st->bindValue(":host", $host);
		$st->execute();
	}

	function host_count($minimum_ts=0) {
		$sql = "SELECT COUNT(host)
			FROM hosts
			WHERE last_update >= :time";
		$st = $this->dbh->prepare($sql);
		$st->bindValue(":time", $minimum_ts);
		$st->execute();
		return $st->fetchColumn(0);
	}

	/* "UTMP entries" aka "Logged in users" table */

	function utmp_query($user, $host, $minimum_ts=0) {
		$sql = "SELECT * FROM utmp
			WHERE updated >= :time";
		if (strlen($user)) {
			$sql .= " AND user = :user";
		}
		if (strlen($host)) {
			$sql .= " AND :host IN (host, SUBSTRING_INDEX(host, '.', 1))";
		}
		$st = $this->dbh->prepare($sql);
		$st->bindValue(":time", $minimum_ts);
		if (strlen($user)) {
			$st->bindValue(":user", $user);
		}
		if (strlen($host)) {
			$st->bindValue(":host", $host);
		}
		$st->execute();
		return $st->fetchAll(\PDO::FETCH_ASSOC);
	}

	function utmp_insert_one($host, $entry) {
		$st = $this->dbh->prepare("
			INSERT INTO utmp (host, user, raw_user, uid, rhost, line, time, updated)
			VALUES (:host, :user, :raw_user, :uid, :rhost, :line, :time, :updated)
		");
		$st->bindValue(":host", $host);
		$st->bindValue(":user", $entry["user"]);
		$st->bindValue(":raw_user", $entry["raw_user"]);
		$st->bindValue(":uid", $entry["uid"]);
		$st->bindValue(":rhost", $entry["host"]);
		$st->bindValue(":line", $entry["line"]);
		$st->bindValue(":time", $entry["time"]);
		$st->bindValue(":updated", time());
		$st->execute();
	}

	function utmp_delete_one($host, $entry) {
		$st = $this->dbh->prepare("
			DELETE FROM utmp
			WHERE host=:host AND raw_user=:raw_user AND line=:line
		");
		$st->bindValue(":host", $host);
		$st->bindValue(":raw_user", $entry["raw_user"]);
		$st->bindValue(":line", $entry["line"]);
		$st->execute();
	}

	function utmp_delete_all($host) {
		$st = $this->dbh->prepare("DELETE FROM utmp WHERE host=:host");
		$st->bindValue(":host", $host);
		$st->execute();
	}

	function utmp_count($group_users, $minimum_ts=0) {
		if ($group_users) {
			$sql = "SELECT COUNT(DISTINCT user)
				FROM utmp
				WHERE updated >= :time";
		} else {
			$sql = "SELECT COUNT(user)
				FROM utmp
				WHERE updated >= :time";
		}
		$st = $this->dbh->prepare($sql);
		$st->bindValue(":time", $minimum_ts);
		$st->execute();
		return $st->fetchColumn(0);
	}

	/* Maintenance functions dealing with both tables */

	function delete_old($before) {
		$st = $this->dbh->prepare("DELETE FROM hosts WHERE last_update<:before");
		$st->bindValue(":before", $before);
		$st->execute();
		$hc = $st->rowCount();

		$st = $this->dbh->prepare("DELETE FROM utmp WHERE updated<:before");
		$st->bindValue(":before", $before);
		$st->execute();
		$uc = $st->rowCount();

		return [$hc, $uc];
	}
}
