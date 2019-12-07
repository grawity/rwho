<?php namespace RWho; ?>
<!DOCTYPE html>
<head>
	<meta charset="utf-8">
	<title>rwho: <?= h(html::$title) ?></title>
	<meta name="robots" content="noindex, nofollow">
	<noscript>
		<meta http-equiv="Refresh" content="10">
	</noscript>
	<link rel="stylesheet" href="rwho.css">
<?php if (Config::has("web.stylesheet")) { ?>
	<link rel="stylesheet" href="<?= h(Config::get("web.stylesheet")) ?>">
<?php } ?>
</head>

<h1>
	<a href="//nullroute.eu.org">nullroute</a>
	| <a href="//nullroute.eu.org/hosts.html">hosts</a>
<?php if (query::$present) { ?>
	| <a href=".">rwho</a>
<?php } else { ?>
	| rwho
<?php } ?>
</h1>

<p><?= html::$title ?>:</p>
