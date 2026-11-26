<?php
declare(strict_types=1);
// SillyLi 表白墙 V1 → V2 数据迁移（CLI）
// 用法:
//   php migrate.php --old-db=forlover_sillyli --old-user=root --old-pass=xxx \
//                   --new-db=forlover_v2    --new-user=root --new-pass=xxx \
//                   --admin-user=admin --admin-pass='新密码' [--force]
// 前提: 新库已由安装向导或 sql/schema.sql 建好表结构。
// 安全: 新库 posts/comments 非空时拒绝运行, --force 会先清空新库四张业务表。

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$opt = getopt('', ['old-db:', 'old-user:', 'old-pass::', 'new-db:', 'new-user:', 'new-pass::', 'admin-user:', 'admin-pass:', 'force', 'host::', 'port::']);
$host = $opt['host'] ?? '127.0.0.1';
$port = (int)($opt['port'] ?? 3306);
foreach (['old-db', 'new-db', 'admin-user', 'admin-pass'] as $req) {
    if (empty($opt[$req])) {
        exit("缺少参数 --$req\n");
    }
}

function pdo_for(string $host, int $port, string $db, string $user, string $pass): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$old = pdo_for($host, $port, $opt['old-db'], $opt['old-user'] ?? 'root', $opt['old-pass'] ?? '');
$new = pdo_for($host, $port, $opt['new-db'], $opt['new-user'] ?? 'root', $opt['new-pass'] ?? '');

$existing = (int)$new->query('SELECT COUNT(*) FROM posts')->fetchColumn()
    + (int)$new->query('SELECT COUNT(*) FROM comments')->fetchColumn();
if ($existing > 0) {
    if (!isset($opt['force'])) {
        exit("新库已有数据 (posts+comments=$existing), 拒绝迁移。确需重跑请加 --force\n");
    }
    $new->exec('SET FOREIGN_KEY_CHECKS=0');
    $new->exec('TRUNCATE TABLE likes');
    $new->exec('TRUNCATE TABLE comments');
    $new->exec('TRUNCATE TABLE posts');
    $new->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "已清空新库业务表\n";
}

// ---- settings: sillyli_config -> settings (skip admin creds) ----
$config = [];
foreach ($old->query('SELECT `k`,`v` FROM sillyli_config') as $row) {
    $config[$row['k']] = (string)$row['v'];
}
$skip = ['admin_user' => true, 'admin_pwd' => true];
$st = $new->prepare('INSERT INTO settings (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)');
$nSettings = 0;
foreach ($config as $k => $v) {
    if (isset($skip[$k])) {
        continue;
    }
    if ($k === 'time' && !ctype_digit($v)) {
        $v = (string)time(); // install.sql 的哨兵字符串等非数字值兜底
    }
    $st->execute([$k, $v]);
    $nSettings++;
}
echo "settings: $nSettings 条\n";

// ---- posts: sillyli_lovemsg -> posts ----
$insPost = $new->prepare(
    'INSERT INTO posts (id, author_name, author_qq, target_name, content, like_count, created_at) VALUES (?,?,?,?,?,?,?)'
);
$nPosts = 0;
foreach ($old->query('SELECT * FROM sillyli_lovemsg ORDER BY id') as $r) {
    $qq = trim((string)$r['qq']);
    $name = trim((string)$r['realname']);
    $ts = ctype_digit(trim((string)$r['time'])) ? (int)$r['time'] : time();
    $insPost->execute([
        (int)$r['id'],
        $name,
        $qq !== '' ? $qq : null,
        (string)$r['towho'],
        (string)$r['msg'],
        max(0, (int)$r['zan']),
        date('Y-m-d H:i:s', $ts),
    ]);
    $nPosts++;
}
echo "posts: $nPosts 条\n";

// ---- comments: sillyli_reply -> comments ----
$insComment = $new->prepare(
    'INSERT INTO comments (id, post_id, author_name, author_qq, content, created_at) VALUES (?,?,?,?,?,?)'
);
$nComments = 0;
$nOrphan = 0;
$validPosts = [];
foreach ($new->query('SELECT id FROM posts') as $r) {
    $validPosts[(int)$r['id']] = true;
}
foreach ($old->query('SELECT * FROM sillyli_reply ORDER BY id') as $r) {
    $gid = (int)$r['gid'];
    if (!isset($validPosts[$gid])) {
        $nOrphan++;
        continue;
    }
    $qq = trim((string)$r['qq']);
    $ts = ctype_digit(trim((string)$r['time'])) ? (int)$r['time'] : time();
    $insComment->execute([
        (int)$r['id'],
        $gid,
        trim((string)$r['nname']),
        $qq !== '' ? $qq : null,
        (string)$r['reply'],
        date('Y-m-d H:i:s', $ts),
    ]);
    $nComments++;
}
echo "comments: $nComments 条" . ($nOrphan ? " (跳过孤儿评论 $nOrphan 条)" : '') . "\n";

// ---- rebuild comment_count ----
$new->exec('UPDATE posts p SET comment_count = (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.deleted_at IS NULL)');

// ---- admin (new hashed credentials; old plaintext is NOT carried over) ----
$st = $new->prepare('INSERT INTO admins (username, password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
$st->execute([$opt['admin-user'], password_hash((string)$opt['admin-pass'], PASSWORD_DEFAULT)]);
echo "admin: {$opt['admin-user']} 已写入 (哈希)\n";

echo "迁移完成: posts=$nPosts comments=$nComments settings=$nSettings\n";
