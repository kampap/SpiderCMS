<?php
if (!file_exists(__DIR__ . '/.footer_enabled')) {
    return;
}
$f_data = file_exists(__DIR__ . '/.footer.json') ? json_decode(file_get_contents(__DIR__ . '/.footer.json'), true) : [];
$columns = $f_data['columns'] ?? [];
?>
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-col">
      <h4>O nas</h4>
      <p><?php echo htmlspecialchars($f_data['about_text'] ?? ''); ?></p>
    </div>
    <?php foreach ($columns as $col): ?>
    <?php if(!empty($col['title'])): ?>
    <div class="footer-col">
      <h4><?php echo htmlspecialchars($col['title']); ?></h4>
      <p><?php echo $col['content'] ?? ''; ?></p>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div class="footer-bottom">
    <?php echo htmlspecialchars($f_data['copyright'] ?? ''); ?>
  </div>
</footer>