<?php
namespace RWho;
error_reporting(E_ALL);
require_once(__DIR__."/../lib-php/librwho.php");
require_once(__DIR__."/application.inc.php");

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

$app = new \RWho\Web\RWhoWebApp();
