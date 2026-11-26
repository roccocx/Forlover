<?php
defined('FORLOVER') || exit;
/** @var array $post */
?>
<h4 class="mb-3">修改表白 #<?= (int)$post['id'] ?></h4>
<form method="post" action="/admin/posts/edit" class="col-lg-6">
  <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
  <div class="mb-3">
    <label class="form-label">QQ</label>
    <input type="text" class="form-control" name="qq" value="<?= e((string)$post['author_qq']) ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">昵称</label>
    <input type="text" class="form-control" name="realname" maxlength="60" value="<?= e((string)$post['author_name']) ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">表白对象</label>
    <input type="text" class="form-control" name="towho" maxlength="60" required value="<?= e($post['target_name']) ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">内容</label>
    <textarea class="form-control" name="msg" rows="5" maxlength="1000" required><?= e($post['content']) ?></textarea>
  </div>
  <div class="mb-3">
    <label class="form-label">赞数</label>
    <input type="number" class="form-control" name="zan" min="0" value="<?= (int)$post['like_count'] ?>">
  </div>
  <button type="submit" class="btn btn-danger">保存</button>
  <a class="btn btn-secondary" href="/admin/posts">返回列表</a>
</form>
