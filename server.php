<?php
namespace RWho;

header("Content-Type: text/plain; charset=utf-8");

require __DIR__."/config.php";
openlog("rwho-server", null, LOG_DAEMON);

class DB {
	static $dbh;
	static function connect() {
		if (!isset(self::$dbh)) {
			self::$dbh = new \PDO(DB_PATH, DB_USER, DB_PASS);
		}
		return self::$dbh;
	}
}

// Host information

function host_update($host) {
	$dbh = DB::connect();
	$st = $dbh->prepare('
		INSERT INTO hosts (host, last_update, last_addr)
		VALUES (:host, :time, :addr)
		ON DUPLICATE KEY UPDATE last_update=:time, last_addr=:addr
		');
	$st->bindValue(":host", $host);
	$st->bindValue(":time", time());
	$st->bindValue(":addr", $_SERVER["REMOTE_ADDR"]);
	return $st->execute();
}

function host_delete($host) {
	$dbh = DB::connect();
	$st = $dbh->prepare('DELETE FROM hosts WHERE host=:host');
	$st->bindValue(":host", $host);
	return $st->execute();
}

// User session information

function utmp_insert($host, $entry) {
	$dbh = DB::connect();
	$st = $dbh->prepare('
		INSERT INTO utmp (host, user, uid, rhost, line, time, updated)
		VALUES (:host, :user, :uid, :rhost, :line, :time, :updated)
		');
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $entry->user);
	$st->bindValue(":uid", $entry->uid);
	$st->bindValue(":rhost", $entry->host);
	$st->bindValue(":line", $entry->line);
	$st->bindValue(":time", $entry->time);
	$st->bindValue(":updated", time());
	return $st->execute();
}

function utmp_delete($host, $entry) {
	$dbh = DB::connect();
	$st = $dbh->prepare('DELETE FROM utmp WHERE host=:host AND user=:user AND line=:line');
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $entry->user);
	$st->bindValue(":line", $entry->line);
	return $st->execute();
}

function utmp_delete_host($host) {
	$dbh = DB::connect();
	$st = $dbh->prepare('DELETE FROM utmp WHERE host=:host');
	$st->bindValue(":host", $host);
	return $st->execute();
}

// API actions

if (strlen($_POST["fqdn"]))
	$host = $_POST["fqdn"];
elseif (strlen($_POST["host"]))
	$host = $_POST["host"];
else
	die("error: host not specified\n");

if (isset($_REQUEST["action"]))
	$action = $_REQUEST["action"];
else
	die("error: action not specified\n");

switch ($action) {
	case "insert":
		$data = json_decode($_POST["utmp"]);
		if (!$data) {
			die("error: no data\n");
		}
		host_update($host);
		foreach ($data as $entry) {
			utmp_insert($host, $entry);
		}
		print "OK\n";
		break;

	case "delete":
		$data = json_decode($_POST["utmp"]);
		if (!$data) {
			die("error: no data\n");
		}
		host_update($host);
		foreach ($data as $entry) {
			utmp_delete($host, $entry);
		}
		print "OK\n";
		break;

	case "put":
		$data = json_decode($_POST["utmp"]);
		// allow zero-length array
		if ($data === false) {
			die("error: no data\n");
		}
		host_update($host);
		utmp_delete_host($host);
		foreach ($data as $entry) {
			utmp_insert($host, $entry);
		}
		print "OK\n";
		break;

	case "destroy":
		host_delete($host);
		utmp_delete_host($host);
		print "OK\n";
		break;

	default:
		print "error: unknown action\n";
}
