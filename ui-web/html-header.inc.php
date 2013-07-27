<?php namespace RWho; ?>
<!DOCTYPE html>
<head>
	<meta charset="utf-8">
	<title><?= h(html::$title) ?></title>
	<meta name="robots" content="noindex, nofollow">
	<noscript>
		<meta http-equiv="Refresh" content="10">
	</noscript>
	<link rel="stylesheet" href="rwho.css">
<?php if (Config::has("web.stylesheet")) { ?>
	<link rel="stylesheet" href="<?= h(Config::get("web.stylesheet")) ?>">
<?php } ?>
</head>