<?php
namespace RWho;

header("Content-Type: text/plain; charset=utf-8");

require(__DIR__."/../lib-php/librwho.php");
require(__DIR__."/libserver.php");

function xsyslog($level, $message) {
	$message = "[".$_SERVER["REMOTE_ADDR"]."] $message";
	return syslog($level, $message);
}

function die_require_http_basic() {
	header("Status: 401");
	header("WWW-Authenticate: Basic realm=\"rwho\"");
	die();
}

function handle_legacy_request() {
	if (strlen($_POST["fqdn"]))
		$host = $_POST["fqdn"];
	elseif (strlen($_POST["host"]))
		$host = $_POST["host"];
	else
		die("error: host not specified\n");

	if (isset($_REQUEST["action"]))
		$action = $_REQUEST["action"];
	else
		die("error: action not specified\n");

	$kod_msg = get_host_kodmsg($host);
	if ($kod_msg) {
		xsyslog(LOG_NOTICE, "Rejected with KOD message (\"$kod_msg\")");
		die("KOD: $kod_msg\n");
	}

	if (!check_authorization($host)) {
		die_require_http_basic();
	}

	$server = new RWhoServer();

	switch ($action) {
		case "insert":
			$data = json_decode($_POST["utmp"]);
			if (!is_array($data)) {
				die("error: no data\n");
			}
			$server->InsertEntries($host, $data);
			print "OK\n";
			break;

		case "delete":
			$data = json_decode($_POST["utmp"]);
			if (!is_array($data)) {
				die("error: no data\n");
			}
			$server->RemoveEntries($host, $data);
			print "OK\n";
			break;

		case "put":
			$data = json_decode($_POST["utmp"]);
			if (!is_array($data)) {
				die("error: no data\n");
			}
			$server->PutEntries($host, $data);
			print "OK\n";
			break;

		case "destroy":
			$server->ClearEntries($host);
			print "OK\n";
			break;

		default:
			print "error: unknown action\n";
	}
}

if (isset($_REQUEST["action"]))
	handle_legacy_request();
