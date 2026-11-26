<?php
defined('FORLOVER') || exit;
/** @var array $post */
$name = display_name($post);
$avatar = avatar_url($post['author_qq'] ?? null);
if ($avatar === '') {
    $avatar = initial_avatar($name);
}
?>
<article class="card fl-card mb-3" data-post-id="<?= (int)$post['id'] ?>">
  <div class="card-body">
    <div class="d-flex align-items-center mb-2">
      <img src="<?= e($avatar) ?>" alt="" class="fl-avatar me-2" loading="lazy" onerror="this.onerror=null;this.src='<?= e(initial_avatar($name)) ?>'">
      <div>
        <div class="fw-semibold"><?= e($name) ?></div>
        <div class="text-muted small"><?= e(date('Y-m-d H:i:s', strtotime((string)$post['created_at']))) ?></div>
      </div>
      <a class="ms-auto btn btn-sm btn-outline-secondary" href="/post/<?= (int)$post['id'] ?>">分享</a>
    </div>
    <p class="fl-content mb-2"><span class="fl-towho">@<?= e($post['target_name']) ?></span> <?= e($post['content']) ?></p>
    <div class="d-flex gap-3 align-items-center">
      <button type="button" class="btn btn-sm fl-like" data-like="<?= (int)$post['id'] ?>">
        <span class="fl-heart">🤍</span> <span class="fl-like-count"><?= (int)$post['like_count'] ?></span>
      </button>
      <a class="btn btn-sm fl-comment-link" href="/post/<?= (int)$post['id'] ?>">💬 <?= (int)$post['comment_count'] ?> 条评论</a>
    </div>
  </div>
</article>
