<?php namespace RWho; ?>
<?php if (html::$refresh) { ?>
<script type="text/javascript">
var settings = {
	interval: <?= html::$refresh ?>,
	args: "<?= addslashes(mangle_query(array("fmt" => "html-xhr"))) ?>",
};
</script>
<script type="text/javascript" src="xhr.js"></script>
<?php } ?>

<p class="footer"><a href="https://github.com/grawity/rwho/">rwho</a> by grawity</p>
