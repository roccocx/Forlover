<?php
defined('FORLOVER') || exit;
/** @var array $conf @var bool $saved */
$fields = [
    'title' => ['网站标题', 'text'],
    'keywords' => ['关键词', 'text'],
    'description' => ['网站描述', 'text'],
    'qq' => ['站长 QQ', 'text'],
    'beian' => ['备案号', 'text'],
    'footer' => ['页脚代码（支持 HTML）', 'textarea'],
    'ad' => ['广告代码（支持 HTML）', 'textarea'],
    '163music' => ['音乐播放器 JS 代码', 'textarea'],
];
?>
<h4 class="mb-3">网站配置</h4>
<?php if (!empty($saved)): ?>
<div class="alert alert-success">保存成功</div>
<?php endif; ?>
<form method="post" action="/admin/settings" class="col-lg-8">
  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
  <?php foreach ($fields as $key => [$label, $type]): ?>
  <div class="mb-3">
    <label class="form-label"><?= e($label) ?> <code><?= e($key) ?></code></label>
    <?php if ($type === 'textarea'): ?>
    <textarea class="form-control" name="<?= e($key) ?>" rows="3"><?= e($conf[$key] ?? '') ?></textarea>
    <?php else: ?>
    <input type="text" class="form-control" name="<?= e($key) ?>" value="<?= e($conf[$key] ?? '') ?>">
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <hr>
  <div class="mb-3">
    <label class="form-label">修改管理员密码（留空不改）</label>
    <input type="password" class="form-control" name="pwd" autocomplete="new-password" placeholder="输入新密码以修改">
  </div>
  <button type="submit" class="btn btn-danger">保存配置</button>
</form>
