<?php
defined('FORLOVER') || exit;
/** @var array $comments @var int $page @var int $pages */
?>
<h4 class="mb-3">评论列表</h4>
<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead><tr>
    <th>ID</th><th>所属表白</th><th>昵称</th><th>QQ</th><th>内容</th><th>时间</th><th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php foreach ($comments as $c): ?>
    <tr class="<?= $c['deleted_at'] ? 'table-secondary text-muted' : '' ?>">
      <td><?= (int)$c['id'] ?></td>
      <td><a href="/post/<?= (int)$c['post_id'] ?>" target="_blank">#<?= (int)$c['post_id'] ?> <?= e(mb_strimwidth((string)$c['post_title'], 0, 24, '…', 'UTF-8')) ?></a></td>
      <td><?= e(display_name($c)) ?></td>
      <td><?= e((string)$c['author_qq']) ?></td>
      <td class="fl-cell-content"><?= e(mb_strimwidth((string)$c['content'], 0, 60, '…', 'UTF-8')) ?></td>
      <td class="text-nowrap"><?= e((string)$c['created_at']) ?></td>
      <td><?= $c['deleted_at'] ? '已删除' : e($c['status']) ?></td>
      <td class="text-nowrap">
        <?php if (!$c['deleted_at']): ?>
        <form method="post" action="/admin/comments/delete" class="d-inline" onsubmit="return confirm('确定删除这条评论吗？')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
        </form>
        <?php else: ?>
        <form method="post" action="/admin/comments/restore" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-success">恢复</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php $base = '/admin/comments'; require __DIR__ . '/../pager.php'; ?>
