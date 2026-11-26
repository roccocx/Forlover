<?php
defined('FORLOVER') || exit;
/** @var string|null $error */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理员登录 - <?= e(setting('title', 'SillyLi表白墙')) ?></title>
<link rel="stylesheet" href="/assets/bootstrap.min.css">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="fl-login-body">
<div class="container">
  <div class="row justify-content-center mt-5">
    <div class="col-md-4">
      <div class="card shadow">
        <div class="card-body p-4">
          <h5 class="text-center mb-4">💌 <?= e(setting('title', 'SillyLi表白墙')) ?> · 后台登录</h5>
          <?php if (!empty($error)): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
          <?php endif; ?>
          <form method="post" action="/admin/login">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="mb-3">
              <label class="form-label">用户名</label>
              <input type="text" class="form-control" name="user" required autofocus>
            </div>
            <div class="mb-3">
              <label class="form-label">密码</label>
              <input type="password" class="form-control" name="pwd" required>
            </div>
            <button type="submit" class="btn btn-danger w-100">登录</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
