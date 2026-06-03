<?php
// Globalny plik reprezentujący stopkę serwisu generowany przez SpiderCMS
$f_data = file_exists(__DIR__ . '/.footer.json') ? json_decode(file_get_contents(__DIR__ . '/.footer.json'), true) : [];
?>
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-col">
      <h4>O nas</h4>
      <p><?php echo htmlspecialchars($f_data['about_text'] ?? ''); ?></p>
    </div>
    <?php if(!empty($f_data['col1_title'])): ?>
    <div class="footer-col">
      <h4><?php echo htmlspecialchars($f_data['col1_title']); ?></h4>
      <p><?php echo $f_data['col1_content'] ?? ''; ?></p>
    </div>
    <?php endif; ?>
    <?php if(!empty($f_data['col2_title'])): ?>
    <div class="footer-col">
      <h4><?php echo htmlspecialchars($f_data['col2_title']); ?></h4>
      <p><?php echo $f_data['col2_content'] ?? ''; ?></p>
    </div>
    <?php endif; ?>
  </div>
  <div class="footer-bottom">
    <?php echo htmlspecialchars($f_data['copyright'] ?? ''); ?>
  </div>
</footer>