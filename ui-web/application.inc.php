<?php
namespace RWho\Web;
require_once(__DIR__."/../lib-php/client_app.php");

// _build_query(dict<str,str> $items) -> str $query
// Format an assoc array of query items to an HTTP query string
// Unlike the PHP built-in, this version supports items without a value.

function _build_query($items) {
	$query = [];
	foreach ($items as $key => $value) {
		if ($value === null or !strlen($value))
			$query[] = urlencode($key);
		else
			$query[] = urlencode($key)."=".urlencode($value);
	}
	return implode("&", $query);
}

// mangle_query(dict<str,str> $add, list<str> $remove) -> str $query
// Add and remove given items from current query string

function mangle_query($add, $remove=null) {
	parse_str($_SERVER["QUERY_STRING"], $query);

	if ($add !== null)
		foreach ($add as $key => $value)
			$query[$key] = $value;

	if ($remove !== null)
		foreach ($remove as $key)
			unset($query[$key]);

	return "?"._build_query($query);
}

class RWhoWebApp extends \RWho\ClientApplicationBase {
	function should_deny() {
		$addr = @$_SERVER["REMOTE_ADDR"];
		$user = @$_SERVER["REMOTE_USER"];
		$access = $this->_check_access($addr, $user, "web");
		return ($access < \RWho\AC_LIMITED);
	}

	function should_filter() {
		$addr = @$_SERVER["REMOTE_ADDR"];
		$user = @$_SERVER["REMOTE_USER"];
		$access = $this->_check_access($addr, $user, "web");
		return ($access < \RWho\AC_TRUSTED);
	}

	function authorize() {
		if ($this->should_deny()) {
			header("Status: 403");
			die("Anonymous access is not allowed.");
		}
	}

	function make_finger_addr($user, $host, $detailed) {
		$user ??= "";
		$host ??= "";

		$host = $this->config->get("web.finger.host", null);
		$gateway = $this->config->get("web.finger.gateway", "//nullroute.lt/finger/?q=%s");
		if (empty($host) || empty($gateway))
			return null;

		if (strlen($host)) {
			$q = $user."@".$host;
		} else {
			$q = $user;
		}
		if ($detailed) {
			$q = "/W ".$q;
		}
		return sprintf($gateway, urlencode($q));
	}
}
