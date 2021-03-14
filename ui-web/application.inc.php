<?php
namespace RWho\Web;
require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/../lib-php/client_app.php");

class RWhoWebApp extends \RWho\ClientApplicationBase {
	function should_filter() {
		$anon = true;
		$rhost = \RWho\get_rhost();
		foreach ($this->config->get_list("privacy.trusted_nets") as $addr)
			if (\RWho\ip_cidr($rhost, $addr)) {
				$anon = false;
				break;
			}

		if ($anon && $this->config->get_bool("privacy.hide_rhost", false))
			return true;
		return false;
	}

	function make_finger_addr($user, $host, $detailed) {
		$user ??= "";
		$host ??= "";

		$host = $this->config->get("web.finger.host", null);
		$gateway = $this->config->get("web.finger.gateway", "//nullroute.eu.org/finger/?q=%s");
		if (empty($host) || empty($gateway))
			return null;

		if (strlen($host)) {
			$q = $user."@".$host;
		} else {
			$q = $user;
		}
		if ($detailed && !(strlen($user) || strlen($host))) {
			$q = "/W ".$q;
		}
		return sprintf($gateway, urlencode($q));
	}
}
