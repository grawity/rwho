<?php if ($xhr_refresh) { ?>
<script type="text/javascript">
var settings = <?= json_encode([
	"interval" => $xhr_refresh,
	"args" => $xhr_url,
]) ?>;
</script>
<script type="text/javascript" src="xhr.js?v2"></script>
<?php } ?>

<p class="footer"><a href="https://github.com/grawity/rwho/">rwho</a> by grawity</p>
