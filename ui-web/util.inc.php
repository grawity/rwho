<?php
namespace RWho;

class html {
	static $columns = 0;
	static $title = "rwho";
	static $refresh = 0;

	static function header($title, $width=0) {
		if ($width)
			echo "\t<th style=\"min-width: {$width}ex\">$title</th>\n";
		else
			echo "\t<th>$title</th>\n";

		self::$columns++;
	}
}

function H($str) { return htmlspecialchars($str); }

function make_finger_addr() {
	if (!Config::has("finger.host"))
		return null;
	$q = (string) query::$user;
	if (strlen(query::$host))
		$q .= "@".query::$host;
	$q .= "@".Config::get("finger.host");
	if (query::$detailed and !(strlen(query::$user) or strlen(query::$host)))
		$q = "/W ".$q;
	return "//nullroute.eu.org/finger/?q=".urlencode($q);
}

function is_trusted_ip() {
	$rhost = get_rhost();
	foreach (Config::getlist("privacy.allow_addr") as $addr) {
		if (ip_cidr($rhost, $addr))
			return true;
	}
	return false;
}

function require_auth() {
	$auth = Config::get("web.auth_method", "none");
	if (!$auth || $auth === "none") {
		return;
	}
	elseif ($auth === "saml") {
		$ssp_path = Config::get("web.ssp_autoload", "/usr/share/simplesamlphp/lib/_autoload.php");
		$ssp_authsource = Config::get("web.ssp_authsource", "default-sp");

		require_once($ssp_path);
		$as = new \SimpleSAML_Auth_Simple($ssp_authsource);
		$as->requireAuth();
	}
	else {
		die("error: unknown auth_method '$auth'\n");
	}
}
