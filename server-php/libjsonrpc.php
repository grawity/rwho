<?php
namespace JsonRpc;

class Absent {
	/* Sentinel value indicating that no call ID was present
	 * and that the request was a notification without reply.
	 */
}

class RpcException extends \Exception {
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

function dispatch($request, $server) {
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
		if (!method_exists($server, $method)) {
			throw new RpcBadMethodError();
		}
		if ($params === null) {
			$result = $server->$method();
		} elseif (is_array($params)) {
			$result = $server->$method(...$params);
		} else {
			throw new RpcMalformedObjectError();
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

function handle_posted_request($server) {
	$request = file_get_contents("php://input");
	$response = dispatch($request, $server);
	if ($response === null) {
		die();
	} else {
		header("Content-Type: application/json");
		die($response);
	}
}
