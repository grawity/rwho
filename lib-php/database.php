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

	/* "Active hosts" table */

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

	/* "UTMP entries" aka "Logged in users" table */

	function utmp_insert_one($host, $entry) {
		$st = $this->dbh->prepare("
			INSERT INTO utmp (host, user, rawuser, uid, rhost, line, time, updated)
			VALUES (:host, :user, :rawuser, :uid, :rhost, :line, :time, :updated)
		");
		$st->bindValue(":host", $host);
		$st->bindValue(":user", $entry["user"]);
		$st->bindValue(":rawuser", $entry["rawuser"]);
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
			WHERE host=:host AND rawuser=:rawuser AND line=:line
		");
		$st->bindValue(":host", $host);
		$st->bindValue(":rawuser", $entry["rawuser"]);
		$st->bindValue(":line", $entry["line"]);
		$st->execute();
	}

	function utmp_delete_all($host) {
		$st = $this->dbh->prepare("DELETE FROM utmp WHERE host=:host");
		$st->bindValue(":host", $host);
		$st->execute();
	}
}
