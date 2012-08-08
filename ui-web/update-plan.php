<?php

function find_plan($user) {
	return "/var/lib/plan/".strtr($user, "./", "__");
}

function fcopy($in, $out, $max) {
	$pos = 0;
	while (!feof($in)) {
		$data = fread($in, 4096);
		$pos += strlen($data);
		if ($pos == $max)
			break;
		elseif ($pos > $max) {
			$data = substr($data, 0, $pos-$max);
			$pos = $max;
		}
		fwrite($out, $data);
	}
	return $pos;
}

header("Content-Type: text/plain; charset=utf-8");

$user = $_GET["user"];

if (!strlen($user)) {
	header("Status: 400");
	die("Missing user.\n");
}

switch ($_SERVER["REQUEST_METHOD"]) {
case "GET":
	$plan = find_plan($user);

	if (file_exists($plan)) {
		readfile($plan);
	} else {
		header("Status: 404");
		die("No Plan.\n");
	}

	break;

case "PUT":
	$plan = find_plan($user);
	$temp = $plan.".tmp";

	$planfh = fopen($temp, "w");
	if (!$planfh) {
		header("Status: 500");
		die("Server error.\n");
	}
	$putfh = fopen("php://input", "r");
	fcopy($putfh, $planfh, $max=16384);
	fclose($planfh);
	fclose($putfh);

	if ($pos > $max) {
		unlink($temp);
		header("Status: 413");
		die("Upload too large (max $max bytes)\n");
	} else {
		rename($temp, $plan);
		header("Status: 201");
		die("Plan created.\n");
	}

	break;

case "DELETE":
	$plan = find_plan($user);

	if (!file_exists($plan)) {
		header("Status: 404");
		die("No Plan.\n");
	} elseif (!unlink($plan)) {
		header("Status: 500");
		die("Server error.\n");
	} else {
		header("Status: 200");
		die("Plan removed.\n");
	}

	break;

default:
	header("Status: 405 Method Not Allowed");
}
