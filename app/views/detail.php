<?php
defined('FORLOVER') || exit;
/** @var array $post @var array $comments */
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <?php require __DIR__ . '/card.php'; ?>

    <div class="card fl-card">
      <div class="card-body">
        <h6 class="mb-3">💬 评论（<?= count($comments) ?>）</h6>
        <div id="commentList">
          <?php foreach ($comments as $c): $cname = display_name($c); $cavatar = avatar_url($c['author_qq'] ?? null); if ($cavatar === '') $cavatar = initial_avatar($cname); ?>
          <div class="d-flex mb-3">
            <img src="<?= e($cavatar) ?>" alt="" class="fl-avatar-sm me-2" loading="lazy" onerror="this.onerror=null;this.src='<?= e(initial_avatar($cname)) ?>'">
            <div>
              <div><span class="fw-semibold"><?= e($cname) ?></span>
                <span class="text-muted small ms-1"><?= e(date('Y-m-d H:i:s', strtotime((string)$c['created_at']))) ?></span></div>
              <div><?= e($c['content']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$comments): ?><p class="text-muted mb-0" id="noComment">还没有评论，来抢沙发吧</p><?php endif; ?>
        </div>
        <hr>
        <form id="commentForm">
          <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
          <div class="row g-2 mb-2">
            <div class="col-md-4">
              <input type="text" class="form-control" name="qq" inputmode="numeric" placeholder="QQ（留空匿名）">
            </div>
            <div class="col-md-4">
              <input type="text" class="form-control" name="nname" maxlength="60" placeholder="昵称（用 QQ 昵称可留空）">
            </div>
          </div>
          <div class="mb-2">
            <textarea class="form-control" name="content" rows="3" maxlength="500" required placeholder="说点什么…"></textarea>
          </div>
          <div class="alert alert-danger d-none" role="alert" id="commentError"></div>
          <button type="submit" class="btn btn-danger" id="commentSubmit">发表评论</button>
        </form>
      </div>
    </div>
  </div>
</div>
