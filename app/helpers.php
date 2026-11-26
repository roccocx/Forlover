<?php
defined('FORLOVER') || exit;

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function client_ip(): string
{
    // Behind Cloudflare in production; the CF header is the real visitor IP.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function ip_hash(): string
{
    return hash('sha256', client_ip() . '|' . (config()['salt'] ?? 'forlover'));
}

function voter_key(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', client_ip() . '|' . $ua . '|' . (config()['salt'] ?? 'forlover'));
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    start_session();
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => '会话已过期，请刷新页面重试'], 419);
    }
}

function avatar_url(?string $qq): string
{
    if ($qq !== null && $qq !== '' && ctype_digit($qq)) {
        return 'https://q1.qlogo.cn/g?b=qq&nk=' . urlencode($qq) . '&s=100';
    }
    return '';
}

/** Inline SVG initial avatar for anonymous posters (no external dependency). */
function initial_avatar(string $name): string
{
    $ch = mb_substr(trim($name) !== '' ? trim($name) : '匿', 0, 1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#ff8fb3"/><stop offset="1" stop-color="#ff5c8a"/>'
        . '</linearGradient></defs>'
        . '<rect width="100" height="100" fill="url(#g)"/>'
        . '<text x="50" y="66" font-size="44" fill="#fff" text-anchor="middle" font-family="sans-serif">'
        . htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** Display name: explicit name wins; otherwise live QQ nickname (cached 24h); otherwise the QQ number. */
function display_name(array $row): string
{
    $name = trim((string)($row['author_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $qq = trim((string)($row['author_qq'] ?? ''));
    if ($qq !== '') {
        return qq_nickname($qq) ?? $qq;
    }
    return '匿名';
}

function qq_nickname(string $qq): ?string
{
    if (!ctype_digit($qq)) {
        return null;
    }
    $dir = ROOT . '/app/cache';
    $file = $dir . '/qq_' . $qq . '.json';
    if (is_file($file) && time() - (int)filemtime($file) < 86400) {
        $data = json_decode((string)file_get_contents($file), true);
        return $data['nick'] ?? null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $raw = @file_get_contents('https://r.qzone.qq.com/fcg-bin/cgi_get_portrait.fcg?uins=' . urlencode($qq), false, $ctx);
    if ($raw === false) {
        return null;
    }
    $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
    if (!preg_match('/portraitCallBack\((.*)\)/s', $raw, $m)) {
        return null;
    }
    $data = json_decode($m[1], true);
    $nick = $data[$qq][6] ?? null;
    if (is_string($nick) && $nick !== '') {
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            @file_put_contents($file, json_encode(['nick' => $nick], JSON_UNESCAPED_UNICODE));
        }
        return $nick;
    }
    return null;
}

/** Simple per-IP throttle backed by posts/comments timestamps. */
function throttle(string $table, int $minSeconds, int $maxPerHour): void
{
    $hash = ip_hash();
    $st = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE `ip_hash`=? AND `created_at` > NOW() - INTERVAL 1 HOUR");
    $st->execute([$hash]);
    if ((int)$st->fetchColumn() >= $maxPerHour) {
        json_response(['ok' => false, 'error' => '操作太频繁啦，请稍后再试'], 429);
    }
    $st = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE `ip_hash`=? AND `created_at` > NOW() - INTERVAL ? SECOND");
    $st->execute([$hash, $minSeconds]);
    if ((int)$st->fetchColumn() > 0) {
        json_response(['ok' => false, 'error' => '发得太快啦，歇一会儿再试'], 429);
    }
}

function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage];
}
