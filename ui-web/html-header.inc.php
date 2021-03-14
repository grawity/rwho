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
<?php if ($app->config->get("web.stylesheet", null) !== null) { ?>
	<link rel="stylesheet" href="<?= h($app->config->get("web.stylesheet")) ?>">
<?php } ?>
</head>

<h1>
	<a href="//nullroute.eu.org">nullroute</a>
	| <a href="//nullroute.eu.org/hosts.html">hosts</a>
<?php if ($has_query) { ?>
	| <a href=".">rwho</a>
<?php } else { ?>
	| rwho
<?php } ?>
</h1>

<p><?= html::$title ?>:</p>
