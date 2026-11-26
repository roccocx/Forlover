<?php
defined('FORLOVER') || exit;
/** @var array $posts @var int $page @var int $pages */
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <?php if (!$posts): ?>
    <div class="text-center text-muted py-5">还没有人表白，来做第一个吧 💌</div>
    <?php endif; ?>
    <?php foreach ($posts as $post) {
        require __DIR__ . '/card.php';
    } ?>
    <?php $base = '/'; require __DIR__ . '/pager.php'; ?>
  </div>
</div>
