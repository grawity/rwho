<?php
header("Content-Type: text/plain; charset=utf-8");

require __DIR__."/config.inc";

function ut_insert($db, $host, $entry) {
	$st = $db->prepare('INSERT INTO `utmp`
		(host, user, uid, rhost, line, protocol, time, updated)
		VALUES (:host, :user, :uid, :rhost, :line, :protocol, :time, :updated)');
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $entry->user);
	$st->bindValue(":uid", $entry->uid);
	$st->bindValue(":rhost", $entry->host);
	$st->bindValue(":line", $entry->line);
	$st->bindValue(":protocol", $entry->proto);
	$st->bindValue(":time", $entry->time);
	$st->bindValue(":updated", time());
	return $st->execute();
}

function ut_delete($db, $host, $entry) {
	$st = $db->prepare('DELETE FROM `utmp` WHERE host=:host AND user=:user AND line=:line');
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $entry->user);
	$st->bindValue(":line", $entry->line);
	//$st->bindValue(":time", $entry->time);
	return $st->execute();
}

function ut_delete_host($db, $host) {
	$st = $db->prepare('DELETE FROM utmp WHERE host=:host');
	$st->bindValue(":host", $host);
	return $st->execute();
}

$actions = array(
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

		$db = new PDO(DB_PATH, DB_USER, DB_PASS);
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

		$db = new PDO(DB_PATH, DB_USER, DB_PASS);
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
		if ($data === false) {
			print "error: no data\n";
			return false;
		}

		$db = new PDO(DB_PATH, DB_USER, DB_PASS);
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

		$db = new PDO(DB_PATH, DB_USER, DB_PASS);
		ut_delete_host($db, $host);
		print "OK\n";
	},
);

if (isset($_REQUEST["action"])) {
	$action = $_REQUEST["action"];
	if (isset($actions[$action])) {
		$actions[$action]();
	} else {
		die("Unknown action\n");
	}
} else {
	die("Action not specified\n");
}
