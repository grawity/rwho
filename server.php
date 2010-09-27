<?php
header("Content-Type: text/plain; charset=utf-8");

const DB_PATH = "/home/grawity/lib/cgi-data/rwho.db";

// to help with inotifywait-monitoring the db
function touch_timestamp() {
	$fh = fopen(DB_PATH.".updated", "w");
	fwrite($fh, time()."\n");
	fclose($fh);
}

function ut_insert($db, $host, $entry) {
	$st = $db->prepare('INSERT INTO `utmp` VALUES (:host, :user, :uid, :rhost, :line, :protocol, :time, :updated)');
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

		$db = new SQLite3(DB_PATH);
		foreach ($data as $entry)
			ut_insert($db, $host, $entry);
		touch_timestamp();
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
		touch_timestamp();
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

		$db = new SQLite3(DB_PATH);
		ut_delete_host($db, $host);
		foreach ($data as $entry)
			ut_insert($db, $host, $entry);
		touch_timestamp();
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
		touch_timestamp();
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
