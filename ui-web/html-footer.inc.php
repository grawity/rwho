<?php if ($xhr_refresh) { ?>
<script type="text/javascript">
var settings = <?= json_encode([
	"interval" => $xhr_refresh,
	"args" => $xhr_url,
]) ?>;
</script>
<script type="text/javascript" src="xhr.js?v6"></script>
<?php } ?>

<p class="footer">
	View as
<?php if (!empty($finger_url)) { ?>
		<a href="<?= htmlspecialchars($finger_url) ?>">text</a>,
<?php } ?>
		<a href="<?= htmlspecialchars($json_url) ?>">json</a>,
		<a href="<?= htmlspecialchars($xml_url) ?>">xml</a>
	| Built using <a href="https://github.com/grawity/rwho/">rwho</a>
</p>
