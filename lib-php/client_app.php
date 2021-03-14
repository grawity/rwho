<?php
namespace RWho;
require_once(__DIR__."/../lib-php/config.php");
require_once(__DIR__."/../lib-php/client.php");

class ClientApplicationBase {
	function __construct() {
		$this->config = new \RWho\Config\Configuration();
		$this->config->load(__DIR__."/../server.conf"); // for DB info
		$this->config->load(__DIR__."/../rwho.conf");

		$this->client = new \RWho\Client($this->config);
	}
}
