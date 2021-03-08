<?php
namespace RWho;

openlog("rwho-server", null, LOG_DAEMON);

function pdo_fmterr($st) {
	list ($sqlstate, $code, $msg) = $st->errorInfo();
	if ($code === null)
		return "[$sqlstate] internal PDO error";
	else
		return "[$sqlstate] $msg ($code)";
}

function pdo_die($st) {
	$err = pdo_fmterr($st);
	header("Status: 500");
	die("error: $err\n");
}

// Host information

function host_update($host) {
	$dbh = DB::connect();
	$st = $dbh->prepare('
		INSERT INTO hosts (host, last_update, last_addr)
		VALUES (:host, :time, :addr)
		ON DUPLICATE KEY UPDATE last_update=:xtime, last_addr=:xaddr
		');
	if (!$st)
		pdo_die($dbh);
	$time = time();
	$addr = $_SERVER["REMOTE_ADDR"];
	$st->bindValue(":host", $host);
	$st->bindValue(":time", $time);
	$st->bindValue(":addr", $addr);
	$st->bindValue(":xtime", $time);
	$st->bindValue(":xaddr", $addr);
	if (!$st->execute())
		pdo_die($st);
}

function host_delete($host) {
	$dbh = DB::connect();
	$st = $dbh->prepare('DELETE FROM hosts WHERE host=:host');
	if (!$st)
		pdo_die($dbh);
	$st->bindValue(":host", $host);
	if (!$st->execute())
		pdo_die($st);
}

// User session information

function utmp_insert($host, $entry) {
	$user = canonicalize_user($entry->user, $entry->uid, $entry->host);
	$dbh = DB::connect();
	$st = $dbh->prepare('
		INSERT INTO utmp (host, user, rawuser, uid, rhost, line, time, updated)
		VALUES (:host, :user, :rawuser, :uid, :rhost, :line, :time, :updated)
		');
	if (!$st)
		pdo_die($dbh);
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $user);
	$st->bindValue(":rawuser", $entry->user);
	$st->bindValue(":uid", $entry->uid);
	$st->bindValue(":rhost", $entry->host);
	$st->bindValue(":line", $entry->line);
	$st->bindValue(":time", $entry->time);
	$st->bindValue(":updated", time());
	if (!$st->execute())
		pdo_die($st);
}

function utmp_delete($host, $entry) {
	$dbh = DB::connect();
	$st = $dbh->prepare('DELETE FROM utmp
			     WHERE host=:host
			     AND rawuser=:user
			     AND line=:line');
	if (!$st)
		pdo_die($dbh);
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $entry->user);
	$st->bindValue(":line", $entry->line);
	if (!$st->execute())
		pdo_die($st);
}

function utmp_delete_host($host) {
	$dbh = DB::connect();
	$st = $dbh->prepare('DELETE FROM utmp
			     WHERE host=:host');
	if (!$st)
		pdo_die($dbh);
	$st->bindValue(":host", $host);
	if (!$st->execute())
		pdo_die($st);
}

// API actions

class RWhoServer {
	function PutEntries($host, $entries) {
		host_update($host);
		utmp_delete_host($host);
		foreach ($entries as $entry) {
			utmp_insert($host, $entry);
		}
	}

	function InsertEntries($host, $entries) {
		host_update($host);
		foreach ($entries as $entry) {
			utmp_insert($host, $entry);
		}
	}

	function RemoveEntries($host, $entries) {
		host_update($host);
		foreach ($entries as $entry) {
			utmp_delete($host, $entry);
		}
	}

	function ClearEntries($host) {
		host_delete($host);
		utmp_delete_host($host);
	}
}
