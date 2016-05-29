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

class auth {
	static $method;
	static $ssp_as;

	static function init() {
		if (self::$method)
			return;

		self::$method = Config::get("web.auth_method", "none");

		if (self::$method === "saml" && !self::$ssp_as) {
			$ssp_autoload = Config::get("web.ssp_autoload", "/usr/share/simplesamlphp/lib/_autoload.php");
			$ssp_authsource = Config::get("web.ssp_authsource", "default-sp");

			require_once($ssp_autoload);
			self::$ssp_as = new \SimpleSAML_Auth_Simple($ssp_authsource);
		}
	}

	static function login() {
		self::init();

		if (self::$method === "none")
			return;
		elseif (self::$method === "saml")
			self::$ssp_as->requireAuth();
		else
			die("error: unknown authentication method '".self::$method."'\n");
	}

	static function logout() {
		self::init();

		$return = mangle(array(), array("logout"));
		die("going back to $return\n");

		if (self::$method === "saml")
			self::$ssp_as->logout($return);
		else {
			header("Location: $return");
			die("Redirecting to $return\n");
		}
	}

	static function is_logged_in() {
		self::init();

		if (self::$method === "none")
			return true;
		elseif (self::$method === "saml")
			return self::$ssp_as->isAuthenticated();
		else
			return false;
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

function is_authenticated() {
	return auth::is_logged_in();
}

function require_auth($strict=false) {
	if ($_GET["logout"]) {
		auth::logout();
		die("error: should not reach\n");
	}

	if (!$strict) {
		if (is_trusted_ip())
			return;
		if (Config::get("privacy.allow_anonymous"))
			return;
	}

	auth::login();
}
