#!/usr/bin/php
<?php
namespace RWho;

define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

function finger_handle() {
	$input = fgets(STDIN)
		or die();
	list ($query, $detail) = finger_parse($input);
	list ($q_user, $q_host) = parse_query($query);
	$data = retrieve($q_user, $q_host);
	if (!count($data))
		die("Nobody is logged in.\r\n");
	if (!$detail)
		$data = summarize($data);
	pretty_text($data);
}

function finger_parse($input) {
	$input = rtrim($input, "\r\n");
	if ($input === "/W" or substr($input, 0, 3) === "/W ") {
		$query = substr($input, 3);
		$detail = true;
	} else {
		$query = $input;
		$detail = false;
	}
	return array($query, $detail);
}

finger_handle();
