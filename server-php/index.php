<?php
namespace RWho;

header("Content-Type: text/plain; charset=utf-8");

require(__DIR__."/../lib-php/librwho.php");

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

function get_host_pwent($host) {
	Config::parse(__DIR__."/../accounts.conf");
	return Config::get("auth.pw.$host");
}

function get_host_kodmsg($host) {
	Config::parse(__DIR__."/../accounts.conf");
	return Config::get("auth.kod.$host", Config::get("auth.kod.all"));
}

function check_authorization($host) {
	$client_ip = @$_SERVER["REMOTE_ADDR"];
	$client_name = "host '$host' from $client_ip";

	$kod_msg = get_host_kodmsg($host);
	if ($kod_msg) {
		syslog(LOG_NOTICE, "$client_name rejected (KOD enabled: \"$kod_msg\")");
		die("KOD: $kod_msg\n");
	}

	$auth_id = @$_SERVER["PHP_AUTH_USER"];
	$auth_pw = @$_SERVER["PHP_AUTH_PW"];
	$auth_type = @$_SERVER["AUTH_TYPE"];
	$auth_required = Config::getbool("server.auth_required", false);

	if ($auth_id) {
		if ($auth_id === $host) {
			$db_pw = get_host_pwent($host);
			// TODO: add authorization methods without password auth
			// (split authn and authz)
			if ($db_pw) {
				if (password_verify($auth_pw, $db_pw)) {
					syslog(LOG_DEBUG, "$client_name accepted (authenticated)");
					return true;
				} else {
					syslog(LOG_NOTICE, "$client_name rejected (bad password)");
					return false;
				}
			} elseif ($auth_required) {
				syslog(LOG_NOTICE, "$client_name rejected (not found in credential table)");
				return false;
			} else {
				syslog(LOG_DEBUG, "$client_name accepted (authentication provided but not needed)");
				return true;
			}
		} else {
			syslog(LOG_NOTICE, "$client_name auth '$auth_id' rejected (username mismatch)");
			return false;
		}
	} else {
		$db_pw = get_host_pwent($host);
		if ($db_pw || $auth_required) {
			syslog(LOG_NOTICE, "$client_name rejected (authentication required but missing)");
			return false;
		} else {
			syslog(LOG_DEBUG, "$client_name accepted (without authentication)");
			return true;
		}
	}

	if ($db_pw) {
	} else {
		if (!$auth_id) {
		}
	}

	syslog(LOG_NOTICE, "$client_name accepted (authentication not implemented)");
	return true;
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

function handle_legacy_request() {
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

	if (!check_authorization($host)) {
		header("Status: 401");
		header("WWW-Authenticate: Basic realm=\"rwho\"");
		die("error: not authorized\n");
	}

	$server = new RWhoServer();

	switch ($action) {
		case "insert":
			$data = json_decode($_POST["utmp"]);
			if (!is_array($data)) {
				die("error: no data\n");
			}
			$server->InsertEntries($host, $data);
			print "OK\n";
			break;

		case "delete":
			$data = json_decode($_POST["utmp"]);
			if (!is_array($data)) {
				die("error: no data\n");
			}
			$server->RemoveEntries($host, $data);
			print "OK\n";
			break;

		case "put":
			$data = json_decode($_POST["utmp"]);
			if (!is_array($data)) {
				die("error: no data\n");
			}
			$server->PutEntries($host, $data);
			print "OK\n";
			break;

		case "destroy":
			$server->ClearEntries($host);
			print "OK\n";
			break;

		default:
			print "error: unknown action\n";
	}
}

if (isset($_REQUEST["action"]))
	handle_legacy_request();
