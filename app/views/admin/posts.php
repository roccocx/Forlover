<?php
defined('FORLOVER') || exit;
/** @var array $posts @var int $page @var int $pages */
?>
<h4 class="mb-3">告白列表</h4>
<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead><tr>
    <th>ID</th><th>头像</th><th>昵称</th><th>QQ</th><th>表白对象</th><th>内容</th><th>赞</th><th>评论</th><th>时间</th><th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php foreach ($posts as $p): ?>
    <tr class="<?= $p['deleted_at'] ? 'table-secondary text-muted' : '' ?>">
      <td><?= (int)$p['id'] ?></td>
      <td><img src="<?= e(avatar_url($p['author_qq']) ?: initial_avatar(display_name($p))) ?>" class="fl-avatar-sm" alt=""></td>
      <td><?= e(display_name($p)) ?></td>
      <td><?= e((string)$p['author_qq']) ?></td>
      <td><?= e($p['target_name']) ?></td>
      <td class="fl-cell-content"><?= e(mb_strimwidth((string)$p['content'], 0, 60, '…', 'UTF-8')) ?></td>
      <td><?= (int)$p['like_count'] ?></td>
      <td><?= (int)$p['comment_count'] ?></td>
      <td class="text-nowrap"><?= e((string)$p['created_at']) ?></td>
      <td><?= $p['deleted_at'] ? '已删除' : e($p['status']) ?></td>
      <td class="text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="/admin/posts/edit?id=<?= (int)$p['id'] ?>">修改</a>
        <?php if (!$p['deleted_at']): ?>
        <form method="post" action="/admin/posts/delete" class="d-inline" onsubmit="return confirm('确定删除这条表白吗？（软删除，可在数据库恢复）')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
        </form>
        <?php else: ?>
        <form method="post" action="/admin/posts/restore" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-success">恢复</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $base = '/admin/posts'; require __DIR__ . '/../pager.php'; ?>
