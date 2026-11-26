<?php
defined('FORLOVER') || exit;
/** @var string $content */
/** @var string $pageTitle */
$title = setting('title', 'SillyLi表白墙');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? $title) ?> - <?= e($title) ?></title>
<meta name="keywords" content="<?= e(setting('keywords')) ?>">
<meta name="description" content="<?= e(setting('description')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💌</text></svg>">
<link rel="stylesheet" href="/assets/bootstrap.min.css">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(VERSION) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg fl-navbar sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">💌 <?= e($title) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="菜单">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="/">首页</a></li>
        <li class="nav-item"><a class="nav-link" href="/about">关于</a></li>
      </ul>
      <form class="d-flex me-2" role="search" action="/search" method="get">
        <input class="form-control me-2" type="search" name="k" placeholder="搜索表白内容…" value="<?= e($_GET['k'] ?? '') ?>" aria-label="搜索">
        <button class="btn btn-outline-danger" type="submit">搜索</button>
      </form>
      <button class="btn btn-danger me-2" type="button" data-bs-toggle="modal" data-bs-target="#publishModal">💌 我要表白</button>
      <button class="btn btn-outline-secondary" type="button" id="themeToggle" title="深色模式">🌙</button>
    </div>
  </div>
</nav>

<main class="container py-4">
<?= $content ?>
</main>

<footer class="fl-footer text-center py-4 mt-4">
  <p class="mb-1">版权所有 &copy; 2018-<?= date('Y') ?> <?= e($title) ?> V<?= VERSION ?></p>
  <p class="mb-1"><a href="<?= e(PROJECT_URL) ?>" target="_blank" rel="noopener">项目地址</a></p>
  <?php if (setting('beian')): ?><p class="mb-1"><?= e(setting('beian')) ?></p><?php endif; ?>
  <?php if (setting('footer')): ?><div><?= setting('footer') ?></div><?php endif; ?>
  <?php $start = (int)setting('time', '0'); if ($start > 0): ?>
  <p class="mb-0 text-muted small">本站已运行 <span id="sitetime" data-start="<?= $start ?>"></span></p>
  <?php endif; ?>
</footer>

<!-- Publish modal -->
<div class="modal fade" id="publishModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="publishForm">
      <div class="modal-header">
        <h5 class="modal-title">💌 我要表白</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">QQ</label>
          <input type="text" class="form-control" name="qq" inputmode="numeric" placeholder="填写后使用 QQ 头像，匿名请留空">
        </div>
        <div class="mb-3">
          <label class="form-label">昵称</label>
          <input type="text" class="form-control" name="realname" maxlength="60" placeholder="使用 QQ 昵称请留空；匿名表白请填写昵称">
        </div>
        <div class="mb-3">
          <label class="form-label">表白对象</label>
          <input type="text" class="form-control" name="towho" maxlength="60" required placeholder="你想表白的人…">
        </div>
        <div class="mb-3">
          <label class="form-label">想说的话</label>
          <textarea class="form-control" name="content" rows="5" maxlength="1000" required placeholder="这是我要说的话"></textarea>
        </div>
        <div class="alert alert-danger d-none" role="alert" id="publishError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="submit" class="btn btn-danger" id="publishSubmit">发布</button>
      </div>
    </form>
  </div>
</div>

<?php if (setting('ad')): ?><div class="fl-ad"><?= setting('ad') ?></div><?php endif; ?>
<div style="display:none"><?= setting('163music') ?></div>

<script src="/assets/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js?v=<?= e(VERSION) ?>"></script>
</body>
</html>
