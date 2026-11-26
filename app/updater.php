<?php
defined('FORLOVER') || exit;

require_once __DIR__ . '/ziputil.php';
require_once __DIR__ . '/sha256.php';

/**
 * One-click updater: check → backup → download → verify (package + per-file
 * hashes) → maintenance → apply → DB migrations → self-check, with automatic
 * rollback. No shell functions are used anywhere; every capability has a
 * fallback chain so shared hosts missing ext-zip/ext-curl still work
 * (see capabilities()).
 */
final class Updater
{
    public const DEFAULT_CHANNELS = [
        'https://api.mao.ms/v1/forlover',
        'https://api.cxcx.club/v1/forlover',
    ];

    private mixed $lockHandle = null;

    // ---------- paths ----------
    public static function cacheDir(): string
    {
        return ROOT . '/app/cache';
    }
    public static function workDir(): string
    {
        return ROOT . '/app/cache/update';
    }
    public static function backupDir(): string
    {
        return ROOT . '/app/backups';
    }
    private static function journalFile(): string
    {
        return self::workDir() . '/journal.json';
    }
    private static function flagFile(): string
    {
        return self::cacheDir() . '/maintenance.flag';
    }

    /**
     * Version comparison with semver-style pre-release handling.
     * PHP's version_compare does not know the "-pre" suffix (it sorts
     * 2.0.0-pre ABOVE 2.0.0), so split the suffix off first: a suffixed
     * build sorts before the same base release.
     */
    public static function verCmp(string $a, string $b): int
    {
        $split = static function (string $v): array {
            $pos = strpos($v, '-');
            return $pos === false ? [$v, ''] : [substr($v, 0, $pos), substr($v, $pos + 1)];
        };
        [$baseA, $sufA] = $split($a);
        [$baseB, $sufB] = $split($b);
        $c = version_compare($baseA, $baseB);
        if ($c !== 0) {
            return $c;
        }
        if ($sufA === $sufB) {
            return 0;
        }
        if ($sufA === '') {
            return 1;
        }
        if ($sufB === '') {
            return -1;
        }
        return version_compare($a, $b);
    }

    public static function channels(): array
    {
        $ch = config()['update_channels'] ?? null;
        if (is_array($ch) && $ch) {
            return array_values(array_filter(array_map('strval', $ch)));
        }
        return self::DEFAULT_CHANNELS;
    }

    /** Display label for a channel URL; raw update-source URLs never reach the UI. */
    public static function channelLabel(string $channel): string
    {
        $target = rtrim($channel, '/');
        foreach (self::channels() as $i => $c) {
            if (rtrim((string)$c, '/') === $target) {
                return $i === 0 ? '主更新服务器' : ($i === 1 ? '备更新服务器' : '更新服务器 ' . ($i + 1));
            }
        }
        return '更新服务器';
    }

    // ---------- capabilities / degradation ----------
    public static function capabilities(): array
    {
        $curl = extension_loaded('curl');
        $fopen = (bool)ini_get('allow_url_fopen');
        $download = $curl ? 'curl' : ($fopen ? 'stream' : null);
        return [
            'download' => $download,                                  // curl|stream|null
            'zip' => class_exists('ZipArchive') ? 'ziparchive' : 'purephp',
            'zlib' => extension_loaded('zlib'),
            'hash' => function_exists('hash_file') ? 'hash' : 'purephp',
            'mode' => $download !== null ? 'full' : 'upload',         // full|upload (+manual always available)
            'php' => PHP_VERSION,
        ];
    }

    // ---------- settings (uncached, updater mutates them mid-request) ----------
    private function getSettingRaw(string $key): string
    {
        $st = db()->prepare('SELECT `v` FROM `settings` WHERE `k`=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string)$v;
    }

    private function setSettingRaw(string $key, string $value): void
    {
        $st = db()->prepare('INSERT INTO `settings` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v`=VALUES(`v`)');
        $st->execute([$key, $value]);
    }

    public function schemaVersion(): string
    {
        $v = $this->getSettingRaw('schema_version');
        return $v !== '' ? $v : VERSION;
    }

    // ---------- HTTP with fallback ----------
    /** @return array{status:int,body:string}|null */
    private function httpGet(string $url, int $timeout = 10, int $maxBytes = 1048576): ?array
    {
        $caps = self::capabilities();
        if ($caps['download'] === 'curl') {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Forlover-Updater/' . VERSION,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false || $status !== 200 || strlen((string)$body) > $maxBytes) {
                return null;
            }
            return ['status' => $status, 'body' => (string)$body];
        }
        if ($caps['download'] === 'stream') {
            $ctx = stream_context_create(['http' => [
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
                'header' => "User-Agent: Forlover-Updater/" . VERSION . "\r\n",
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false || strlen($body) > $maxBytes) {
                return null;
            }
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
            return $status === 200 ? ['status' => 200, 'body' => $body] : null;
        }
        return null;
    }

    private function httpDownload(string $url, string $dest, int $timeout = 180): bool
    {
        $caps = self::capabilities();
        if ($caps['download'] === 'curl') {
            $fp = fopen($dest, 'wb');
            if ($fp === false) {
                return false;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'Forlover-Updater/' . VERSION,
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fp);
            return $ok && $status === 200;
        }
        if ($caps['download'] === 'stream') {
            $ctx = stream_context_create(['http' => [
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 3,
                'header' => "User-Agent: Forlover-Updater/" . VERSION . "\r\n",
            ]]);
            $in = @fopen($url, 'rb', false, $ctx);
            if ($in === false) {
                return false;
            }
            $out = fopen($dest, 'wb');
            if ($out === false) {
                fclose($in);
                return false;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            return filesize($dest) > 0;
        }
        return false;
    }

    // ---------- hashing with fallback ----------
    public static function sha256File(string $file): string
    {
        if (function_exists('hash_file')) {
            return (string)hash_file('sha256', $file);
        }
        return sha256_pure((string)file_get_contents($file));
    }

    public static function sha256Str(string $data): string
    {
        if (function_exists('hash')) {
            return hash('sha256', $data);
        }
        return sha256_pure($data);
    }

    // ---------- update check ----------
    /** Fetch version.json from the first reachable channel; cache in settings. */
    public function check(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cachedCheck();
            if ($cached !== null && time() - (int)($cached['at'] ?? 0) < 86400) {
                return $cached;
            }
        }
        $info = ['ok' => false, 'at' => time(), 'current' => VERSION, 'has_new' => false, 'error' => ''];
        $lastError = '所有更新源都无法访问';
        foreach (self::channels() as $channel) {
            $res = $this->httpGet(rtrim($channel, '/') . '/version.json', 8, 65536);
            if ($res === null) {
                continue;
            }
            $data = json_decode($res['body'], true);
            if (!is_array($data) || empty($data['latest']) || empty($data['package']['path'])
                || empty($data['package']['sha256']) || !preg_match('/^[a-f0-9]{64}$/', (string)$data['package']['sha256'])) {
                $lastError = '更新源返回的清单格式不正确';
                continue;
            }
            $info = array_merge($info, [
                'ok' => true,
                'channel' => $channel,
                'latest' => (string)$data['latest'],
                'notes' => (string)($data['notes'] ?? ''),
                'released_at' => (string)($data['released_at'] ?? ''),
                'min_php' => (string)($data['min_php'] ?? '8.1'),
                'from' => (string)($data['from'] ?? ''),
                'package' => [
                    'path' => (string)$data['package']['path'],
                    'sha256' => (string)$data['package']['sha256'],
                    'size' => (int)($data['package']['size'] ?? 0),
                ],
                'migrations' => array_values(array_map('strval', (array)($data['migrations'] ?? []))),
                'has_new' => self::verCmp((string)$data['latest'], VERSION) > 0,
                'error' => '',
            ]);
            break;
        }
        if (!$info['ok']) {
            $info['error'] = $lastError;
        }
        try {
            $this->setSettingRaw('update_check', (string)json_encode($info, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // check result caching is best-effort
        }
        return $info;
    }

    public function cachedCheck(): ?array
    {
        try {
            $raw = $this->getSettingRaw('update_check');
        } catch (Throwable) {
            return null;
        }
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        // The snapshot was computed against the version running at check
        // time, so after an upgrade it goes stale and would keep offering
        // the version just installed. Always re-derive has_new against
        // the version running NOW.
        if (!empty($data['latest'])) {
            $data['has_new'] = self::verCmp((string)$data['latest'], VERSION) > 0;
            $data['current'] = VERSION;
        }
        return $data;
    }

    // ---------- journal ----------
    public function journal(): array
    {
        $f = self::journalFile();
        if (!is_file($f)) {
            return ['status' => 'idle', 'steps' => []];
        }
        $data = json_decode((string)file_get_contents($f), true);
        return is_array($data) ? $data : ['status' => 'idle', 'steps' => []];
    }

    private function journalWrite(array $j): void
    {
        $this->ensureDir(self::workDir());
        file_put_contents(self::journalFile(), json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function journalStart(string $target): void
    {
        $this->journalWrite(['status' => 'running', 'target' => $target, 'started_at' => date('Y-m-d H:i:s'), 'steps' => []]);
    }

    private function step(string $name, bool $ok, string $detail = ''): void
    {
        $j = $this->journal();
        $j['steps'][] = ['ts' => date('H:i:s'), 'step' => $name, 'ok' => $ok, 'detail' => $detail];
        $this->journalWrite($j);
    }

    private function journalFinish(string $status, string $detail = ''): void
    {
        $j = $this->journal();
        $j['status'] = $status;
        $j['finished_at'] = date('Y-m-d H:i:s');
        if ($detail !== '') {
            $j['detail'] = $detail;
        }
        $this->journalWrite($j);
    }

    /** A journal stuck in "running" for >10 min means the last run died mid-way. */
    public function interrupted(): bool
    {
        $j = $this->journal();
        return ($j['status'] ?? '') === 'running'
            && isset($j['started_at'])
            && strtotime((string)$j['started_at']) < time() - 600;
    }

    // ---------- maintenance ----------
    public static function maintenanceOn(): bool
    {
        return is_file(self::flagFile());
    }

    private function setMaintenance(bool $on): void
    {
        if ($on) {
            file_put_contents(self::flagFile(), date('Y-m-d H:i:s'));
        } elseif (is_file(self::flagFile())) {
            unlink(self::flagFile());
        }
    }

    /** Manual site close/open for hand-performed upgrades (admin stays reachable). */
    public function toggleMaintenance(bool $on): void
    {
        $this->setMaintenance($on);
    }

    // ---------- helpers ----------
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
    }

    private function acquireLock(): void
    {
        $this->ensureDir(self::workDir());
        $h = fopen(self::workDir() . '/update.lock', 'c');
        if ($h === false || !flock($h, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('已有升级任务在进行中，请稍后再试');
        }
        $this->lockHandle = $h;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /** Paths the updater must never overwrite or delete. */
    private static function isProtected(string $rel): bool
    {
        return $rel === 'config.php' || $rel === 'install.lock' || $rel === '.user.ini'
            || str_starts_with($rel, 'app/cache/') || $rel === 'app/cache'
            || str_starts_with($rel, 'app/backups/') || $rel === 'app/backups';
    }

    /** @return string[] all files under ROOT as relative paths, minus volatile dirs */
    private function siteFiles(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(ROOT) + 1));
            if (str_starts_with($rel, 'app/cache/') || str_starts_with($rel, 'app/backups/')) {
                continue;
            }
            if ($rel === '.user.ini') {
                continue; // host-managed (immutable on Baota), never back up or restore
            }
            $files[] = $rel;
        }
        sort($files);
        return $files;
    }

    private function zipAdd(string $zipPath, array $relFiles): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('备份包创建失败');
            }
            foreach ($relFiles as $rel) {
                $zip->addFile(ROOT . '/' . $rel, $rel);
            }
            $zip->close();
            return;
        }
        $map = [];
        foreach ($relFiles as $rel) {
            $map[$rel] = ROOT . '/' . $rel;
        }
        ZipUtil::create($zipPath, $map);
    }

    /** @return string[] extracted relative paths */
    private function zipExtract(string $zipPath, string $destDir): array
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('升级包打开失败');
            }
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                $rel = ZipUtil::safeRel($name);
                if ($rel === null) {
                    $zip->close();
                    throw new RuntimeException('升级包包含不安全路径：' . $name);
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $out[] = $rel;
            }
            if (!$zip->extractTo($destDir)) {
                $zip->close();
                throw new RuntimeException('升级包解压失败');
            }
            $zip->close();
            return $out;
        }
        return ZipUtil::extract($zipPath, $destDir);
    }

    private function zipReadEntry(string $zipPath, string $name): ?string
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return null;
            }
            $data = $zip->getFromName($name);
            $zip->close();
            return $data === false ? null : $data;
        }
        return ZipUtil::readEntry($zipPath, $name);
    }

    // ---------- SQL dump / restore / split ----------
    public static function splitSql(string $sql): array
    {
        $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
        $out = [];
        foreach (preg_split('/;\s*\R/', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }

    private function backupDb(string $file): void
    {
        $pdo = db();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
        $sql = "-- Forlover backup " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n";
        foreach ($tables as $row) {
            $table = (string)$row[0];
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $sql .= "\nDROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n";
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            if ($count === 0) {
                continue;
            }
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $batch = [];
            while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
                $vals = [];
                foreach ($r as $v) {
                    $vals[] = $v === null ? 'NULL' : (is_int($v) ? (string)$v : $pdo->quote((string)$v));
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 500) {
                    $sql .= "INSERT INTO `$table` VALUES " . implode(',', $batch) . ";\n";
                    $batch = [];
                }
            }
            if ($batch) {
                $sql .= "INSERT INTO `$table` VALUES " . implode(',', $batch) . ";\n";
            }
        }
        $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        if (file_put_contents($file, $sql) === false) {
            throw new RuntimeException('数据库备份写入失败');
        }
    }

    private function restoreDb(string $file): void
    {
        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::splitSql((string)file_get_contents($file)) as $stmt) {
                if (str_starts_with($stmt, 'SET FOREIGN_KEY_CHECKS')) {
                    continue;
                }
                $pdo->exec($stmt);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // ---------- migrations ----------
    /**
     * Run pending migrations from a directory of <version>.sql / <version>.php
     * files (version > current schema_version). Returns applied versions.
     */
    public function runMigrationsFrom(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $current = $this->schemaVersion();
        $files = [];
        foreach (glob($dir . '/*.sql') ?: [] as $f) {
            $files[basename($f, '.sql')]['sql'] = $f;
        }
        foreach (glob($dir . '/*.php') ?: [] as $f) {
            $files[basename($f, '.php')]['php'] = $f;
        }
        $versions = array_filter(array_keys($files), static fn($v) => version_compare((string)$v, $current, '>'));
        usort($versions, 'version_compare');
        $applied = json_decode($this->getSettingRaw('applied_migrations') ?: '[]', true);
        $applied = is_array($applied) ? $applied : [];
        $done = [];
        foreach ($versions as $ver) {
            if (!empty($files[$ver]['sql'])) {
                foreach (self::splitSql((string)file_get_contents($files[$ver]['sql'])) as $stmt) {
                    db()->exec($stmt);
                }
            }
            if (!empty($files[$ver]['php'])) {
                $pdo = db();
                require $files[$ver]['php'];
            }
            $applied[] = $ver;
            $this->setSettingRaw('applied_migrations', (string)json_encode($applied));
            $this->setSettingRaw('schema_version', (string)$ver);
            $done[] = (string)$ver;
        }
        return $done;
    }

    /** Manual mode: admin placed the package's migrations in app/migrations-pending/. */
    public function runManualMigrations(): array
    {
        $this->journalStart('manual-migrations');
        try {
            $done = $this->runMigrationsFrom(ROOT . '/app/migrations-pending');
            $this->step('数据库迁移', true, $done ? '已执行：' . implode(', ', $done) : '没有待执行的迁移');
            $this->journalFinish('done', $done ? '迁移完成' : '无待迁移项');
            return $done;
        } catch (Throwable $ex) {
            $this->step('数据库迁移', false, $ex->getMessage());
            $this->journalFinish('failed', $ex->getMessage());
            throw $ex;
        }
    }

    // ---------- backups ----------
    /** @return array<int,array{tag:string,from:string,to:string,at:string,code:string,db:string,added:array}> */
    public function backups(): array
    {
        $dir = self::backupDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/meta-*.json') ?: [] as $f) {
            $meta = json_decode((string)file_get_contents($f), true);
            if (is_array($meta) && isset($meta['tag'])) {
                $out[] = $meta;
            }
        }
        usort($out, static fn($a, $b) => strcmp((string)$b['tag'], (string)$a['tag']));
        return $out;
    }

    /** Create a code+db backup set; returns its tag. */
    private function makeBackup(string $toVersion, string $kind = 'upgrade'): string
    {
        $this->ensureDir(self::backupDir());
        $tag = date('Ymd-His') . '-' . VERSION;
        $codeFile = self::backupDir() . '/code-' . $tag . '.zip';
        $dbFile = self::backupDir() . '/db-' . $tag . '.sql';
        $this->zipAdd($codeFile, $this->siteFiles());
        $this->step('备份代码', true, basename($codeFile));
        $this->backupDb($dbFile);
        $this->step('备份数据库', true, basename($dbFile));
        $meta = [
            'tag' => $tag,
            'from' => VERSION,
            'to' => $toVersion,
            'kind' => $kind,
            'at' => date('Y-m-d H:i:s'),
            'code' => basename($codeFile),
            'db' => basename($dbFile),
            'added' => [],
            'sha256' => [
                'code' => self::sha256File($codeFile),
                'db' => self::sha256File($dbFile),
            ],
            'size' => [
                'code' => (int)filesize($codeFile),
                'db' => (int)filesize($dbFile),
            ],
        ];
        file_put_contents(self::backupDir() . '/meta-' . $tag . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $tag;
    }

    /** On-demand backup from the admin page (before a manual upgrade, or anytime). */
    public function manualBackup(): string
    {
        $this->acquireLock();
        try {
            $this->journalStart('manual-backup');
            $tag = $this->makeBackup(VERSION, 'manual');
            $this->journalFinish('done', '手动备份完成（' . $tag . '）');
            return $tag;
        } catch (Throwable $ex) {
            $this->journalFinish('failed', '手动备份失败：' . $ex->getMessage());
            throw $ex;
        } finally {
            $this->releaseLock();
        }
    }

    /** List entry names inside a zip (either backend). @return string[] */
    private function zipList(string $zipPath): array
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('压缩包打开失败');
            }
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $out[] = (string)$zip->getNameIndex($i);
            }
            $zip->close();
            return $out;
        }
        return ZipUtil::listEntries($zipPath);
    }

    private function findBackup(string $tag): array
    {
        foreach ($this->backups() as $b) {
            if ($b['tag'] === $tag) {
                return $b;
            }
        }
        throw new RuntimeException('备份不存在：' . $tag);
    }

    /** Delete one backup set (code zip + db dump + meta). */
    public function deleteBackup(string $tag): void
    {
        $meta = $this->findBackup($tag);
        $this->acquireLock();
        try {
            foreach (['code', 'db'] as $k) {
                $name = (string)($meta[$k] ?? '');
                $f = self::backupDir() . '/' . basename($name);
                if ($name !== '' && is_file($f)) {
                    unlink($f);
                }
            }
            $mf = self::backupDir() . '/meta-' . $meta['tag'] . '.json';
            if (is_file($mf)) {
                unlink($mf);
            }
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Verify one backup set. Sets whose meta carries sha256 baselines
     * (made after this feature) get a hash comparison; older sets get
     * structural checks only and are labelled as such.
     * @return array{ok: bool, lines: string[]}
     */
    public function verifyBackup(string $tag): array
    {
        $meta = $this->findBackup($tag);
        $lines = [];
        $ok = true;
        $base = $meta['sha256'] ?? null;
        foreach (['code' => '代码包', 'db' => '数据库'] as $k => $label) {
            $name = (string)($meta[$k] ?? '');
            $f = self::backupDir() . '/' . basename($name);
            if ($name === '' || !is_file($f)) {
                $lines[] = $label . '：文件缺失（' . $name . '）';
                $ok = false;
                continue;
            }
            if (is_array($base) && !empty($base[$k])) {
                if (self::sha256File($f) === (string)$base[$k]) {
                    $lines[] = $label . '：哈希一致';
                } else {
                    $lines[] = $label . '：哈希不一致，文件可能已损坏或被改动';
                    $ok = false;
                }
            } else {
                $lines[] = $label . '：文件存在（' . (int)filesize($f) . ' 字节，无哈希基准）';
            }
        }
        $codeFile = self::backupDir() . '/' . basename((string)($meta['code'] ?? ''));
        if (is_file($codeFile)) {
            try {
                $entries = $this->zipList($codeFile);
                $files = array_filter($entries, static fn($n) => !str_ends_with($n, '/'));
                if ($files && in_array('index.php', $entries, true) && in_array('app/bootstrap.php', $entries, true)) {
                    $lines[] = '代码包结构：可打开，共 ' . count($files) . ' 个文件，核心文件齐全';
                } else {
                    $lines[] = '代码包结构：异常（核心文件缺失）';
                    $ok = false;
                }
            } catch (Throwable $ex) {
                $lines[] = '代码包结构：打开失败（' . $ex->getMessage() . '）';
                $ok = false;
            }
        }
        $dbFile = self::backupDir() . '/' . basename((string)($meta['db'] ?? ''));
        if (is_file($dbFile)) {
            $head = (string)file_get_contents($dbFile, false, null, 0, 262144);
            if (str_contains($head, 'CREATE TABLE') || str_contains($head, 'INSERT INTO')) {
                $lines[] = '数据库结构：包含建表/数据语句';
            } else {
                $lines[] = '数据库结构：异常（未见建表/数据语句）';
                $ok = false;
            }
        }
        if (!is_array($base)) {
            $lines[] = '注：该备份创建于哈希记录功能之前，未做哈希比对，仅结构校验';
        }
        return ['ok' => $ok, 'lines' => $lines];
    }

    // ---------- program integrity (official per-version manifest) ----------

    /** @return array<string,string> relpath => sha256 for every program file (protected paths excluded) */
    private function programHashes(): array
    {
        $out = [];
        foreach ($this->siteFiles() as $rel) {
            if (self::isProtected($rel)) {
                continue;
            }
            $out[$rel] = self::sha256File(ROOT . '/' . $rel);
        }
        return $out;
    }

    /**
     * Fetch the official integrity manifest for $version from the update
     * channels. Prefers the standalone manifest file published next to
     * the release package; falls back to reading manifest.json out of
     * the package itself (older releases predate the standalone file).
     * @return array{files: array, channel: string}|null
     */
    private function fetchVersionManifest(string $version): ?array
    {
        foreach (self::channels() as $channel) {
            $base = rtrim((string)$channel, '/');
            $res = $this->httpGet($base . '/releases/forlover-' . $version . '.manifest.json', 10, 262144);
            if ($res !== null) {
                $m = json_decode($res['body'], true);
                if (is_array($m) && (string)($m['version'] ?? '') === $version && !empty($m['files']) && is_array($m['files'])) {
                    return ['files' => $m['files'], 'channel' => (string)$channel];
                }
            }
            $this->ensureDir(self::workDir());
            $zip = self::workDir() . '/manifest-' . $version . '.zip';
            if ($this->httpDownload($base . '/releases/forlover-' . $version . '.zip', $zip, 60)) {
                $raw = $this->zipReadEntry($zip, 'manifest.json');
                @unlink($zip);
                if ($raw !== null) {
                    $m = json_decode($raw, true);
                    if (is_array($m) && (string)($m['version'] ?? '') === $version && !empty($m['files']) && is_array($m['files'])) {
                        return ['files' => $m['files'], 'channel' => (string)$channel];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Compare current program files against the official integrity
     * manifest of the running version, downloaded from the update
     * channels. No local baseline is kept: the manifest published with
     * each release is the only reference, so a site whose files were
     * already tampered with cannot bless itself.
     * ok = nothing modified and nothing missing; added files are
     * reported separately for the admin to judge.
     * @return array{ok: bool, has_manifest: bool, at: string, version: string, channel: string, modified: string[], missing: string[], added: string[]}
     */
    public function verifyProgram(): array
    {
        $res = [
            'ok' => false,
            'has_manifest' => false,
            'at' => date('Y-m-d H:i:s'),
            'version' => VERSION,
            'channel' => '',
            'modified' => [],
            'missing' => [],
            'added' => [],
        ];
        $remote = $this->fetchVersionManifest(VERSION);
        if ($remote !== null) {
            $res['has_manifest'] = true;
            $res['channel'] = $remote['channel'];
            $ref = [];
            foreach ((array)$remote['files'] as $rel => $sha) {
                $rel = (string)$rel;
                if (!str_starts_with($rel, 'payload/')) {
                    continue; // manifest also lists migrations/deletions, not program files
                }
                $rel = substr($rel, 8);
                if (str_starts_with($rel, 'app/cache/') || str_starts_with($rel, 'app/backups/')) {
                    continue; // runtime dirs (incl. the packaged .gitkeep) are not program files
                }
                $ref[$rel] = (string)$sha;
            }
            $current = $this->programHashes();
            foreach ($ref as $rel => $sha) {
                if (!isset($current[$rel])) {
                    $res['missing'][] = $rel;
                } elseif ($current[$rel] !== $sha) {
                    $res['modified'][] = $rel;
                }
            }
            $res['added'] = array_values(array_diff(array_keys($current), array_keys($ref)));
            sort($res['modified']);
            sort($res['missing']);
            sort($res['added']);
            $res['ok'] = !$res['modified'] && !$res['missing'];
        }
        try {
            $this->setSettingRaw('integrity_check', (string)json_encode($res, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // persisting the result is best-effort
        }
        return $res;
    }

    public function cachedIntegrity(): ?array
    {
        try {
            $raw = $this->getSettingRaw('integrity_check');
        } catch (Throwable) {
            return null;
        }
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function saveAddedFiles(string $tag, array $added): void
    {
        $f = self::backupDir() . '/meta-' . $tag . '.json';
        $meta = json_decode((string)file_get_contents($f), true);
        if (is_array($meta)) {
            $meta['added'] = $added;
            file_put_contents($f, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    // ---------- rollback ----------
    public function rollback(string $tag): void
    {
        $meta = null;
        foreach ($this->backups() as $b) {
            if ($b['tag'] === $tag) {
                $meta = $b;
                break;
            }
        }
        if ($meta === null) {
            throw new RuntimeException('备份不存在：' . $tag);
        }
        $this->acquireLock();
        try {
            $this->journalStart('rollback-' . $tag);
            $this->setMaintenance(true);
            try {
                $tmp = self::workDir() . '/rollback';
                $this->rrmdir($tmp);
                $this->ensureDir($tmp);
                $this->zipExtract(self::backupDir() . '/' . $meta['code'], $tmp);
                $this->copyTree($tmp, ROOT, true);
                $this->step('恢复代码', true, '来自备份 ' . $tag);
                foreach ((array)($meta['added'] ?? []) as $rel) {
                    $rel = (string)$rel;
                    if (!self::isProtected($rel) && is_file(ROOT . '/' . $rel)) {
                        unlink(ROOT . '/' . $rel);
                    }
                }
                if (!empty($meta['added'])) {
                    $this->step('删除升级新增文件', true, count((array)$meta['added']) . ' 个');
                }
                $this->restoreDb(self::backupDir() . '/' . $meta['db']);
                $this->step('恢复数据库', true, (string)$meta['db']);
                $this->setSettingRaw('schema_version', (string)($meta['from'] ?? VERSION));
            } finally {
                $this->setMaintenance(false);
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $this->journalFinish('rolled-back', '已回滚到备份 ' . $tag . '（版本 ' . ($meta['from'] ?? '') . '）');
        } catch (Throwable $ex) {
            $this->journalFinish('failed', '回滚失败：' . $ex->getMessage());
            throw $ex;
        } finally {
            $this->releaseLock();
        }
    }

    // ---------- file tree ops ----------
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /** Copy extracted payload files into the site root (honoring protected paths); returns added files. */
    private function copyTree(string $srcDir, string $destDir, bool $forRollback = false): array
    {
        $added = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($srcDir) + 1));
            if (self::isProtected($rel)) {
                continue;
            }
            $target = $destDir . '/' . $rel;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            if (!$forRollback && !is_file($target)) {
                $added[] = $rel;
            }
            if (!copy($file->getPathname(), $target)) {
                throw new RuntimeException('文件写入失败：' . $rel);
            }
        }
        return $added;
    }

    // ---------- self check ----------
    private function selfCheck(string $targetVersion): array
    {
        // Fast path: the version constant on disk already proves files landed.
        $bootstrap = (string)@file_get_contents(ROOT . '/app/bootstrap.php');
        $filesOk = str_contains($bootstrap, "'" . $targetVersion . "'");
        $caps = self::capabilities();
        if ($caps['download'] === null) {
            return [$filesOk, $filesOk ? '文件版本已更新（无网络能力，跳过回环自检）' : '文件版本未更新'];
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        $urls = [];
        if ($port !== 80 && $port !== 443) {
            $urls[] = 'http://127.0.0.1:' . $port . '/';
        } else {
            $urls[] = 'http://127.0.0.1/';
            $urls[] = 'https://127.0.0.1/';
        }
        foreach ($urls as $url) {
            $res = $this->loopbackGet($url, $host);
            if ($res !== null && $res['status'] === 200 && str_contains($res['body'], '表白墙')) {
                return [true, '回环自检通过（' . $url . '）'];
            }
        }
        return [$filesOk, $filesOk ? '回环未通但文件版本已更新，按通过处理' : '回环自检失败且文件版本未更新'];
    }

    /** @return array{status:int,body:string}|null */
    private function loopbackGet(string $url, string $hostHeader): ?array
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Host: ' . $hostHeader],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'Forlover-Updater-SelfCheck',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $body === false ? null : ['status' => $status, 'body' => (string)$body];
        }
        if (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "Host: $hostHeader\r\nUser-Agent: Forlover-Updater-SelfCheck\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return null;
            }
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
            return ['status' => $status, 'body' => $body];
        }
        return null;
    }

    // ---------- the pipeline ----------
    /** Run a full upgrade using an already-downloaded (or uploaded) package file. */
    private function applyPackage(string $zipPath, array $info): void
    {
        $target = (string)$info['latest'];
        // 1) read + verify the package itself
        $manifestRaw = $this->zipReadEntry($zipPath, 'manifest.json');
        if ($manifestRaw === null) {
            throw new RuntimeException('升级包缺少 manifest.json');
        }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || (string)($manifest['version'] ?? '') !== $target) {
            throw new RuntimeException('升级包版本与清单不一致');
        }
        $tmp = self::workDir() . '/tmp';
        $this->rrmdir($tmp);
        $this->ensureDir($tmp);
        $this->zipExtract($zipPath, $tmp);
        $checked = 0;
        foreach ((array)($manifest['files'] ?? []) as $rel => $sha) {
            $f = $tmp . '/' . $rel;
            if (!is_file($f) || !hash_equals((string)$sha, self::sha256File($f))) {
                throw new RuntimeException('文件完整性校验失败：' . $rel);
            }
            $checked++;
        }
        $this->step('完整性校验', true, $checked . ' 个文件逐一通过 SHA-256 校验');

        // 2) backup (before touching anything live)
        $tag = $this->makeBackup($target);

        // 3) maintenance + apply + migrate + self-check, rollback on any failure
        $this->setMaintenance(true);
        $this->step('维护模式', true, '前台已暂停访问');
        try {
            $added = $this->copyTree($tmp . '/payload', ROOT);
            $this->saveAddedFiles($tag, $added);
            $this->step('更新文件', true, count($added) . ' 个新文件，其余为覆盖更新');

            $delFile = $tmp . '/deletions.txt';
            if (is_file($delFile)) {
                $n = 0;
                foreach (file($delFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $rel = ZipUtil::safeRel(trim((string)$line));
                    if ($rel !== null && !self::isProtected($rel) && is_file(ROOT . '/' . $rel)) {
                        unlink(ROOT . '/' . $rel);
                        $n++;
                    }
                }
                if ($n > 0) {
                    $this->step('删除旧文件', true, $n . ' 个');
                }
            }

            $applied = $this->runMigrationsFrom($tmp . '/migrations');
            $this->step('数据库升级', true, $applied ? '已执行迁移：' . implode(', ', $applied) : '本版无数据库迁移');

            [$ok, $note] = $this->selfCheck($target);
            $this->step('升级自检', $ok, $note);
            if (!$ok) {
                throw new RuntimeException('自检未通过：' . $note);
            }
        } catch (Throwable $ex) {
            $this->step('自动回滚', false, '升级失败，正在回滚：' . $ex->getMessage());
            $this->setMaintenance(false);
            $this->rollbackQuiet($tag);
            $this->journalFinish('rolled-back', '升级到 ' . $target . ' 失败并已自动回滚：' . $ex->getMessage());
            throw new RuntimeException('升级失败，已自动回滚到原版本。原因：' . $ex->getMessage());
        }
        $this->setMaintenance(false);
        $this->setSettingRaw('last_update', (string)json_encode([
            'from' => VERSION, 'to' => $target, 'at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
        $this->step('完成', true, '已升级到 ' . $target . '（页面刷新后生效）');
        $this->rrmdir(self::workDir() . '/tmp');
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->journalFinish('done', '升级成功：' . VERSION . ' → ' . $target);
    }

    /** Rollback used inside the pipeline (journal/lock already managed by caller). */
    private function rollbackQuiet(string $tag): void
    {
        $meta = null;
        foreach ($this->backups() as $b) {
            if ($b['tag'] === $tag) {
                $meta = $b;
                break;
            }
        }
        if ($meta === null) {
            return;
        }
        $this->setMaintenance(true);
        try {
            $tmp = self::workDir() . '/rollback';
            $this->rrmdir($tmp);
            $this->ensureDir($tmp);
            $this->zipExtract(self::backupDir() . '/' . $meta['code'], $tmp);
            $this->copyTree($tmp, ROOT, true);
            foreach ((array)($meta['added'] ?? []) as $rel) {
                $rel = (string)$rel;
                if (!self::isProtected($rel) && is_file(ROOT . '/' . $rel)) {
                    unlink(ROOT . '/' . $rel);
                }
            }
            $this->restoreDb(self::backupDir() . '/' . $meta['db']);
            $this->setSettingRaw('schema_version', (string)($meta['from'] ?? VERSION));
        } finally {
            $this->setMaintenance(false);
        }
    }

    /** One-click upgrade from the update channels. */
    public function run(): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $this->acquireLock();
        try {
            $info = $this->check(true);
            $this->journalStart((string)($info['latest'] ?? ''));
            if (!$info['ok']) {
                throw new RuntimeException('检查更新失败：' . ($info['error'] ?? '未知错误'));
            }
            $this->step('检查更新', true, '当前 ' . VERSION . '，最新 ' . $info['latest'] . '（来源 ' . self::channelLabel((string)($info['channel'] ?? '')) . '）');
            if (!$info['has_new']) {
                $this->journalFinish('done', '已是最新版本');
                return;
            }
            // preflight
            $minPhp = (string)($info['min_php'] ?? '8.1');
            if (version_compare(PHP_VERSION, $minPhp, '<')) {
                throw new RuntimeException('PHP 版本过低：需要 ≥ ' . $minPhp . '，当前 ' . PHP_VERSION);
            }
            if (!empty($info['from']) && self::verCmp(VERSION, (string)$info['from']) < 0) {
                throw new RuntimeException('当前版本 ' . VERSION . ' 过旧，不能直接升级到 ' . $info['latest'] . '（要求 ≥ ' . $info['from'] . '）');
            }
            if (!is_writable(ROOT)) {
                throw new RuntimeException('站根目录不可写，无法升级');
            }
            $size = (int)($info['package']['size'] ?? 0);
            $free = @disk_free_space(ROOT);
            if ($free !== false && $size > 0 && $free < $size * 3 + 10485760) {
                throw new RuntimeException('磁盘空间不足');
            }
            $this->step('环境预检', true, 'PHP ' . PHP_VERSION . ' · 升级方式 ' . self::capabilities()['mode']);

            // download with channel failover on hash mismatch
            $zipPath = self::workDir() . '/package.zip';
            $this->ensureDir(self::workDir());
            $channels = self::channels();
            $ordered = array_merge([$info['channel']], array_diff($channels, [$info['channel']]));
            $downloaded = false;
            foreach ($ordered as $channel) {
                $url = rtrim((string)$channel, '/') . '/' . ltrim((string)$info['package']['path'], '/');
                if (is_file($zipPath)) {
                    unlink($zipPath);
                }
                if (!$this->httpDownload($url, $zipPath)) {
                    $this->step('下载升级包', false, '来源 ' . self::channelLabel((string)$channel) . ' 下载失败，尝试下一个');
                    continue;
                }
                $actual = self::sha256File($zipPath);
                if (!hash_equals((string)$info['package']['sha256'], $actual)) {
                    $this->step('下载升级包', false, '来源 ' . self::channelLabel((string)$channel) . ' 整包哈希不一致，尝试下一个');
                    continue;
                }
                $this->step('下载升级包', true, '来源 ' . self::channelLabel((string)$channel) . ' · 整包 SHA-256 校验通过');
                $downloaded = true;
                break;
            }
            if (!$downloaded) {
                throw new RuntimeException('所有更新源的升级包都下载失败或哈希不一致');
            }
            $this->applyPackage($zipPath, $info);
        } catch (Throwable $ex) {
            $j = $this->journal();
            if (($j['status'] ?? '') === 'running') {
                $this->journalFinish('failed', $ex->getMessage());
            }
            throw $ex;
        } finally {
            $this->releaseLock();
        }
    }

    /** Semi-automatic mode: admin uploads a package downloaded from the official source. */
    public function runUploaded(string $tmpFile): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $this->acquireLock();
        try {
            $this->ensureDir(self::workDir());
            $zipPath = self::workDir() . '/package-upload.zip';
            if (!move_uploaded_file($tmpFile, $zipPath) && !copy($tmpFile, $zipPath)) {
                throw new RuntimeException('上传文件保存失败');
            }
            $manifestRaw = $this->zipReadEntry($zipPath, 'manifest.json');
            $manifest = $manifestRaw !== null ? json_decode($manifestRaw, true) : null;
            if (!is_array($manifest) || empty($manifest['version'])) {
                throw new RuntimeException('上传的不是有效的 Forlover 升级包（缺少清单）');
            }
            $target = (string)$manifest['version'];
            $this->journalStart($target);
            if (self::verCmp($target, VERSION) <= 0) {
                throw new RuntimeException('升级包版本 ' . $target . ' 不高于当前版本 ' . VERSION);
            }
            // Cross-check against the channel when reachable.
            $info = $this->check(false);
            if (!empty($info['ok']) && ($info['latest'] ?? '') === $target) {
                $actual = self::sha256File($zipPath);
                if (hash_equals((string)$info['package']['sha256'], $actual)) {
                    $this->step('整包校验', true, '与官方源登记的 SHA-256 一致');
                } else {
                    throw new RuntimeException('上传包与官方源登记的哈希不一致，已中止');
                }
            } else {
                $this->step('整包校验', true, '官方源暂不可达或版本不同，仅做包内完整性校验');
            }
            $info = array_merge($info ?: [], ['latest' => $target]);
            $this->applyPackage($zipPath, $info);
        } catch (Throwable $ex) {
            $j = $this->journal();
            if (($j['status'] ?? '') === 'running') {
                $this->journalFinish('failed', $ex->getMessage());
            }
            throw $ex;
        } finally {
            $this->releaseLock();
        }
    }
}
