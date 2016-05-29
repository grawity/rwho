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
<p class="footer">authed: <?php echo is_authenticated() ? "yes" : "no" ?>, trusted: <?php echo is_trusted_ip() ? "yes" : "no" ?></p>
<?php if (auth::$method !== "none" && auth::is_logged_in()) { ?>
<p class="footer"><a href="?logout=1">log out</a></p>
<?php } ?>
