<?php
if (!file_exists(__DIR__ . '/.footer_enabled')) {
    return;
}
$f_data = file_exists(__DIR__ . '/.footer.json') ? json_decode(file_get_contents(__DIR__ . '/.footer.json'), true) : [];
if (!is_array($f_data)) {
    $f_data = [];
}
$footer_columns = $f_data['columns'] ?? [];
if (!is_array($footer_columns) || !$footer_columns) {
    $footer_columns = [];
    if (!empty($f_data['col1_title']) || !empty($f_data['col1_content'])) {
        $footer_columns[] = ['title' => $f_data['col1_title'] ?? '', 'content' => $f_data['col1_content'] ?? ''];
    }
    if (!empty($f_data['col2_title']) || !empty($f_data['col2_content'])) {
        $footer_columns[] = ['title' => $f_data['col2_title'] ?? '', 'content' => $f_data['col2_content'] ?? ''];
    }
}
?>
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-col">
      <h4>O nas</h4>
      <p><?php echo htmlspecialchars($f_data['about_text'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php foreach ($footer_columns as $column): ?>
      <?php
        $col_title = trim((string)($column['title'] ?? ''));
        $col_content = (string)($column['content'] ?? '');
        if ($col_title === '' && trim(strip_tags($col_content)) === '') { continue; }
      ?>
      <div class="footer-col">
        <?php if ($col_title !== ''): ?>
          <h4><?php echo htmlspecialchars($col_title, ENT_QUOTES, 'UTF-8'); ?></h4>
        <?php endif; ?>
        <p><?php echo $col_content; ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="footer-bottom">
    <?php echo htmlspecialchars($f_data['copyright'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
  </div>
</footer>