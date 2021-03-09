<?php
namespace RWho;

require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/database.php");
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

class RWhoServer {
	function __construct() {
		$this->config = new \RWho\Config\Configuration();
		$this->config->load(__DIR__."/../server.conf");

		$db = new \RWho\Database($this->config);
		$this->dbh = $db->dbh;
	}

	function get_host_password($host) {
		return $this->config->get("auth.pw.$host");
	}

	function get_host_kod($host) {
		return $this->config->get("auth.kod.$host",
			$this->config->get("auth.kod.all"));
	}

	function host_update($host) {
		$st = $this->dbh->prepare('
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
		$st = $this->dbh->prepare('DELETE FROM hosts WHERE host=:host');
		if (!$st)
			pdo_die($dbh);
		$st->bindValue(":host", $host);
		if (!$st->execute())
			pdo_die($st);
	}

	// User session information

	function utmp_insert($host, $entry) {
		$user = canonicalize_user($entry["user"], $entry["uid"], $entry["host"]);
		$st = $this->dbh->prepare('
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
		$st = $this->dbh->prepare('DELETE FROM utmp
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
		$st = $this->dbh->prepare('DELETE FROM utmp
			     	     WHERE host=:host');
		if (!$st)
			pdo_die($dbh);
		$st->bindValue(":host", $host);
		if (!$st->execute())
			pdo_die($st);
	}
}

// API actions

class RWhoApiInterface {
	private $config;
	private $auth_id;

	function __construct($server, $auth_id) {
		$this->server = $server;
		$this->config = $server->config;
		$this->auth_id = $auth_id;
	}

	function _authorize($host) {
		$auth_id = $this->auth_id;
		$auth_required = $this->config->get_bool("server.auth_required", false);

		// Check by host, not auth_id, to match anonymous clients as well.
		$kod_msg = $this->server->get_host_kod($host);
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
		$this->server->host_update($host);
		$this->server->utmp_delete_host($host);
		foreach ($entries as $entry) {
			$this->server->utmp_insert($host, $entry);
		}
	}

	function InsertEntries($host, $entries) {
		$this->_authorize($host);
		$this->server->host_update($host);
		foreach ($entries as $entry) {
			$this->server->utmp_insert($host, $entry);
		}
	}

	function RemoveEntries($host, $entries) {
		$this->_authorize($host);
		$this->server->host_update($host);
		foreach ($entries as $entry) {
			$this->server->utmp_delete($host, $entry);
		}
	}

	function ClearEntries($host) {
		$this->_authorize($host);
		$this->server->host_delete($host);
		$this->server->utmp_delete_host($host);
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
