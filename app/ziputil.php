<?php
defined('FORLOVER') || exit;

/**
 * Pure-PHP zip reader/writer fallback (used only when ext-zip is missing).
 * Reader supports stored (0) and deflate (8) entries; writer emits stored
 * entries only, so release packages built with stored entries can be
 * unpacked on hosts with neither ext-zip nor zlib.
 */
final class ZipUtil
{
    /** @return string[] relative entry names (directories excluded) */
    public static function listEntries(string $zipFile): array
    {
        $entries = self::parse($zipFile);
        $names = [];
        foreach ($entries as $en) {
            if (!str_ends_with($en['name'], '/')) {
                $names[] = $en['name'];
            }
        }
        return $names;
    }

    public static function readEntry(string $zipFile, string $name): ?string
    {
        $data = (string)file_get_contents($zipFile);
        foreach (self::parse($zipFile, $data) as $en) {
            if ($en['name'] === $name) {
                return self::entryData($data, $en);
            }
        }
        return null;
    }

    /** @return string[] extracted relative paths */
    public static function extract(string $zipFile, string $destDir): array
    {
        $data = (string)file_get_contents($zipFile);
        $out = [];
        foreach (self::parse($zipFile, $data) as $en) {
            $rel = self::safeRel($en['name']);
            if ($rel === null) {
                throw new RuntimeException('升级包包含不安全路径：' . $en['name']);
            }
            if (str_ends_with($en['name'], '/')) {
                continue;
            }
            $target = $destDir . '/' . $rel;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                throw new RuntimeException('无法创建目录：' . $dir);
            }
            $content = self::entryData($data, $en);
            if ($content === null) {
                throw new RuntimeException('升级包条目解压失败：' . $en['name']);
            }
            if (file_put_contents($target, $content) === false) {
                throw new RuntimeException('写入失败：' . $target);
            }
            $out[] = $rel;
        }
        return $out;
    }

    /** @param array<string,string> $files rel path => absolute source file */
    public static function create(string $zipFile, array $files): void
    {
        $out = '';
        $central = '';
        foreach ($files as $rel => $abs) {
            $rel = str_replace('\\', '/', $rel);
            $content = (string)file_get_contents($abs);
            $crc = crc32($content);
            $size = strlen($content);
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($rel), 0)
                . $rel . $content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($rel), 0, 0, 0, 0, 0, $offset)
                . $rel;
        }
        $cdOffset = strlen($out);
        $out .= $central;
        $count = count($files);
        $out .= pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $cdOffset, 0);
        if (file_put_contents($zipFile, $out) === false) {
            throw new RuntimeException('备份包写入失败：' . $zipFile);
        }
    }

    /** Normalize an entry name to a safe relative path, or null if unsafe. */
    public static function safeRel(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name)) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $name) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                return null;
            }
            $parts[] = $p;
        }
        return $parts ? implode('/', $parts) : null;
    }

    /**
     * @return array<int,array{name:string,method:int,csize:int,usize:int,offset:int}>
     */
    private static function parse(string $zipFile, ?string $data = null): array
    {
        $data ??= (string)file_get_contents($zipFile);
        $len = strlen($data);
        // Locate End Of Central Directory (comment up to 64KB).
        $eocd = -1;
        for ($i = $len - 22; $i >= max(0, $len - 65558); $i--) {
            if (substr($data, $i, 4) === "PK\x05\x06") {
                $eocd = $i;
                break;
            }
        }
        if ($eocd < 0) {
            throw new RuntimeException('不是有效的 zip 文件：' . $zipFile);
        }
        $tail = unpack('vdisk/vcddisk/vhere/vtotal/Vcdsize/Vcdoffset/vcomment', substr($data, $eocd + 4, 18));
        $entries = [];
        $pos = $tail['cdoffset'];
        for ($n = 0; $n < $tail['total']; $n++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException('zip 中央目录损坏');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vname/vextra/vcomment/vdisk/vintattr/Vextattr/Voffset', substr($data, $pos + 4, 42));
            $name = substr($data, $pos + 46, $h['name']);
            $entries[] = [
                'name' => $name,
                'method' => $h['method'],
                'csize' => $h['csize'],
                'usize' => $h['usize'],
                'offset' => $h['offset'],
            ];
            $pos += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }
        return $entries;
    }

    private static function entryData(string $data, array $en): ?string
    {
        $pos = (int)$en['offset'];
        if (substr($data, $pos, 4) !== "PK\x03\x04") {
            return null;
        }
        $h = unpack('vneed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vname/vextra', substr($data, $pos + 4, 26));
        $start = $pos + 30 + $h['name'] + $h['extra'];
        $raw = substr($data, $start, (int)$en['csize']);
        if ($en['method'] === 0) {
            return $raw;
        }
        if ($en['method'] === 8) {
            $out = @gzinflate($raw);
            return $out === false ? null : $out;
        }
        return null; // unsupported compression method
    }
}
