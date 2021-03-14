<?php
namespace RWho\Config;

class ConfigurationSyntaxError extends \Exception {
	function __construct($file, $line, $text) {
		$this->message = "Syntax error at $file:$line: '$text'";
	}
}

function _parse_config_file($file) {
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

class Configuration {
	function __construct($file=null) {
		$this->_data = [];
		if ($file !== null) {
			$this->load($file);
		}
	}

	function load($file) {
		$this->_data = array_merge($this->_data, _parse_config_file($file));
	}

	function set($key, $value) {
		$this->_data[$key] = $value;
	}

	function merge($data) {
		$this->_data = array_merge($this->_data, $data);
	}

	function get($key, $default=null) {
		if (!array_key_exists($key, $this->_data))
			return $default;
		return $this->_data[$key];
	}

	function get_bool($key, $default=false) {
		if (!array_key_exists($key, $this->_data))
			return $default;
		$value = $this->_data[$key];
		if ($value === "true" || $value === "yes")
			return true;
		elseif ($value === "false" || $value === "no")
			return false;
		else
			return (bool) $value;
	}

	function get_list($key) {
		if (!array_key_exists($key, $this->_data))
			return [];
		$value = $this->_data[$key];
		return preg_split('/,|\s/', $value, 0, PREG_SPLIT_NO_EMPTY);
	}

	function get_rel_time($key, $default=0) {
		if (!array_key_exists($key, $this->_data))
			return $default;
		$re = '/^
			(?:(?<w>\d+)w)?
			(?:(?<d>\d+)d)?
			(?:(?<h>\d+)h)?
			(?:(?<m>\d+)m)?
			(?:(?<s>\d+)s?)?
		$/x';
		$value = $this->_data[$key];
		if (preg_match($re, $value, $m)) {
			return
				+ intval(@$m["w"]) * 1*60*60*24*7
				+ intval(@$m["d"]) * 1*60*60*24
				+ intval(@$m["h"]) * 1*60*60
				+ intval(@$m["m"]) * 1*60
				+ intval(@$m["s"]) * 1;
		} else {
			return $default;
		}
	}
}
