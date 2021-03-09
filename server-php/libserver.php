<?php
namespace RWho;

require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/database.php");
require_once(__DIR__."/../lib-php/json_rpc.php");

Config::parse(__DIR__."/../server.conf");

openlog("rwho-server", null, LOG_DAEMON);

function xsyslog($level, $message) {
	$message = "[".$_SERVER["REMOTE_ADDR"]."] $message";
	return syslog($level, $message);
}

// Host information

class RWhoServer {
	function __construct() {
		$this->config = new \RWho\Config\Configuration();
		$this->config->load(__DIR__."/../server.conf");

		$this->db = new \RWho\Database($this->config);
		$this->dbh = $this->db->dbh;
	}

	function host_update($host) {
		$addr = $_SERVER["REMOTE_ADDR"];
		$this->db->host_update($host, $addr);
	}

	function host_delete($host) {
		$this->db->host_delete($host);
	}

	// User session information

	function utmp_insert($host, $entry) {
		$user = canonicalize_user($entry["user"], $entry["uid"], $entry["host"]);
		$this->db->utmp_insert_one($host, $user, $entry);
	}

	function utmp_delete($host, $entry) {
		$this->db->utmp_delete_one($host, $entry);
	}

	function utmp_delete_host($host) {
		$this->db->utmp_delete_all($host);
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
		$kod_msg = $this->config->get("auth.kod.$host", $this->config->get("auth.kod.all"));
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
