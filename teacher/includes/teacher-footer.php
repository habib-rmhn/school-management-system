<?php
/**
 * EMS Teacher — Footer Include
 * Closes .main-inner, .ems-main, .ems-shell opened by teacher-sidebar.php
 * Also renders the mobile bottom navigation bar (visible ≤768px only).
 */
?>
</div><!-- /.main-inner -->
</main><!-- /.ems-main -->
</div><!-- /.ems-shell -->

<!-- ══════════════════════════════════════════════════════════════
     MOBILE BOTTOM NAV — quick thumb-reach shortcuts on phones
     ══════════════════════════════════════════════════════════════ -->
<nav class="mobile-bottom-nav" aria-label="Quick navigation">
  <div class="mobile-bottom-nav-inner">
    <a href="<?= BASE_URL ?>/teacher/dashboard.php"
       class="mob-nav-item<?= $activeNav === 'dashboard' ? ' active' : '' ?>">
      <i class="fas fa-house"></i><span>Home</span>
    </a>
    <a href="<?= BASE_URL ?>/teacher/routine.php"
       class="mob-nav-item<?= $activeNav === 'routine' ? ' active' : '' ?>">
      <i class="fas fa-calendar-week"></i><span>Routine</span>
    </a>
    <a href="<?= BASE_URL ?>/teacher/attendance.php"
       class="mob-nav-item<?= $activeNav === 'attendance' ? ' active' : '' ?>">
      <i class="fas fa-calendar-check"></i><span>Attend</span>
    </a>
    <a href="<?= BASE_URL ?>/teacher/marks.php"
       class="mob-nav-item<?= $activeNav === 'marks' ? ' active' : '' ?>">
      <i class="fas fa-pencil"></i><span>Marks</span>
    </a>
    <a href="<?= BASE_URL ?>/teacher/profile.php"
       class="mob-nav-item<?= $activeNav === 'profile' ? ' active' : '' ?>">
      <i class="fas fa-user"></i><span>Profile</span>
    </a>
  </div>
</nav>

<!-- Universal Teacher JS -->
<script src="<?= $assetBase ?>/js/teacher.js"></script>
<script src="<?= BASE_URL ?>/assets/js/datepicker.js"></script>

<?php
// Slot for page-specific inline JS — set $pageJs = "your JS code" before footer include
if (!empty($pageJs)): ?>
<script><?= $pageJs ?></script>
<?php endif; ?>

</body>
</html>