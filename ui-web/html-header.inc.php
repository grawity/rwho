<!DOCTYPE html>
<head>
	<meta charset="utf-8">
	<title>rwho: <?= htmlspecialchars($page_title) ?></title>
	<meta name="robots" content="noindex, nofollow">
	<noscript>
		<meta http-equiv="Refresh" content="10">
	</noscript>
	<link rel="stylesheet" href="rwho.css">
<?php if (isset($page_css)) { ?>
	<link rel="stylesheet" href="<?= htmlspecialchars($page_css) ?>">
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

<p><?= $page_title ?>:</p>
