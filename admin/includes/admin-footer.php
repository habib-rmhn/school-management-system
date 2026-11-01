<?php
/**
 * EMS Admin — Footer Include
 * Closes .main-inner, .ems-main, .ems-shell opened by admin-sidebar.php
 */
?>
</div><!-- /.main-inner -->
</main><!-- /.ems-main -->
</div><!-- /.ems-shell -->

<!-- Universal Admin JS -->
<script src="<?= $assetBase ?>/js/admin.js"></script>
<script src="<?= BASE_URL ?>/assets/js/datepicker.js"></script>
<script src="<?= BASE_URL ?>/assets/js/timepicker.js"></script>

<?php
// Slot for page-specific inline JS — set $pageJs = "your JS code" before footer include
if (!empty($pageJs)): ?>
<script><?= $pageJs ?></script>
<?php endif; ?>

</body>
</html>