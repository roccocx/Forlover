<?php
defined('FORLOVER') || exit;
/** @var array $posts @var int $page @var int $pages @var string $k */
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <h5 class="mb-3">搜索「<?= e($k) ?>」的结果</h5>
    <?php if (!$posts): ?>
    <div class="text-center text-muted py-5">没有找到相关的表白</div>
    <?php endif; ?>
    <?php foreach ($posts as $post) {
        require __DIR__ . '/card.php';
    } ?>
    <?php $base = '/search?k=' . urlencode($k); require __DIR__ . '/pager.php'; ?>
  </div>
</div>
