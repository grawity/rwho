<?php
namespace RWho\Config;

class ConfigurationSyntaxError extends \Exception {
	function __construct($file, $line, $text) {
		$this->message = "Syntax error at $file:$line: '$text'";
	}
}

function parse_file($file) {
	$data = [];
	$fh = fopen($file, "r");
	if ($fh) {
		$section = "";
		for ($i = 1; ($line = fgets($fh)) !== false; $i++) {
			$line = rtrim($line);
			if (!preg_match('/^[^;#]/', $line)) {
				continue;
			}
			elseif (preg_match('/^\[(\S+)\]$/', $line, $m)) {
				list ($_, $key) = $m;
				$section = "$key.";
			}
			elseif (preg_match('/^(\S+)\s*=\s*(.*)$/', $line, $m)) {
				list ($_, $key, $val) = $m;
				$data[$section.$key] = $val;
			}
			else {
				throw new ConfigurationSyntaxError($file, $i, $line);
			}
		}
		fclose($fh);
	}
	return $data;
}
