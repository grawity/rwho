<?php
header("Content-Type: text/plain; charset=utf-8");

const DB_PATH = "/home/grawity/lib/cgi-data/rwho.db";

function ut_insert($db, $host, $entry) {
	list ($user, $rhost, $line, $time) = $entry;
	$st = $db->prepare('
		INSERT INTO `utmp` (host, user, rhost, line, time, updated)
		VALUES (:host, :user, :rhost, :line, :time, :updated)');
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $user);
	$st->bindValue(":rhost", $rhost);
	$st->bindValue(":line", $line);
	$st->bindValue(":time", $time);
	$st->bindValue(":updated", time());
	return $st->execute();
}

function ut_delete($db, $host, $entry) {
	list ($user, $rhost, $line, $time) = $entry;
	$st = $db->prepare('DELETE FROM `utmp` WHERE host=:host AND user=:user AND line=:line');
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $user);
	$st->bindValue(":line", $line);
	$st->bindValue(":time", $time);
	return $st->execute();
}

function ut_delete_host($db, $host) {
	$st = $db->prepare('DELETE FROM utmp WHERE host=:host');
	$st->bindValue(":host", $host);
	return $st->execute();
}

$actions = array(
	"query" => function() {
		$db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);

		$res = $db->query('select * from `utmp`');

		$utmp = array();
		while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
			$utmp[] = $row;
		}
		print json_encode($utmp);
	},

	"insert" => function() {
		$host = $_POST["host"];
		if (!strlen($host)) {
			print "error: host not specified\n";
			return false;
		}
		$data = json_decode($_POST["utmp"]);
		if (!$data) {
			print "error: no data\n";
			return false;
		}

		$db = new SQLite3(DB_PATH);
		foreach ($data as $entry)
			ut_insert($db, $host, $entry);
		print "OK\n";
	},

	"delete" => function() {
		$host = $_POST["host"];
		if (!strlen($host)) {
			print "error: host not specified\n";
			return false;
		}
		$data = json_decode($_POST["utmp"]);
		if (!$data) {
			print "error: no data\n";
			return false;
		}

		$db = new SQLite3(DB_PATH);
		foreach ($data as $entry)
			ut_delete($db, $host, $entry);
		print "OK\n";
	},

	"put" => function() {
		$host = $_POST["host"];
		if (!strlen($host)) {
			print "error: host not specified\n";
			return false;
		}
		$data = json_decode($_POST["utmp"]);
		if (!$data) {
			print "error: no data\n";
			return false;
		}

		$db = new SQLite3(DB_PATH);
		ut_delete_host($db, $host);
		foreach ($data as $entry)
			ut_insert($db, $host, $entry);
		print "OK\n";
	},

	"destroy" => function() {
		$host = $_POST["host"];
		if (!strlen($host)) {
			print "error: host not specified\n";
			return false;
		}

		$db = new SQLite3(DB_PATH);
		ut_delete_host($db, $host);
		print "OK\n";
	},

	null => function() {
		print "Unknown action.\n";
	},
);

$action = isset($_REQUEST["action"])
	? $_REQUEST["action"]
	: "query";

$handler = isset($actions[$action])
	? $actions[$action]
	: $actions[null];

$handler();
