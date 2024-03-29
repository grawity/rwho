<?php
namespace RWho\Server;
require_once(__DIR__."/../lib-php/client.php");
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/database.php");
require_once(__DIR__."/../lib-php/json_rpc.php");

openlog("rwho-server", null, LOG_DAEMON);

function xsyslog($level, $message) {
	$message = "[".$_SERVER["REMOTE_ADDR"]."] $message";
	return syslog($level, $message);
}

class RWhoApiInterface {
	private $config;
	private $environ;

	function __construct($config, $environ) {
		$this->config = $config;
		$this->environ = $environ;
		$this->db = new \RWho\Database($this->config);
		$this->client = new \RWho\Client($this->config, $this->db);
	}

	// Accept any non-anonymous client, e.g. via mod_auth_gssapi. This
	// gives access to Get*() calls that reveal network-sensitive information.
	function _authorize_any($what) {
		$auth_id = $this->environ["REMOTE_USER"];
		if (!$auth_id) {
			// No authentication
			throw new UnauthorizedClientError($what);
		} else {
			xsyslog(LOG_DEBUG, "Allowing client '$auth_id' to call $what");
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
			xsyslog(LOG_DEBUG, "Kerberos identity '$principal' is not a host principal");
			return false;
		}
		$khost = $m[1];
		$realm = $m[2];
		if (!$this->_is_trusted_realm($realm)) {
			xsyslog(LOG_DEBUG, "Kerberos identity '$principal' is from an untrusted realm");
			return false;
		}
		// Check exact FQDN match only, no "short <=> fqdn" mapping (as we don't do it for Basic auth).
		if (strtolower($khost) !== strtolower($host)) {
			xsyslog(LOG_DEBUG, "Kerberos identity '$principal' not authorized for host '$host'");
			return false;
		}
		return true;
	}

	// Permit hosts (via Basic auth) to update their own records.
	function _authorize_host($host) {
		$auth_id = $this->environ["REMOTE_USER"];
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
			if ($auth_id === $host || $this->_match_host_principal($auth_id, $host)) {
				xsyslog(LOG_DEBUG, "Allowing client '$auth_id' to update host '$host'");
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
		$this->_authorize_any("GetHosts");
		return $this->db->host_query();
	}

	function GetEntries($user, $host) {
		$this->_authorize_any("GetEntries");
		return $this->db->utmp_query($user, $host);
	}

	function GetCounts() {
		$this->_authorize_any("GetCounts");
		return ["hosts" => $this->client->count_hosts(),
			"users" => $this->client->count_users(),
			"lines" => $this->client->count_lines()];
	}

	function GetPlanFile($user, $host) {
		$this->_authorize_any("GetPlanFile");
		return $this->client->get_plan_file($user, $host);
	}

	function PurgeOld() {
		$this->_authorize_any("PurgeOld");
		return $this->client->purge_dead();
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
