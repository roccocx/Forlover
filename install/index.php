<?php
declare(strict_types=1);
// SillyLi 表白墙 V2 安装向导（独立运行，不依赖主程序）
if (!defined('FORLOVER') && is_file(dirname(__DIR__) . '/app/bootstrap.php')) {
    require dirname(__DIR__) . '/app/bootstrap.php'; // for the VERSION constant only
}
$root = defined('ROOT') ? ROOT : dirname(__DIR__);
$lockFile = $root . '/install.lock';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$page = static function (string $title, string $body): void {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' - SillyLi表白墙安装向导</title>'
        . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#fff5f8;margin:0;padding:2rem 1rem}'
        . '.box{max-width:640px;margin:0 auto;background:#fff;border-radius:1rem;box-shadow:0 .5rem 2rem rgba(255,92,138,.15);padding:2rem}'
        . 'h1{font-size:1.4rem;margin-top:0}.ok{color:#198754}.bad{color:#dc3545}'
        . 'label{display:block;margin:.8rem 0 .3rem;font-weight:600}input{width:100%;padding:.5rem;border:1px solid #ddd;border-radius:.5rem;box-sizing:border-box}'
        . '.btn{display:inline-block;background:#ff5c8a;color:#fff;border:0;padding:.6rem 1.6rem;border-radius:.5rem;cursor:pointer;font-size:1rem;text-decoration:none;margin-top:1.2rem}'
        . 'table{width:100%;border-collapse:collapse}td{padding:.35rem 0;border-bottom:1px solid #f3f3f3}'
        . '.alert{background:#f8d7da;color:#842029;border-radius:.5rem;padding:.8rem 1rem}</style></head><body>'
        . '<div class="box"><h1>💌 SillyLi表白墙 V2 安装向导</h1>' . $body . '</div></body></html>';
    exit;
};

if (is_file($lockFile)) {
    $page('已安装', '<p>本站已经安装过了。如需重新安装，请先删除站根目录的 <code>install.lock</code> 与 <code>config.php</code>。</p><p><a href="/">返回首页</a></p>');
}

$step = (string)($_GET['step'] ?? '0');

if ($step === '0') {
    $hasCurl = extension_loaded('curl');
    $hasFopen = (bool)ini_get('allow_url_fopen');
    $upgradeMode = ($hasCurl || $hasFopen) ? '全自动（一键升级）' : '半自动（后台上传升级包）';
    $checks = [
        ['PHP 版本 ≥ 8.1（当前 ' . PHP_VERSION . '）', PHP_VERSION_ID >= 80100, true],
        ['PDO 扩展', extension_loaded('pdo'), true],
        ['pdo_mysql 扩展', extension_loaded('pdo_mysql'), true],
        ['mbstring 扩展', extension_loaded('mbstring'), true],
        ['站根目录可写（生成 config.php / install.lock）', is_writable($root), true],
        ['app/cache 目录可写（QQ 昵称缓存，可选）', is_writable($root . '/app/cache') || is_writable($root . '/app'), false],
        ['zip 扩展（缺失时用内置纯 PHP 解压兜底，不影响升级）', extension_loaded('zip'), false],
        ['curl 扩展 / allow_url_fopen（一键升级的下载通道，二选一即可）', $hasCurl || $hasFopen, false],
    ];
    $rows = '';
    $pass = true;
    foreach ($checks as [$label, $ok, $required]) {
        if ($required && !$ok) {
            $pass = false;
        }
        $rows .= '<tr><td>' . h($label) . '</td><td class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? '✓ 通过' : ($required ? '✗ 未通过' : '△ 可选')) . '</td></tr>';
    }
    $next = $pass
        ? '<a class="btn" href="?step=1">下一步：填写配置</a>'
        : '<p class="alert">有必备项未通过，请处理后刷新本页。</p>';
    $page('环境检测', '<table>' . $rows . '</table>'
        . '<p style="margin-top:1rem">本机一键升级能力：<strong>' . h($upgradeMode) . '</strong>（另有手动模式兜底：FTP 覆盖文件＋后台执行数据库迁移）</p>' . $next);
}

if ($step === '1' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $page('填写配置', '
    <form method="post" action="?step=1">
      <h3>数据库</h3>
      <label>数据库主机</label><input name="db_host" value="127.0.0.1" required>
      <label>数据库端口</label><input name="db_port" value="3306" required>
      <label>数据库名（不存在会自动创建）</label><input name="db_name" value="forlover_v2" required>
      <label>数据库用户名</label><input name="db_user" required>
      <label>数据库密码</label><input name="db_pass" type="password">
      <h3 style="margin-top:1.6rem">管理员与站点</h3>
      <label>管理员用户名</label><input name="admin_user" value="admin" required>
      <label>管理员密码</label><input name="admin_pass" type="password" required>
      <label>网站标题</label><input name="site_title" value="SillyLi表白墙" required>
      <button class="btn" type="submit">开始安装</button>
    </form>');
}

if ($step === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $siteTitle = trim((string)($_POST['site_title'] ?? 'SillyLi表白墙'));

    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $page('安装失败', '<p class="alert">数据库名只能含字母、数字和下划线。</p><p><a href="?step=1">返回</a></p>');
    }
    if ($adminUser === '' || $adminPass === '') {
        $page('安装失败', '<p class="alert">管理员用户名和密码不能为空。</p><p><a href="?step=1">返回</a></p>');
    }
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort),
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");

        $sql = (string)file_get_contents($root . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql); // strip comment lines first
        foreach (preg_split('/;\s*\R/', (string)$sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }

        $defaults = [
            'title' => $siteTitle,
            'keywords' => '表白墙,告白墙,SillyLi',
            'description' => 'SillyLi表白墙系统',
            'footer' => '',
            'ad' => '',
            'qq' => '',
            '163music' => '',
            'beian' => '',
            'time' => (string)time(),
            'schema_version' => defined('VERSION') ? VERSION : '2.0.0',
            'applied_migrations' => '[]',
        ];
        $st = $pdo->prepare('INSERT INTO settings (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)');
        foreach ($defaults as $k => $v) {
            $st->execute([$k, $v]);
        }

        $st = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?,?)');
        $st->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

        $count = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
        if ($count === 0) {
            $st = $pdo->prepare('INSERT INTO posts (author_name, author_qq, target_name, content, like_count) VALUES (?,?,?,?,9)');
            $st->execute(['SillyLi', null, 'EveryOne', '欢迎大家使用SillyLi表白墙程序，在此你可以匿名，也可以展示自己的QQ头像昵称进行表白让你喜欢的人注意到你！祝所有小伙伴们幸福美满！']);
            $st->execute(['匿名', null, 'Ta', '或许还不够勇敢？没关系来用匿名写出你心中的那段话吧！没人会知道你是谁。']);
        }

        $cfg = [
            'db' => ['host' => $dbHost, 'port' => $dbPort, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass],
            'salt' => bin2hex(random_bytes(16)),
        ];
        $code = "<?php\n// Generated by the installer on " . date('Y-m-d H:i:s') . "\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents($root . '/config.php', $code) === false) {
            throw new RuntimeException('config.php 写入失败，请检查站根目录权限');
        }
        file_put_contents($lockFile, date('Y-m-d H:i:s'));
    } catch (Throwable $ex) {
        $page('安装失败', '<p class="alert">安装失败：' . h($ex->getMessage()) . '</p><p><a href="?step=1">返回重试</a></p>');
    }
    $page('安装成功', '
      <p class="ok">✓ 安装完成！</p>
      <p>管理员账号：<strong>' . h($adminUser) . '</strong>（密码为你刚填写的）</p>
      <p><a class="btn" href="/">访问前台</a> <a class="btn" href="/admin">进入后台</a></p>
      <p class="alert">安全提醒：请删除或改名 <code>install/</code> 目录（install.lock 已生成，重复安装已被拦截，但删掉更稳妥）。</p>');
}

$page('安装向导', '<p><a class="btn" href="?step=0">开始安装</a></p>');
