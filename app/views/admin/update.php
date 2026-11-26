<?php
defined('FORLOVER') || exit;
/** @var array $caps @var ?array $checkInfo @var array $journal @var array $backups @var bool $interrupted @var ?string $flash @var ?string $flashErr @var ?array $integrityCheck */
$modeLabel = ['full' => '全自动（一键升级）', 'upload' => '半自动（上传升级包）'][$caps['mode']] ?? $caps['mode'];
?>
<h4 class="mb-4">系统升级</h4>

<?php if (!empty($flash)): ?><div class="alert alert-success"><?= e($flash) ?></div><?php endif; ?>
<?php if (!empty($flashErr)): ?><div class="alert alert-danger"><?= e($flashErr) ?></div><?php endif; ?>

<?php if (!empty($maintenanceOn)): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
  <span>⚠️ <strong>站点当前处于维护状态（已关站）</strong>，前台访问显示维护提示。手动升级完成后记得重新开站。</span>
  <form method="post" action="/admin/update/maintenance" class="m-0">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="off">
    <button class="btn btn-warning btn-sm" type="submit">重新开站</button>
  </form>
</div>
<?php endif; ?>

<?php if ($interrupted): ?>
<div class="alert alert-warning">
  <strong>检测到上次升级中断</strong>（可能因超时或断电）。站点可能处于中间状态，建议立即回滚到最近的备份。
  <?php if ($backups): ?>
  <form method="post" action="/admin/update/rollback" class="mt-2" onsubmit="return confirm('确定回滚到该备份吗？文件与数据库都会恢复到升级前。')">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="tag" value="<?= e((string)$backups[0]['tag']) ?>">
    <button class="btn btn-warning btn-sm" type="submit">回滚到最近备份（<?= e((string)$backups[0]['at']) ?>）</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h5 class="card-title">当前状态</h5>
      <table class="table table-sm mb-0">
        <tr><th style="width:9rem">当前版本</th><td>V<?= e(VERSION) ?>（数据库结构 <?= e((string)($schemaVersion ?? VERSION)) ?>）</td></tr>
        <tr><th>站点状态</th><td>
          <?php if (!empty($maintenanceOn)): ?><span class="text-warning fw-bold">维护中（已关站）</span>
          <form method="post" action="/admin/update/maintenance" class="d-inline ms-2">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="off">
            <button class="btn btn-outline-success btn-sm" type="submit">开站</button>
          </form>
          <?php else: ?><span class="text-success">开放中</span>
          <form method="post" action="/admin/update/maintenance" class="d-inline ms-2" onsubmit="return confirm('关站后前台将显示维护提示（后台不受影响），确定吗？')">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="on">
            <button class="btn btn-outline-warning btn-sm" type="submit">关站（手动升级用）</button>
          </form>
          <?php endif; ?>
        </td></tr>
        <tr><th>升级能力</th><td><?= e($modeLabel) ?>
          <br><span class="text-muted small">向下兼容：<?php if ($caps['mode'] === 'full'): ?>半自动（上传升级包） → 手动（FTP 覆盖＋数据库迁移）<?php else: ?>手动（FTP 覆盖＋数据库迁移）<?php endif; ?></span>
        </td></tr>
        <tr><th>下载方式</th><td><?= $caps['download'] === 'curl' ? 'cURL 扩展' : ($caps['download'] === 'stream' ? 'PHP 流（allow_url_fopen）' : '<span class="text-danger">不可用（请用上传模式）</span>') ?></td></tr>
        <tr><th>解压方式</th><td><?= $caps['zip'] === 'ziparchive' ? 'ZipArchive 扩展' : '内置纯 PHP 解压（兜底）' ?></td></tr>
      </table>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h5 class="card-title">检查更新</h5>
      <?php if ($checkInfo && !empty($checkInfo['ok'])): ?>
        <p>最新版本：<strong>V<?= e((string)$checkInfo['latest']) ?></strong>
          <?php if (!empty($checkInfo['released_at'])): ?><span class="text-muted">（<?= e((string)$checkInfo['released_at']) ?> 发布）</span><?php endif; ?>
        </p>
        <?php if (!empty($checkInfo['notes'])): ?><p class="text-muted" style="white-space:pre-line"><?= e((string)$checkInfo['notes']) ?></p><?php endif; ?>
        <?php if (!empty($checkInfo['has_new'])): ?>
          <div class="alert alert-info py-2">有新版本可用。升级前会自动备份代码与数据库，失败自动回滚。</div>
          <?php if ($caps['mode'] === 'full'): ?>
          <form method="post" action="/admin/update/run" onsubmit="return confirm('确定立即升级到 V<?= e((string)$checkInfo['latest']) ?> 吗？升级期间前台会短暂显示维护页。')">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <button class="btn btn-primary" type="submit">立即升级到 V<?= e((string)$checkInfo['latest']) ?></button>
          </form>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-success mb-0">✓ 已是最新版本</p>
        <?php endif; ?>
        <p class="text-muted small mt-2 mb-0">上次检查：<?= e(date('Y-m-d H:i:s', (int)($checkInfo['at'] ?? time()))) ?> · 来源：<?= e(Updater::channelLabel((string)($checkInfo['channel'] ?? ''))) ?></p>
      <?php elseif ($checkInfo && !empty($checkInfo['error'])): ?>
        <p class="text-danger">检查失败：<?= e((string)$checkInfo['error']) ?></p>
      <?php else: ?>
        <p class="text-muted">尚未检查过更新。</p>
      <?php endif; ?>
      <form method="post" action="/admin/update/check" class="mt-2">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-outline-secondary btn-sm" type="submit">重新检查</button>
      </form>
    </div></div>
  </div>
</div>

<div class="card mt-3"><div class="card-body">
  <h5 class="card-title">上传升级包（半自动）</h5>
  <p class="text-muted mb-2">当服务器无法直连更新源时：先从官方更新源手动下载升级包（version.json 同目录的 releases/*.zip），再在这里上传。系统会做整包与逐文件完整性校验，之后的备份、升级、回滚流程与一键升级完全相同。</p>
  <form method="post" action="/admin/update/upload" enctype="multipart/form-data" onsubmit="return confirm('确定用上传的升级包执行升级吗？')">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="input-group">
      <input class="form-control" type="file" name="package" accept=".zip" required>
      <button class="btn btn-primary" type="submit">上传并升级</button>
    </div>
  </form>
  <hr>
  <h6>手动模式（极端环境）</h6>
  <p class="text-muted mb-2">若连上传也不可用：把升级包在本地解压，将 <code>payload/</code> 内的文件覆盖到站根，再把包内的 <code>migrations/</code> 文件夹放到 <code>app/migrations-pending/</code>，然后点下面按钮只执行数据库迁移（文件备份请用主机面板或 FTP 自行完成）。</p>
  <form method="post" action="/admin/update/migrate" onsubmit="return confirm('确定执行 app/migrations-pending/ 中的待迁移项吗？')">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn btn-outline-primary btn-sm" type="submit">执行数据库迁移</button>
  </form>
</div></div>

<?php if (!empty($journal['steps'])): ?>
<div class="card mt-3"><div class="card-body">
  <h5 class="card-title">最近一次升级记录
    <span class="badge bg-<?= ($journal['status'] ?? '') === 'done' ? 'success' : ((($journal['status'] ?? '') === 'running') ? 'warning' : 'secondary') ?>"><?= e((string)($journal['status'] ?? '')) ?></span>
  </h5>
  <?php if (!empty($journal['detail'])): ?><p class="text-muted"><?= e((string)$journal['detail']) ?></p><?php endif; ?>
  <table class="table table-sm mb-0">
    <?php foreach ($journal['steps'] as $s): ?>
    <tr>
      <td style="width:5.5rem" class="text-muted"><?= e((string)$s['ts']) ?></td>
      <td style="width:8rem"><?= e((string)$s['step']) ?></td>
      <td style="width:3rem"><?= !empty($s['ok']) ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>' ?></td>
      <td class="text-muted"><?= e((string)($s['detail'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div></div>
<?php endif; ?>

<div class="card mt-3"><div class="card-body">
  <h5 class="card-title">备份与回滚</h5>
  <form method="post" action="/admin/update/backup" class="mb-3">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn btn-outline-primary btn-sm" type="submit">立即备份（代码＋数据库）</button>
    <span class="text-muted small ms-2">手动升级前先备份一份，出问题可一键回滚。</span>
  </form>
  <?php if (!$backups): ?>
    <p class="text-muted mb-0">暂无备份。每次升级前都会自动生成一份代码＋数据库备份，也可以随时手动备份。</p>
  <?php else: ?>
  <table class="table table-sm align-middle mb-0">
    <tr><th>时间</th><th>类型</th><th>版本变化</th><th>备份文件</th><th></th></tr>
    <?php foreach ($backups as $b): ?>
    <tr>
      <td><?= e((string)$b['at']) ?></td>
      <td><?= ['manual' => '手动备份', 'repair' => '修复前备份'][$b['kind'] ?? ''] ?? '升级前备份' ?></td>
      <td><?= e((string)$b['from']) ?> → <?= e((string)$b['to']) ?></td>
      <td class="small text-muted"><?= e((string)$b['code']) ?><br><?= e((string)$b['db']) ?></td>
      <td class="text-nowrap">
        <form method="post" action="/admin/update/rollback" class="d-inline-block" onsubmit="return confirm('确定回滚到该备份吗？当前文件与数据库会被覆盖。')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="tag" value="<?= e((string)$b['tag']) ?>">
          <button class="btn btn-outline-warning btn-sm" type="submit">回滚</button>
        </form>
        <form method="post" action="/admin/update/backup-verify" class="d-inline-block">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="tag" value="<?= e((string)$b['tag']) ?>">
          <button class="btn btn-outline-info btn-sm" type="submit">校验</button>
        </form>
        <form method="post" action="/admin/update/backup-delete" class="d-inline-block" onsubmit="return confirm('确定删除该备份吗？删除后不可恢复。')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="tag" value="<?= e((string)$b['tag']) ?>">
          <button class="btn btn-outline-danger btn-sm" type="submit">删除</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div></div>

<div class="card mt-3"><div class="card-body">
  <h5 class="card-title">程序完整性</h5>
  <p class="text-muted mb-2">校验时从更新服务器下载本站当前版本（V<?= e(VERSION) ?>）的官方完整性表，逐文件比对 SHA-256。对照表随每个版本发布、只来自云端，本站不留自建基准。</p>
  <form method="post" action="/admin/update/integrity">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn btn-outline-primary btn-sm" type="submit">校验程序文件</button>
  </form>
  <?php if ($integrityCheck && !empty($integrityCheck['has_manifest'])): ?>
  <div class="mt-3">
    <p class="mb-1">上次校验：<?= e((string)$integrityCheck['at']) ?> · 对照版本 V<?= e((string)$integrityCheck['version']) ?> · 来源：<?= e(Updater::channelLabel((string)($integrityCheck['channel'] ?? ''))) ?> ·
      <?php if (!empty($integrityCheck['ok'])): ?><span class="text-success">✓ 完整，无改动无缺失</span><?php else: ?><span class="text-danger">✗ 发现异常</span><?php endif; ?>
    </p>
    <?php foreach (['modified' => '被修改', 'missing' => '缺失', 'added' => '新增（不在官方完整性表内）'] as $k => $label): ?>
      <?php if (!empty($integrityCheck[$k])): ?>
      <p class="mb-1 small"><strong><?= $label ?>（<?= count($integrityCheck[$k]) ?>）：</strong></p>
      <ul class="small text-muted mb-2">
        <?php foreach (array_slice($integrityCheck[$k], 0, 100) as $f): ?><li><code><?= e((string)$f) ?></code></li><?php endforeach; ?>
        <?php if (count($integrityCheck[$k]) > 100): ?><li>…还有 <?= count($integrityCheck[$k]) - 100 ?> 个</li><?php endif; ?>
      </ul>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (empty($integrityCheck['ok'])): ?>
    <form method="post" action="/admin/update/repair" class="mt-2" onsubmit="return confirm('确定按官方 V<?= e(VERSION) ?> 一键修复吗？将先自动备份，再用官方文件覆盖被修改/缺失的文件（不影响数据与配置）。')">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-warning btn-sm" type="submit">一键修复（按官方 V<?= e(VERSION) ?> 还原）</button>
      <span class="text-muted small ms-2">若更新服务器已不再提供本版本，将提示改为升级到最近版本。</span>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div></div>
