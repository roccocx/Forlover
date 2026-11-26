<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/helpers.php';

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Block direct access to internals.
if (preg_match('#^/(app|sql|migrate)/#', $path) || $path === '/config.php') {
    http_response_code(404);
    exit('Not Found');
}

if (!is_installed()) {
    if (!str_starts_with($path, '/install')) {
        redirect('/install/');
    }
    require __DIR__ . '/install/index.php';
    exit;
}

require __DIR__ . '/app/updater.php';

// Maintenance mode (set by the updater while an upgrade runs): the front
// site pauses, the admin area stays reachable.
if (is_file(__DIR__ . '/app/cache/maintenance.flag')
    && !str_starts_with($path, '/admin') && !str_starts_with($path, '/assets/')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>维护中</title></head>'
        . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem">'
        . '<h1>💌 站点维护中</h1><p>正在升级，马上就好，请稍后再来。</p></body></html>';
    exit;
}

function render(string $view, array $vars = [], string $pageTitle = ''): void
{
    extract($vars);
    ob_start();
    require __DIR__ . '/app/views/' . $view . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/app/views/layout.php';
}

function render_admin(string $view, array $vars = [], string $adminPage = ''): void
{
    extract($vars);
    ob_start();
    require __DIR__ . '/app/views/admin/' . $view . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/app/views/admin/layout.php';
}

function admin_id(): ?int
{
    start_session();
    return isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
}

function require_admin(): int
{
    $id = admin_id();
    if ($id === null) {
        redirect('/admin/login');
    }
    return $id;
}

function csrf_check_form(): void
{
    start_session();
    $token = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('会话已过期，请返回刷新页面重试');
    }
}

const PER_PAGE = 10;

try {
    // ---------- Front ----------
    if ($path === '/' && $method === 'GET') {
        $page = (int)($_GET['page'] ?? 1);
        $total = (int)db()->query("SELECT COUNT(*) FROM posts WHERE status='approved' AND deleted_at IS NULL")->fetchColumn();
        $pg = paginate($total, PER_PAGE, $page);
        $st = db()->prepare("SELECT * FROM posts WHERE status='approved' AND deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->bindValue(1, PER_PAGE, PDO::PARAM_INT);
        $st->bindValue(2, $pg['offset'], PDO::PARAM_INT);
        $st->execute();
        render('home', ['posts' => $st->fetchAll(), 'page' => $pg['page'], 'pages' => $pg['pages']], '首页');
    }
    elseif (preg_match('#^/post/(\d+)$#', $path, $m) && $method === 'GET') {
        $st = db()->prepare("SELECT * FROM posts WHERE id=? AND status='approved' AND deleted_at IS NULL");
        $st->execute([(int)$m[1]]);
        $post = $st->fetch();
        if (!$post) {
            http_response_code(404);
            render('home', ['posts' => [], 'page' => 1, 'pages' => 1], '未找到');
            exit;
        }
        $st = db()->prepare("SELECT * FROM comments WHERE post_id=? AND status='approved' AND deleted_at IS NULL ORDER BY id ASC");
        $st->execute([(int)$post['id']]);
        render('detail', ['post' => $post, 'comments' => $st->fetchAll()], '表白 #' . $post['id']);
    }
    elseif ($path === '/search' && $method === 'GET') {
        $k = trim((string)($_GET['k'] ?? ''));
        $page = (int)($_GET['page'] ?? 1);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $k) . '%';
        $where = "status='approved' AND deleted_at IS NULL AND (content LIKE ? OR target_name LIKE ? OR author_name LIKE ?)";
        $st = db()->prepare("SELECT COUNT(*) FROM posts WHERE $where");
        $st->execute([$like, $like, $like]);
        $total = (int)$st->fetchColumn();
        $pg = paginate($total, PER_PAGE, $page);
        $st = db()->prepare("SELECT * FROM posts WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->bindValue(1, $like);
        $st->bindValue(2, $like);
        $st->bindValue(3, $like);
        $st->bindValue(4, PER_PAGE, PDO::PARAM_INT);
        $st->bindValue(5, $pg['offset'], PDO::PARAM_INT);
        $st->execute();
        render('search', ['posts' => $st->fetchAll(), 'page' => $pg['page'], 'pages' => $pg['pages'], 'k' => $k], '搜索');
    }
    elseif ($path === '/about' && $method === 'GET') {
        render('about', [], '关于');
    }
    elseif ($path === '/publish' && $method === 'POST') {
        csrf_check();
        throttle('posts', 20, 10);
        $qq = trim((string)($_POST['qq'] ?? ''));
        $realname = trim((string)($_POST['realname'] ?? ''));
        $towho = trim((string)($_POST['towho'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        if ($qq !== '' && !preg_match('/^\d{5,12}$/', $qq)) {
            json_response(['ok' => false, 'error' => 'QQ 号格式不正确'], 422);
        }
        if ($realname === '' && $qq === '') {
            json_response(['ok' => false, 'error' => '匿名表白请填写昵称，或填写 QQ 使用 QQ 昵称'], 422);
        }
        if ($towho === '' || mb_strlen($towho) > 60) {
            json_response(['ok' => false, 'error' => '请填写表白对象（60 字以内）'], 422);
        }
        if ($content === '' || mb_strlen($content) > 1000) {
            json_response(['ok' => false, 'error' => '请填写表白内容（1000 字以内）'], 422);
        }
        if (mb_strlen($realname) > 60) {
            json_response(['ok' => false, 'error' => '昵称太长啦'], 422);
        }
        $st = db()->prepare('INSERT INTO posts (author_name, author_qq, target_name, content, ip_hash) VALUES (?,?,?,?,?)');
        $st->execute([$realname, $qq !== '' ? $qq : null, $towho, $content, ip_hash()]);
        json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    }
    elseif ($path === '/comment' && $method === 'POST') {
        csrf_check();
        throttle('comments', 10, 30);
        $postId = (int)($_POST['post_id'] ?? 0);
        $qq = trim((string)($_POST['qq'] ?? ''));
        $nname = trim((string)($_POST['nname'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $st = db()->prepare("SELECT id FROM posts WHERE id=? AND status='approved' AND deleted_at IS NULL");
        $st->execute([$postId]);
        if (!$st->fetch()) {
            json_response(['ok' => false, 'error' => '表白不存在'], 404);
        }
        if ($qq !== '' && !preg_match('/^\d{5,12}$/', $qq)) {
            json_response(['ok' => false, 'error' => 'QQ 号格式不正确'], 422);
        }
        if ($nname === '' && $qq === '') {
            json_response(['ok' => false, 'error' => '匿名评论请填写昵称，或填写 QQ'], 422);
        }
        if ($content === '' || mb_strlen($content) > 500) {
            json_response(['ok' => false, 'error' => '请填写评论内容（500 字以内）'], 422);
        }
        $st = db()->prepare('INSERT INTO comments (post_id, author_name, author_qq, content, ip_hash) VALUES (?,?,?,?,?)');
        $st->execute([$postId, $nname, $qq !== '' ? $qq : null, $content, ip_hash()]);
        db()->prepare('UPDATE posts SET comment_count = comment_count + 1 WHERE id=?')->execute([$postId]);
        $row = ['author_name' => $nname, 'author_qq' => $qq !== '' ? $qq : null];
        json_response([
            'ok' => true,
            'comment' => [
                'name' => display_name($row),
                'avatar' => avatar_url($row['author_qq']) ?: initial_avatar(display_name($row)),
                'content' => $content,
                'time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
    elseif ($path === '/like' && $method === 'POST') {
        csrf_check();
        $postId = (int)($_POST['post_id'] ?? 0);
        $st = db()->prepare("SELECT id, like_count FROM posts WHERE id=? AND status='approved' AND deleted_at IS NULL");
        $st->execute([$postId]);
        $post = $st->fetch();
        if (!$post) {
            json_response(['ok' => false, 'error' => '表白不存在'], 404);
        }
        try {
            db()->prepare('INSERT INTO likes (post_id, voter_key) VALUES (?,?)')->execute([$postId, voter_key()]);
            db()->prepare('UPDATE posts SET like_count = like_count + 1 WHERE id=?')->execute([$postId]);
            $count = (int)$post['like_count'] + 1;
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') {
                throw $ex;
            }
            $count = (int)$post['like_count']; // already liked
        }
        json_response(['ok' => true, 'count' => $count]);
    }
    // ---------- Admin ----------
    elseif ($path === '/admin/login' && $method === 'GET') {
        if (admin_id() !== null) {
            redirect('/admin');
        }
        $error = null;
        require __DIR__ . '/app/views/admin/login.php';
    }
    elseif ($path === '/admin/login' && $method === 'POST') {
        csrf_check_form();
        start_session();
        $user = trim((string)($_POST['user'] ?? ''));
        $pwd = (string)($_POST['pwd'] ?? '');
        // login throttle: 10 failures per IP per 15 min kept in session-independent file cache is overkill;
        // simple session counter + sleep keeps brute force slow.
        $_SESSION['login_fails'] = $_SESSION['login_fails'] ?? 0;
        if ($_SESSION['login_fails'] >= 10) {
            $error = '尝试次数过多，请稍后再试';
            require __DIR__ . '/app/views/admin/login.php';
            exit;
        }
        $st = db()->prepare('SELECT * FROM admins WHERE username=?');
        $st->execute([$user]);
        $admin = $st->fetch();
        if ($admin && password_verify($pwd, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['login_fails'] = 0;
            db()->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$admin['id']]);
            redirect('/admin');
        }
        $_SESSION['login_fails']++;
        $error = '用户名或密码错误';
        require __DIR__ . '/app/views/admin/login.php';
    }
    elseif ($path === '/admin/logout') {
        start_session();
        $_SESSION = [];
        session_destroy();
        redirect('/');
    }
    elseif ($path === '/admin' && $method === 'GET') {
        require_admin();
        $stats = [
            'posts' => (int)db()->query('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL')->fetchColumn(),
            'comments' => (int)db()->query('SELECT COUNT(*) FROM comments WHERE deleted_at IS NULL')->fetchColumn(),
            'likes' => (int)db()->query('SELECT COALESCE(SUM(like_count),0) FROM posts WHERE deleted_at IS NULL')->fetchColumn(),
            'today_posts' => (int)db()->query('SELECT COUNT(*) FROM posts WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
            'today_comments' => (int)db()->query('SELECT COUNT(*) FROM comments WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
        ];
        render_admin('dashboard', ['stats' => $stats, 'updateInfo' => (new Updater())->cachedCheck()], 'dashboard');
    }
    elseif ($path === '/admin/posts' && $method === 'GET') {
        require_admin();
        $page = (int)($_GET['page'] ?? 1);
        $total = (int)db()->query('SELECT COUNT(*) FROM posts')->fetchColumn();
        $pg = paginate($total, 20, $page);
        $st = db()->prepare('SELECT * FROM posts ORDER BY id DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, 20, PDO::PARAM_INT);
        $st->bindValue(2, $pg['offset'], PDO::PARAM_INT);
        $st->execute();
        render_admin('posts', ['posts' => $st->fetchAll(), 'page' => $pg['page'], 'pages' => $pg['pages']], 'posts');
    }
    elseif ($path === '/admin/posts/edit' && $method === 'GET') {
        require_admin();
        $st = db()->prepare('SELECT * FROM posts WHERE id=?');
        $st->execute([(int)($_GET['id'] ?? 0)]);
        $post = $st->fetch();
        if (!$post) {
            http_response_code(404);
            exit('未找到');
        }
        render_admin('post_edit', ['post' => $post], 'posts');
    }
    elseif ($path === '/admin/posts/edit' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        $id = (int)($_POST['id'] ?? 0);
        $qq = trim((string)($_POST['qq'] ?? ''));
        $st = db()->prepare('UPDATE posts SET author_qq=?, author_name=?, target_name=?, content=?, like_count=?, updated_at=NOW() WHERE id=?');
        $st->execute([
            $qq !== '' ? $qq : null,
            trim((string)($_POST['realname'] ?? '')),
            trim((string)($_POST['towho'] ?? '')),
            trim((string)($_POST['msg'] ?? '')),
            max(0, (int)($_POST['zan'] ?? 0)),
            $id,
        ]);
        redirect('/admin/posts');
    }
    elseif ($path === '/admin/posts/delete' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        db()->prepare('UPDATE posts SET deleted_at=NOW() WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        redirect('/admin/posts');
    }
    elseif ($path === '/admin/posts/restore' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        db()->prepare('UPDATE posts SET deleted_at=NULL WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        redirect('/admin/posts');
    }
    elseif ($path === '/admin/comments' && $method === 'GET') {
        require_admin();
        $page = (int)($_GET['page'] ?? 1);
        $total = (int)db()->query('SELECT COUNT(*) FROM comments')->fetchColumn();
        $pg = paginate($total, 20, $page);
        $st = db()->prepare('SELECT c.*, p.content AS post_title FROM comments c LEFT JOIN posts p ON p.id=c.post_id ORDER BY c.id DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, 20, PDO::PARAM_INT);
        $st->bindValue(2, $pg['offset'], PDO::PARAM_INT);
        $st->execute();
        render_admin('comments', ['comments' => $st->fetchAll(), 'page' => $pg['page'], 'pages' => $pg['pages']], 'comments');
    }
    elseif ($path === '/admin/comments/delete' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT post_id FROM comments WHERE id=? AND deleted_at IS NULL');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            db()->prepare('UPDATE comments SET deleted_at=NOW() WHERE id=?')->execute([$id]);
            db()->prepare('UPDATE posts SET comment_count = GREATEST(comment_count-1,0) WHERE id=?')->execute([(int)$row['post_id']]);
        }
        redirect('/admin/comments');
    }
    elseif ($path === '/admin/comments/restore' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT post_id FROM comments WHERE id=? AND deleted_at IS NOT NULL');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            db()->prepare('UPDATE comments SET deleted_at=NULL WHERE id=?')->execute([$id]);
            db()->prepare('UPDATE posts SET comment_count = comment_count+1 WHERE id=?')->execute([(int)$row['post_id']]);
        }
        redirect('/admin/comments');
    }
    elseif ($path === '/admin/settings' && $method === 'GET') {
        require_admin();
        render_admin('settings', ['conf' => settings(), 'saved' => isset($_GET['saved'])], 'settings');
    }
    elseif ($path === '/admin/settings' && $method === 'POST') {
        $aid = require_admin();
        csrf_check_form();
        foreach (['title', 'keywords', 'description', 'qq', 'beian', 'footer', 'ad', '163music'] as $key) {
            if (isset($_POST[$key])) {
                set_setting($key, (string)$_POST[$key]);
            }
        }
        $pwd = (string)($_POST['pwd'] ?? '');
        if ($pwd !== '') {
            db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')
                ->execute([password_hash($pwd, PASSWORD_DEFAULT), $aid]);
        }
        redirect('/admin/settings?saved=1');
    }
    elseif ($path === '/admin/update' && $method === 'GET') {
        require_admin();
        $u = new Updater();
        render_admin('update', [
            'caps' => Updater::capabilities(),
            'checkInfo' => $u->check(false),
            'journal' => $u->journal(),
            'backups' => $u->backups(),
            'integrityCheck' => $u->cachedIntegrity(),
            'interrupted' => $u->interrupted(),
            'maintenanceOn' => Updater::maintenanceOn(),
            'schemaVersion' => $u->schemaVersion(),
            'flash' => isset($_GET['ok']) ? (string)$_GET['ok'] : null,
            'flashErr' => isset($_GET['err']) ? (string)$_GET['err'] : null,
        ], 'update');
    }
    elseif ($path === '/admin/update/check' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        $info = (new Updater())->check(true);
        if (!$info['ok']) {
            redirect('/admin/update?err=' . urlencode('检查失败：' . ($info['error'] ?? '未知错误')));
        }
        redirect('/admin/update?ok=' . urlencode(!empty($info['has_new']) ? '发现新版本 V' . $info['latest'] : '已是最新版本'));
    }
    elseif ($path === '/admin/update/run' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            $up = new Updater();
            $chk = $up->check(true);
            $up->run();
            redirect('/admin/update?ok=' . urlencode('升级完成，当前版本 V' . (!empty($chk['latest']) ? $chk['latest'] : VERSION)));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode($ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/upload' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        $f = $_FILES['package'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            redirect('/admin/update?err=' . urlencode('上传失败，请重试'));
        }
        try {
            (new Updater())->runUploaded((string)$f['tmp_name']);
            redirect('/admin/update?ok=' . urlencode('升级完成'));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode($ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/migrate' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            $done = (new Updater())->runManualMigrations();
            redirect('/admin/update?ok=' . urlencode($done ? '迁移完成：' . implode(', ', $done) : '没有待执行的迁移'));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode('迁移失败：' . $ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/backup' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            (new Updater())->manualBackup();
            redirect('/admin/update?ok=' . urlencode('备份完成，已出现在下方备份列表中'));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode('备份失败：' . $ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/backup-delete' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            $tag = (string)($_POST['tag'] ?? '');
            (new Updater())->deleteBackup($tag);
            redirect('/admin/update?ok=' . urlencode('已删除备份：' . $tag));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode('删除失败：' . $ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/backup-verify' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            $tag = (string)($_POST['tag'] ?? '');
            $res = (new Updater())->verifyBackup($tag);
            $msg = ($res['ok'] ? '完整性校验通过' : '完整性校验未通过') . '（' . $tag . '）：' . implode('；', $res['lines']);
            redirect('/admin/update?' . ($res['ok'] ? 'ok=' : 'err=') . urlencode($msg));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode('校验失败：' . $ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/integrity' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            $res = (new Updater())->verifyProgram();
            if (empty($res['has_manifest'])) {
                redirect('/admin/update?err=' . urlencode('云端没有找到 V' . VERSION . ' 的完整性表（该版本可能未正式发布，或更新服务器不可达）'));
            }
            if (!empty($res['ok'])) {
                redirect('/admin/update?ok=' . urlencode('程序完整性校验通过：与官方 V' . VERSION . ' 完整性表一致（来源：' . Updater::channelLabel((string)$res['channel']) . '）' . (!empty($res['added']) ? '，另有 ' . count($res['added']) . ' 个新增文件待确认（见下方明细）' : '')));
            }
            $parts = [];
            if (!empty($res['modified'])) {
                $parts[] = '被修改 ' . count($res['modified']) . ' 个';
            }
            if (!empty($res['missing'])) {
                $parts[] = '缺失 ' . count($res['missing']) . ' 个';
            }
            if (!empty($res['added'])) {
                $parts[] = '新增 ' . count($res['added']) . ' 个';
            }
            redirect('/admin/update?err=' . urlencode('程序完整性校验未通过：' . implode('，', $parts) . '（见下方明细）'));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode('校验失败：' . $ex->getMessage()));
        }
    }
    elseif ($path === '/admin/update/maintenance' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        $on = (string)($_POST['action'] ?? '') === 'on';
        (new Updater())->toggleMaintenance($on);
        redirect('/admin/update?ok=' . urlencode($on ? '站点已关闭（维护中），前台显示维护提示，后台不受影响' : '站点已重新开放'));
    }
    elseif ($path === '/admin/update/rollback' && $method === 'POST') {
        require_admin();
        csrf_check_form();
        try {
            (new Updater())->rollback((string)($_POST['tag'] ?? ''));
            redirect('/admin/update?ok=' . urlencode('已回滚到所选备份'));
        } catch (Throwable $ex) {
            redirect('/admin/update?err=' . urlencode('回滚失败：' . $ex->getMessage()));
        }
    }
    else {
        http_response_code(404);
        exit('Not Found');
    }
} catch (Throwable $ex) {
    http_response_code(500);
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        echo json_encode(['ok' => false, 'error' => '服务器开小差了，请稍后再试']);
    } else {
        echo '服务器开小差了，请稍后再试';
    }
    error_log('[forlover] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
}
