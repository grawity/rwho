<?php
namespace RWho;
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/client.php");
require_once(__DIR__."/../lib-php/util.php");

const AC_DENIED = 0;	// Deny access completely
const AC_LIMITED = 1;	// Show limited information (no rhost)
const AC_TRUSTED = 2;	// Show all information

class ClientApplicationBase {
	function __construct() {
		$this->config = new \RWho\Config\Configuration();
		$this->config->load(__DIR__."/../server.conf"); // for DB info
		$this->config->load(__DIR__."/../rwho.conf");

		$this->client = new \RWho\Client($this->config);
	}

	function _is_rhost_trusted($host) {
		$nets = $this->config->get_list("privacy.trusted_nets");
		foreach ($nets as $net) {
			if (\RWho\Util\ip_cidr($host, $net))
				return true;
		}
		return false;
	}

	function _is_ruser_trusted($user) {
		// This might check for known bad usernames like 'guest' or 'anonymous'.
		return true;
	}

	function _check_access($rhost, $ruser) {
		$anonymous = true;
		if (!empty($rhost) && $this->_is_rhost_trusted($rhost)) {
			$anonymous = false;
		}
		if (!empty($ruser) && $this->_is_ruser_trusted($ruser)) {
			$anonymous = false;
		}
		if ($anonymous) {
			if ($this->config->get_bool("privacy.deny_anonymous", false))
				return AC_DENIED;
			elseif ($this->config->get_bool("privacy.hide_rhost", false))
				return AC_LIMITED;
			else
				return AC_TRUSTED;
		} else {
			return AC_TRUSTED;
		}
	}
}
