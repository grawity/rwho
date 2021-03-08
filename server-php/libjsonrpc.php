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
