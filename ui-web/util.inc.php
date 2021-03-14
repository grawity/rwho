<?php
namespace RWho;

require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/client.php");

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
$client = new \RWho\Client($config);
