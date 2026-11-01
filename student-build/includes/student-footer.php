<?php
/**
 * EMS Student — Footer Include
 * Closes .main-inner, .ems-main, .ems-shell opened by student-sidebar.php
 * Adds: mobile bottom tab bar, logout confirmation modal, universal JS.
 */
$activeNav = $activeNav ?? '';

function stabClass(string $key, string $active): string {
    return 'mt-item' . ($key === $active ? ' active' : '');
}
?>
</div><!-- /.main-inner -->
</main><!-- /.ems-main -->

<!-- ══════════════════════════════════════════════════════════════
     MOBILE BOTTOM TAB BAR — 5 most-used pages, always visible
     ══════════════════════════════════════════════════════════════ -->
<nav class="mobile-tabbar" aria-label="Quick navigation">
  <div class="mobile-tabbar-inner">
    <a href="<?= BASE_URL ?>/student/dashboard.php"  class="<?= stabClass('dashboard', $activeNav) ?>">
      <i class="fas fa-house"></i><span>Home</span>
    </a>
    <a href="<?= BASE_URL ?>/student/routine.php"    class="<?= stabClass('routine', $activeNav) ?>">
      <i class="fas fa-calendar-week"></i><span>Routine</span>
    </a>
    <a href="<?= BASE_URL ?>/student/results.php"    class="<?= stabClass('results', $activeNav) ?>">
      <i class="fas fa-chart-simple"></i><span>Results</span>
    </a>
    <a href="<?= BASE_URL ?>/student/payments.php"   class="<?= stabClass('payments', $activeNav) ?>">
      <i class="fas fa-money-bill-wave"></i><span>Payments</span>
    </a>
    <a href="<?= BASE_URL ?>/student/profile.php"    class="<?= stabClass('profile', $activeNav) ?>">
      <i class="fas fa-user"></i><span>Profile</span>
    </a>
  </div>
</nav>

</div><!-- /.ems-shell -->

<!-- ══════════════════════════════════════════════════════════════
     LOGOUT CONFIRMATION MODAL — custom, not browser confirm()
     ══════════════════════════════════════════════════════════════ -->
<div class="ems-modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
  <div class="ems-modal">
    <div class="ems-modal-icon"><i class="fas fa-right-from-bracket"></i></div>
    <div class="ems-modal-title" id="logoutModalTitle">Log out?</div>
    <div class="ems-modal-text">Are you sure you want to log out of your student account?</div>
    <div class="ems-modal-actions">
      <button type="button" class="btn btn-outline" id="logoutCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="logoutConfirm" data-href="<?= BASE_URL ?>/logout.php">
        <i class="fas fa-right-from-bracket"></i> Log Out
      </button>
    </div>
  </div>
</div>

<!-- Universal Student JS -->
<script src="<?= rtrim(BASE_URL,'/') ?>/student/assets/js/student.js"></script>

<?php if (!empty($pageJs)): ?>
<script><?= $pageJs ?></script>
<?php endif; ?>

</body>
</html>