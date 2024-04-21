<?php
namespace RWho\Server;
require_once(__DIR__."/../lib-php/client.php");
require_once(__DIR__."/../lib-php/client_app.php");
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/database.php");
require_once(__DIR__."/../lib-php/json_rpc.php");

openlog("rwho-server", null, LOG_DAEMON);

function xsyslog($level, $message) {
	$message = "[".$_SERVER["REMOTE_ADDR"]."] $message";
	return syslog($level, $message);
}

class RWhoApiInterface extends \RWho\ClientApplicationBase {
	public $config;
	public $client;

	private $db;
	private $environ;
	private $trusted;

	function __construct($config, $environ) {
		$this->config = $config;
		$this->environ = $environ;
		$this->db = new \RWho\Database($this->config);
		$this->client = new \RWho\Client($this->config, $this->db);
	}

	function log_debug($message) {
		if ($this->config->get_bool("log.debug")) {
			xsyslog(LOG_DEBUG, $message);
		}
	}

	// Accept any non-anonymous client, e.g. via mod_auth_gssapi. This
	// gives access to Get*() calls that reveal network-sensitive information.
	function _authorize_auth($what) {
		$remote_user = $this->environ["REMOTE_USER"];
		$remote_addr = $this->environ["REMOTE_ADDR"];
		if ($remote_user && $this->_is_ruser_trusted($remote_user)) {
			$this->log_debug("Allowing client '$remote_user' to call $what");
			$this->trusted = true;
		} elseif ($remote_addr && $this->_is_rhost_trusted($remote_addr)) {
			$this->log_debug("Allowing client to call $what");
			$this->trusted = true;
		} else {
			throw new UnauthorizedClientError($what);
		}
	}

	// Accept any client including anonymous; additionally return whether
	// it is trusted to see network-sensitive information (IP addresses).
	function _authorize_public($what) {
		$remote_user = $this->environ["REMOTE_USER"];
		$remote_addr = $this->environ["REMOTE_ADDR"];
		if ($remote_user && $this->_is_ruser_trusted($remote_user)) {
			$this->log_debug("Allowing client '$remote_user' to call $what");
			$this->trusted = true;
		} elseif ($remote_addr && $this->_is_rhost_trusted($remote_addr)) {
			$this->log_debug("Allowing client to call $what");
			$this->trusted = true;
		} elseif ($this->_should_deny_anonymous("api")) {
			$this->log_debug("Denying anonymous client to call $what");
			throw new UnauthorizedClientError($what);
		} else {
			$this->log_debug("Allowing untrusted client [$remote_addr] to call $what");
			$this->trusted = !$this->_should_limit_anonymous("api");
		}
	}

	// Check whether a realm is trusted to not issue fake host principals.
	function _is_trusted_realm($realm) {
		$realms = $this->config->get_list("server.auth.local_realms");
		if (count($realms)) {
			return in_array($realm, $realms, true);
		} else {
			// If not defined, trust all realms.
			return true;
		}
	}

	// Check whether a Kerberos host principal is for a specific FQDN.
	function _match_host_principal($principal, $host) {
		if (!preg_match('!^host/([^/@]+)@([^/@]+)$!', $principal, $m)) {
			$this->log_debug("Kerberos identity '$principal' is not a host principal");
			return false;
		}
		$khost = $m[1];
		$realm = $m[2];
		if (!$this->_is_trusted_realm($realm)) {
			$this->log_debug("Kerberos identity '$principal' is from an untrusted realm");
			return false;
		}
		// Check exact FQDN match only, no "short <=> fqdn" mapping (as we don't do it for Basic auth).
		if (strtolower($khost) !== strtolower($host)) {
			$this->log_debug("Kerberos identity '$principal' not authorized for host '$host'");
			return false;
		}
		return true;
	}

	// Permit hosts (via Basic auth) to update their own records.
	function _authorize_host($host) {
		$auth_id = $this->environ["REMOTE_USER"];
		$allow_anonymous = $this->config->get_bool("server.allow_anonymous_updates", false);

		// Check by host, not auth_id, to match anonymous clients as well.
		$kod_msg = $this->config->get("server.kod.$host", $this->config->get("server.kod.all"));
		if ($kod_msg) {
			xsyslog(LOG_NOTICE, "Rejected client with KOD message (\"$kod_msg\")");
			throw new KodResponseError($kod_msg);
		}

		if ($auth_id === null) {
			// No auth header, or account was unknown
			if ($allow_anonymous) {
				$this->log_debug("Allowing anonymous client to update host '$host'");
			} else {
				xsyslog(LOG_WARNING, "Denying anonymous client to update host '$host'");
				throw new UnauthorizedHostError();
			}
		} else {
			// Valid auth for known account
			if ($auth_id === $host || $this->_match_host_principal($auth_id, $host)) {
				$this->log_debug("Allowing client '$auth_id' to update host '$host'");
			} else {
				xsyslog(LOG_WARNING, "Denying client '$auth_id' to update host '$host' (FQDN mismatch)");
				throw new UnauthorizedHostError();
			}
		}
	}

	function _host_update($host) {
		$this->db->host_update($host, $this->environ["REMOTE_ADDR"]);
	}

	function _utmp_canon_entry($entry) {
		// Strip off the SSSD "@domain" suffix. This allows the same
		// username in multiple SSSD domains to be summarized under a
		// single section.
		$entry["raw_user"] = $entry["user"];
		$entry["user"] = explode("@", $entry["user"])[0];
		return $entry;
	}

	function _insert_entries($host, $entries) {
		foreach ($entries as $entry) {
			$entry = $this->_utmp_canon_entry($entry);
			$this->db->utmp_insert_one($host, $entry);
		}
	}

	function _remove_entries($host, $entries) {
		foreach ($entries as $entry) {
			$entry = $this->_utmp_canon_entry($entry);
			$this->db->utmp_delete_one($host, $entry);
		}
	}

	/* Common API */

	function WhoAmI() {
		return $this->environ["REMOTE_USER"];
	}

	/* Client API */

	function GetHosts() {
		$this->_authorize_public("GetHosts");
		return $this->client->retrieve_hosts(true);
	}

	function GetEntries($user, $host) {
		$this->_authorize_public("GetEntries");
		return $this->client->retrieve($user, $host, !$this->trusted);
	}

	function GetCounts() {
		$this->_authorize_public("GetCounts");
		return ["hosts" => $this->client->count_hosts(),
			"users" => $this->client->count_users(),
			"lines" => $this->client->count_lines()];
	}

	function GetPlanFile($user, $host) {
		$this->_authorize_public("GetPlanFile");
		return $this->client->get_plan_file($user, $host);
	}

	function SetPlanFile($user, $host, $text) {
		$this->_authorize_auth("SetPlanFile");
		return $this->client->set_plan_file($user, $host, $text);
	}

	function PurgeOld() {
		$this->_authorize_auth("PurgeOld");
		list ($hrows, $urows) = $this->client->purge_dead();
		xsyslog(LOG_INFO, "Purged $hrows hosts and $urows utmp entries.");
		return [$hrows, $urows];
	}

	/* Host API */

	function PutHostEntries($host, $entries) {
		$this->_authorize_host($host);
		$this->db->begin();
		$this->_host_update($host);
		$this->db->utmp_delete_all($host);
		$this->_insert_entries($host, $entries);
		$this->db->commit();
	}

	function InsertHostEntries($host, $entries) {
		$this->_authorize_host($host);
		$this->db->begin();
		$this->_host_update($host);
		$this->_insert_entries($host, $entries);
		$this->db->commit();
	}

	function RemoveHostEntries($host, $entries) {
		$this->_authorize_host($host);
		$this->db->begin();
		$this->_host_update($host);
		$this->_remove_entries($host, $entries);
		$this->db->commit();
	}

	function ClearHostEntries($host) {
		$this->_authorize_host($host);
		$this->db->begin();
		$this->db->host_delete($host);
		$this->db->utmp_delete_all($host);
		$this->db->commit();
	}
}

class UnauthorizedClientError extends \JsonRpc\RpcException {
	function __construct($what) {
		$this->code = 403;
		$this->message = "Client not authorized to call $what()";
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
