<?php
defined('FORLOVER') || exit;
/** @var string $content @var string $adminPage */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>后台管理 - <?= e(setting('title', 'SillyLi表白墙')) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="/assets/bootstrap.min.css">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(VERSION) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/admin">后台管理</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="菜单">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="adminNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= ($adminPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="/admin">平台首页</a></li>
        <li class="nav-item"><a class="nav-link <?= ($adminPage ?? '') === 'posts' ? 'active' : '' ?>" href="/admin/posts">告白列表</a></li>
        <li class="nav-item"><a class="nav-link <?= ($adminPage ?? '') === 'comments' ? 'active' : '' ?>" href="/admin/comments">评论列表</a></li>
        <li class="nav-item"><a class="nav-link <?= ($adminPage ?? '') === 'settings' ? 'active' : '' ?>" href="/admin/settings">网站配置</a></li>
        <?php $uc = json_decode(setting('update_check'), true); ?>
        <li class="nav-item"><a class="nav-link <?= ($adminPage ?? '') === 'update' ? 'active' : '' ?>" href="/admin/update">系统升级<?php if (is_array($uc) && !empty($uc['has_new'])): ?> <span class="badge bg-danger">NEW</span><?php endif; ?></a></li>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="/" target="_blank">查看前台</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(PROJECT_URL) ?>" target="_blank" rel="noopener">项目地址</a></li>
        <li class="nav-item"><a class="nav-link" href="/admin/logout">退出登录</a></li>
      </ul>
    </div>
  </div>
</nav>
<main class="container-fluid py-4">
<?= $content ?>
</main>
<script src="/assets/bootstrap.bundle.min.js"></script>
</body>
</html>
