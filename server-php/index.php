<?php
namespace RWho\Server;
require_once(__DIR__."/../lib-php/util.php");
require_once(__DIR__."/../lib-php/json_rpc.php");
require_once(__DIR__."/../lib-php/server_api.php");

class ApiServerApp {
	function __construct() {
		$this->config = new \RWho\Config\Configuration();
		$this->config->load(__DIR__."/../server.conf");
	}

	function die_require_http_basic() {
		header("Status: 401");
		header("WWW-Authenticate: Basic realm=\"rwho\"");
		die();
	}

	function check_authentication() {
		$auth_required = $this->config->get_bool("server.auth_required", false);
		$auth_id = @$_SERVER["PHP_AUTH_USER"];
		$auth_pw = @$_SERVER["PHP_AUTH_PW"];
		$auth_type = @$_SERVER["AUTH_TYPE"];

		if (isset($auth_id) && isset($auth_pw)) {
			$db_pw = $this->config->get("auth.pw.$auth_id", null);
			if (!empty($db_pw)) {
				if (password_verify($auth_pw, $db_pw)) {
					xsyslog(LOG_DEBUG, "Accepting authenticated client '$auth_id'");
					return $auth_id;
				} else {
					xsyslog(LOG_WARNING, "Rejecting client '$auth_id' (authentication failure)");
					$this->die_require_http_basic();
				}
			} else {
				xsyslog(LOG_DEBUG, "Client sent unknown username '$auth_id', will treat as anonymous.");
				// Fall through - if there is no such username, apply the same rules as for anonymous clients.
			}
		}

		if ($auth_required) {
			xsyslog(LOG_WARNING, "Rejecting anonymous client (configuration requires auth)");
			$this->die_require_http_basic();
		} else {
			xsyslog(LOG_DEBUG, "Allowing anonymous client with no auth");
			return null;
		}
	}

	function handle_legacy_request($api) {
		header("Content-Type: text/plain; charset=utf-8");

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

		try {
			switch ($action) {
				case "insert":
					$data = json_decode($_POST["utmp"], true);
					if (!is_array($data)) {
						die("error: no data\n");
					}
					$api->InsertEntries($host, $data);
					die("OK\n");
				case "delete":
					$data = json_decode($_POST["utmp"], true);
					if (!is_array($data)) {
						die("error: no data\n");
					}
					$api->RemoveEntries($host, $data);
					die("OK\n");
				case "put":
					$data = json_decode($_POST["utmp"], true);
					if (!is_array($data)) {
						die("error: no data\n");
					}
					$api->PutEntries($host, $data);
					die("OK\n");
				case "destroy":
					$api->ClearEntries($host);
					die("OK\n");
				default:
					die("error: unknown action\n");
			}
		} catch (UnauthorizedHostError $e) {
			header("Status: 403");
			die("error: account '$auth_id' not authorized for host '$host'\n");
		} catch (KodResponseError $e) {
			die("KOD: ".$e->getMessage()."\n");
		}
	}

	function handle_json_request($api) {
		if ($_SERVER["REQUEST_METHOD"] === "POST") {
			$rpc = new \JsonRpc\Server();
			$rpc->handle_posted_request($api);
		} else {
			header("Status: 405 Method Not Allowed");
		}
	}

	function handle_request() {
		$auth_id = $this->check_authentication();
		$environ = [
			"REMOTE_USER" => $auth_id,
			"REMOTE_ADDR" => $_SERVER["REMOTE_ADDR"],
		];
		$api = new RWhoApiInterface($this->config, $environ);

		if (isset($_REQUEST["action"])) {
			$this->handle_legacy_request($api);
		} else {
			$this->handle_json_request($api);
		}
	}
}

$app = new ApiServerApp();
$app->handle_request();
