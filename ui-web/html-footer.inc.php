<?php namespace RWho; ?>
<?php if ($page_xhr_refresh) { ?>
<script type="text/javascript">
var settings = <?= json_encode([
	"interval" => $page_xhr_refresh,
	"args" => Web\mangle_query(["fmt" => "html-xhr"]),
]) ?>;
</script>
<script type="text/javascript" src="xhr.js"></script>
<?php } ?>

<p class="footer"><a href="https://github.com/grawity/rwho/">rwho</a> by grawity</p>
