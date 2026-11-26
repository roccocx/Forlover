<?php
defined('FORLOVER') || exit;
/** @var int $page @var int $pages @var string $base */
if (($pages ?? 1) > 1):
    $sep = str_contains($base, '?') ? '&' : '?';
?>
<nav aria-label="分页">
  <ul class="pagination justify-content-center">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e($base . $sep . 'page=' . ($page - 1)) ?>">上一页</a>
    </li>
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
      <a class="page-link" href="<?= e($base . $sep . 'page=' . $i) ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
      <a class="page-link" href="<?= e($base . $sep . 'page=' . ($page + 1)) ?>">下一页</a>
    </li>
  </ul>
</nav>
<?php endif; ?>
