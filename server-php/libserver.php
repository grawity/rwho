<?php
namespace RWho;

require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/../lib-php/json_rpc.php");

Config::parse(__DIR__."/../server.conf");

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

function get_host_kodmsg($host) {
	return Config::get("auth.kod.$host", Config::get("auth.kod.all"));
}

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
	$user = canonicalize_user($entry["user"], $entry["uid"], $entry["host"]);
	$dbh = DB::connect();
	$st = $dbh->prepare('
		INSERT INTO utmp (host, user, rawuser, uid, rhost, line, time, updated)
		VALUES (:host, :user, :rawuser, :uid, :rhost, :line, :time, :updated)
		');
	if (!$st)
		pdo_die($dbh);
	$st->bindValue(":host", $host);
	$st->bindValue(":user", $user);
	$st->bindValue(":rawuser", $entry["user"]);
	$st->bindValue(":uid", $entry["uid"]);
	$st->bindValue(":rhost", $entry["host"]);
	$st->bindValue(":line", $entry["line"]);
	$st->bindValue(":time", $entry["time"]);
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
	$st->bindValue(":user", $entry["user"]);
	$st->bindValue(":line", $entry["line"]);
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
	private $auth_id;

	function __construct($auth_id, $auth_required) {
		$this->auth_id = $auth_id;
		$this->auth_required = $auth_required;
	}

	function _authorize($host) {
		$auth_id = $this->auth_id;
		$auth_required = $this->auth_required;

		// Check by host, not auth_id, to match anonymous clients as well.
		$kod_msg = get_host_kodmsg($host);
		if ($kod_msg) {
			xsyslog(LOG_NOTICE, "Rejected client with KOD message (\"$kod_msg\")");
			throw new KodResponseError($kod_msg);
		}

		if ($auth_id === null) {
			// No auth header, or account was unknown
			if (!$auth_required) {
				xsyslog(LOG_DEBUG, "Allowing anonymous client to update host '$host'");
			} else {
				// XXX: This can't happen because check_authn() already exits in this case.
				xsyslog(LOG_WARNING, "Denying anonymous client to update host '$host'");
				throw new UnauthorizedHostError();
			}
		} else {
			// Valid auth for known account
			if ($auth_id === $host) {
				xsyslog(LOG_DEBUG, "Allowing client '$auth_id' to update host '$host'");
			} else {
				xsyslog(LOG_WARNING, "Denying client '$auth_id' to update host '$host' (FQDN mismatch)");
				throw new UnauthorizedHostError();
			}
		}
	}

	function PutEntries($host, $entries) {
		$this->_authorize($host);
		host_update($host);
		utmp_delete_host($host);
		foreach ($entries as $entry) {
			utmp_insert($host, $entry);
		}
	}

	function InsertEntries($host, $entries) {
		$this->_authorize($host);
		host_update($host);
		foreach ($entries as $entry) {
			utmp_insert($host, $entry);
		}
	}

	function RemoveEntries($host, $entries) {
		$this->_authorize($host);
		host_update($host);
		foreach ($entries as $entry) {
			utmp_delete($host, $entry);
		}
	}

	function ClearEntries($host) {
		$this->_authorize($host);
		host_delete($host);
		utmp_delete_host($host);
	}
}

class UnauthorizedHostError extends \JsonRpc\RpcException {
	function __construct() {
		$this->code = 403;
		$this->message = "Client not authorized to update this host";
	}
}

class KodResponseError extends \JsonRpc\RpcException {
	function __construct($message) {
		$this->code = 410;
		$this->message = "$message";
	}
}
