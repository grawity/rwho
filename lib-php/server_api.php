<?php
namespace RWho\Server;
require_once(__DIR__."/../lib-php/client.php");
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/database.php");
require_once(__DIR__."/../lib-php/json_rpc.php");

if (!function_exists("array_is_list")) {
	/* Polyfill from https://wiki.php.net/rfc/is_list */
	function array_is_list(array $array): bool {
		$expect = 0;
		foreach ($array as $k => $_) {
			if ($k !== $expect)
				return false;
			$expect++;
		}
		return true;
	}
}

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
			if ($auth_id === $host) {
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

	function _utmp_canon_entry($host, $entry) {
		if (!is_array($entry) || array_is_list($entry)) {
			throw new MalformedEntryError();
		}

		// Compat with older agents which sent :host instead of :rhost
		if (!isset($entry["rhost"]) && isset($entry["host"])) {
			$entry["rhost"] = $entry["host"];
			unset($entry["host"]);
		}

		if (!isset($entry["user"])
		    || !is_string($entry["user"])
		    || !isset($entry["uid"])
		    || !is_int($entry["uid"])
		    || !isset($entry["rhost"])
		    || !is_string($entry["rhost"])
		    || !isset($entry["line"])
		    || !is_string($entry["line"])
		    || !isset($entry["time"])
		    || !(is_int($entry["time"])
		         || is_float($entry["time"])))
		{
			throw new MalformedEntryError();
		}

		// Always store the authenticated host name in entry.
		$entry["host"] = $host;

		// Strip off the SSSD "@domain" suffix. This allows the same
		// username in multiple SSSD domains to be summarized under a
		// single section.
		$entry["raw_user"] = $entry["user"];
		$entry["user"] = explode("@", $entry["user"])[0];

		return $entry;
	}

	function _insert_entries($host, $entries) {
		foreach ($entries as $entry) {
			$entry = $this->_utmp_canon_entry($host, $entry);
			$this->db->utmp_insert_one($entry);
		}
	}

	function _remove_entries($host, $entries) {
		foreach ($entries as $entry) {
			$entry = $this->_utmp_canon_entry($host, $entry);
			$this->db->utmp_delete_one($entry);
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

class MalformedEntryError extends \JsonRpc\RpcBadParametersError {
	function __construct() {
		parent::__construct();
		$this->message = "Bad utmp entry format";
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
