<?php
define("RWHO_LIB", true);
require __DIR__."/rwho.lib.php";

$user = $_GET["user"];
$host = $_GET["host"];

$data = retrieve($user, $host);

header("Content-Type: text/plain; charset=utf-8");
print json_encode($data)."\n";
