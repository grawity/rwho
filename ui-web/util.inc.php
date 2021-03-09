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

$config = new \RWho\Config\Configuration();
// Load database information from server.conf
$config->load(__DIR__."/../server.conf");
$config->load(__DIR__."/../rwho.conf");

// XXX: Until everything is migrated over, load the settings into the legacy
// global object as well.
Config::$conf->merge($config->_data);
