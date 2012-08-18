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
