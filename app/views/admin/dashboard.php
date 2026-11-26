<?php
defined('FORLOVER') || exit;
/** @var array $stats */
?>
<h4 class="mb-4">平台首页</h4>
<?php if (!empty($updateInfo['has_new'])): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
  <span>🎉 发现新版本 <strong>V<?= e((string)$updateInfo['latest']) ?></strong>，可以升级了。</span>
  <a class="btn btn-primary btn-sm" href="/admin/update">去升级</a>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-md-3"><div class="card text-center"><div class="card-body">
    <div class="display-6"><?= (int)$stats['posts'] ?></div><div class="text-muted">表白总数</div>
  </div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body">
    <div class="display-6"><?= (int)$stats['comments'] ?></div><div class="text-muted">评论总数</div>
  </div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body">
    <div class="display-6"><?= (int)$stats['likes'] ?></div><div class="text-muted">点赞总数</div>
  </div></div></div>
  <div class="col-md-3"><div class="card text-center"><div class="card-body">
    <div class="display-6"><?= (int)$stats['today_posts'] ?> / <?= (int)$stats['today_comments'] ?></div><div class="text-muted">今日表白 / 评论</div>
  </div></div></div>
</div>
<p class="text-muted mt-4">SillyLi表白墙 V<?= VERSION ?> · PHP <?= PHP_VERSION ?></p>
