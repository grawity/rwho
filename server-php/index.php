<?php
namespace RWho;

header("Content-Type: text/plain; charset=utf-8");

require(__DIR__."/../lib-php/librwho.php");
require(__DIR__."/libserver.php");
require_once(__DIR__."/libjsonrpc.php");

function xsyslog($level, $message) {
	$message = "[".$_SERVER["REMOTE_ADDR"]."] $message";
	return syslog($level, $message);
}

function get_host_pwent($host) {
	Config::parse(__DIR__."/../accounts.conf");
	return Config::get("auth.pw.$host");
}

function get_host_kodmsg($host) {
	Config::parse(__DIR__."/../accounts.conf");
	return Config::get("auth.kod.$host", Config::get("auth.kod.all"));
}

function die_require_http_basic() {
	header("Status: 401");
	header("WWW-Authenticate: Basic realm=\"rwho\"");
	die();
}

function check_authentication() {
	$auth_required = Config::getbool("server.auth_required", false);

	$auth_id = @$_SERVER["PHP_AUTH_USER"];
	$auth_pw = @$_SERVER["PHP_AUTH_PW"];
	$auth_type = @$_SERVER["AUTH_TYPE"];

	if (isset($auth_id) && isset($auth_pw)) {
		$db_pw = get_host_pwent($auth_id);
		if ($db_pw) {
			if (password_verify($auth_pw, $db_pw)) {
				xsyslog(LOG_DEBUG, "Accepting authenticated client '$auth_id'");
				return $auth_id;
			} else {
				xsyslog(LOG_WARNING, "Rejecting client '$auth_id' (authentication failure)");
				die_require_http_basic();
			}
		} else {
			xsyslog(LOG_DEBUG, "Client sent unknown username '$auth_id', will treat as anonymous.");
			// Fall through - if there is no such username, apply the same rules as for anonymous clients.
		}
	}

	if ($auth_required) {
		xsyslog(LOG_WARNING, "Rejecting anonymous client (configuration requires auth)");
		die_require_http_basic();
	} else {
		xsyslog(LOG_DEBUG, "Allowing anonymous client with no auth");
		return null;
	}
}

function handle_legacy_request() {
	if (isset($_POST["fqdn"]))
		$host = $_POST["fqdn"];
	elseif (isset($_POST["host"]))
		$host = $_POST["host"];
	else
		die("error: host not specified\n");

	if (isset($_REQUEST["action"]))
		$action = $_REQUEST["action"];
	else
		die("error: action not specified\n");

	$auth_id = check_authentication();
	$auth_required = Config::getbool("server.auth_required", false);
	$server = new RWhoServer($auth_id, $auth_required);

	try {
		switch ($action) {
			case "insert":
				$data = json_decode($_POST["utmp"], true);
				if (!is_array($data)) {
					die("error: no data\n");
				}
				$server->InsertEntries($host, $data);
				print "OK\n";
				break;

			case "delete":
				$data = json_decode($_POST["utmp"], true);
				if (!is_array($data)) {
					die("error: no data\n");
				}
				$server->RemoveEntries($host, $data);
				print "OK\n";
				break;

			case "put":
				$data = json_decode($_POST["utmp"], true);
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
	} catch (UnauthorizedHostError $e) {
		header("Status: 403");
		die("error: account '$auth_id' not authorized for host '$host'\n");
	} catch (KodResponseError $e) {
		die("KOD: $kod_msg\n");
	}
}

function handle_jsonrpc_request() {
	$auth_id = check_authentication();
	$auth_required = Config::getbool("server.auth_required", false);
	$server = new RWhoServer($auth_id, $auth_required);

	$callid = null;

	$request = file_get_contents("php://input");
	$request = json_decode($request, true, 64);

	try {
		if (!$request) {
			throw new \JsonRpc\RpcParseError();
		}
		if (@$request["jsonrpc"] !== "2.0") {
			throw new \JsonRpc\RpcMalformedObjectError();
		}
		if (!isset($request["method"])) {
			throw new \JsonRpc\RpcMalformedObjectError();
		}
		$method = $request["method"];
		$params = @$request["params"];
		$callid = @$request["id"];
		if (preg_match("/^_|^rpc[._]/", $method)) {
			throw new \JsonRpc\RpcBadMethodError();
		}
		if (!method_exists($server, $method)) {
			throw new \JsonRpc\RpcBadMethodError();
		}
		if ($params === null) {
			$result = $server->$method();
		} elseif (is_array($params)) {
			$result = $server->$method(...$params);
		} else {
			throw new \JsonRpc\RpcMalformedObjectError();
		}
		$response = [
			"jsonrpc" => "2.0",
			"result" => $result,
			"id" => $callid,
		];
	} catch (\JsonRpc\RpcException $e) {
		$response = [
			"jsonrpc" => "2.0",
			"error" => [
				"code" => $e->getCode(),
				"message" => $e->getMessage(),
			],
			"id" => $callid,
		];
	}

	$response = json_encode($response);
	header("Content-Type: application/json");
	die($response);
}

if (isset($_REQUEST["action"])) {
	handle_legacy_request();
	exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
	handle_jsonrpc_request();
} else {
	header("Status: 405 Method Not Allowed");
}
