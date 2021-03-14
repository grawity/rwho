<?php namespace RWho; ?>
<?php if (html::$refresh) { ?>
<script type="text/javascript">
var settings = {
	interval: <?= html::$refresh ?>,
	args: "<?= addslashes(Web\mangle_query(["fmt" => "html-xhr"])) ?>",
};
</script>
<script type="text/javascript" src="xhr.js"></script>
<?php } ?>

<p class="footer"><a href="https://github.com/grawity/rwho/">rwho</a> by grawity</p>
