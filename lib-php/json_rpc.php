<?php
namespace JsonRpc;

if (!function_exists("array_is_list")) {
	/* Polyfill from https://wiki.php.net/rfc/is_list */
	function array_is_list(array $array): bool {
		$expect = 0;
		foreach ($array as $k => $_) {
			if ($k !== $expect)
				return false;
			$expect++;
		}
		return true;
	}
}

class Absent {
	/* Sentinel value indicating that no call ID was present
	 * and that the request was a notification without reply.
	 */
}

abstract class RpcException extends \Exception {
}

class RpcParseError extends RpcException {
	function __construct() {
		$this->code = -32700;
		$this->message = "JSON parse error";
	}
}

class RpcMalformedObjectError extends RpcException {
	function __construct() {
		$this->code = -32600;
		$this->message = "Not a well-formed JSON-RPC object";
	}
}

class RpcBadMethodError extends RpcException {
	function __construct() {
		$this->code = -32601;
		$this->message = "Specified method not available";
	}
}

class RpcBadParametersError extends RpcException {
	function __construct() {
		$this->code = -32602;
		$this->message = "Unacceptable call parameters";
	}
}

class RpcInternalError extends RpcException {
	function __construct($message) {
		$this->code = -32603;
		$this->message = "$message";
	}
}

class Server {
	public $interface;
	public $allow_named_args = false;

	function __construct($interface) {
		$this->interface = $interface;
	}

	function dispatch($request) {
		$call_id = null;
		$request = json_decode($request, true, 64);

		try {
			if (!$request) {
				throw new RpcParseError();
			}
			if (!is_array($request)) {
				throw new RpcMalformedObjectError();
			}
			if (@$request["jsonrpc"] !== "2.0") {
				throw new RpcMalformedObjectError();
			}
			if (!isset($request["method"])) {
				throw new RpcMalformedObjectError();
			}
			$method = $request["method"];
			$params = @$request["params"];
			if (!array_key_exists("id", $request)) {
				$call_id = new Absent();
			} else {
				$call_id = $request["id"];
				if (!is_null($call_id)
				&& !is_string($call_id)
				&& !is_numeric($call_id)) {
					throw new RpcMalformedObjectError();
				}
			}
			if (!is_string($method)) {
				throw new RpcMalformedObjectError();
			}
			if (preg_match("/^_|^rpc[._]/", $method)) {
				throw new RpcBadMethodError();
			}
			if (!method_exists($this->interface, $method)) {
				throw new RpcBadMethodError();
			}
			try {
				if ($params === null) {
					$result = $this->interface->$method();
				} elseif (is_array($params) && array_is_list($params)) {
					$result = $this->interface->$method(...$params);
				} elseif (is_array($params) && $this->allow_named_args) {
					/* Allow spread of named parameters if opted-in.
					 * This throws a generic Error if the parameter
					 * names don't match, unfortunately. */
					$result = $this->interface->$method(...$params);
				} elseif (is_array($params) && !$this->allow_named_args) {
					throw new RpcBadParametersError();
				} else {
					throw new RpcMalformedObjectError();
				}
			} catch (\ArgumentCountError $e) {
				throw new RpcBadParametersError();
			} catch (\Error $e) {
				/* TODO: Implement a dedicated exception for wrong arg names,
				 * similar to https://github.com/php/php-src/pull/2100 */
				throw new RpcInternalError($e->getMessage());
			}
			$response = [
				"jsonrpc" => "2.0",
				"result" => $result,
				"id" => $call_id,
			];
		} catch (RpcException $e) {
			$response = [
				"jsonrpc" => "2.0",
				"error" => [
					"code" => $e->getCode(),
					"message" => $e->getMessage(),
				],
				"id" => $call_id,
			];
		}

		if ($call_id instanceof Absent) {
			return null;
		} else {
			$response = json_encode($response);
			return $response;
		}
	}

	function handle_posted_request() {
		$request = file_get_contents("php://input");
		$response = $this->dispatch($request);
		if ($response === null) {
			die();
		} else {
			header("Content-Type: application/json");
			die($response);
		}
	}
}
