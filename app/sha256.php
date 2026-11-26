<?php
defined('FORLOVER') || exit;

/**
 * Pure-PHP SHA-256 (last-resort fallback for hosts without ext-hash,
 * which is virtually unheard of — ext-hash is core since PHP 7.4).
 * Operates on whole strings; update packages are small.
 */
function sha256_pure(string $data): string
{
    static $K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];
    $h = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
    $m32 = 0xFFFFFFFF;
    $rotr = static fn(int $x, int $n): int => (($x >> $n) | (($x << (32 - $n)) & $m32)) & $m32;

    $len = strlen($data);
    $data .= "\x80";
    while (strlen($data) % 64 !== 56) {
        $data .= "\x00";
    }
    $bitLen = $len * 8;
    $data .= pack('NN', ($bitLen >> 32) & $m32, $bitLen & $m32);

    $blocks = intdiv(strlen($data), 64);
    for ($b = 0; $b < $blocks; $b++) {
        $w = [];
        for ($i = 0; $i < 16; $i++) {
            $w[$i] = unpack('N', substr($data, $b * 64 + $i * 4, 4))[1];
        }
        for ($i = 16; $i < 64; $i++) {
            $s0 = $rotr($w[$i - 15], 7) ^ $rotr($w[$i - 15], 18) ^ ($w[$i - 15] >> 3);
            $s1 = $rotr($w[$i - 2], 17) ^ $rotr($w[$i - 2], 19) ^ ($w[$i - 2] >> 10);
            $w[$i] = ($w[$i - 16] + $s0 + $w[$i - 7] + $s1) & $m32;
        }
        [$a, $bb, $c, $d, $e, $f, $g, $hh] = $h;
        for ($i = 0; $i < 64; $i++) {
            $S1 = $rotr($e, 6) ^ $rotr($e, 11) ^ $rotr($e, 25);
            $ch = ($e & $f) ^ ((~$e & $m32) & $g);
            $t1 = ($hh + $S1 + $ch + $K[$i] + $w[$i]) & $m32;
            $S0 = $rotr($a, 2) ^ $rotr($a, 13) ^ $rotr($a, 22);
            $maj = ($a & $bb) ^ ($a & $c) ^ ($bb & $c);
            $t2 = ($S0 + $maj) & $m32;
            $hh = $g; $g = $f; $f = $e; $e = ($d + $t1) & $m32;
            $d = $c; $c = $bb; $bb = $a; $a = ($t1 + $t2) & $m32;
        }
        $h[0] = ($h[0] + $a) & $m32; $h[1] = ($h[1] + $bb) & $m32;
        $h[2] = ($h[2] + $c) & $m32; $h[3] = ($h[3] + $d) & $m32;
        $h[4] = ($h[4] + $e) & $m32; $h[5] = ($h[5] + $f) & $m32;
        $h[6] = ($h[6] + $g) & $m32; $h[7] = ($h[7] + $hh) & $m32;
    }
    $out = '';
    foreach ($h as $word) {
        $out .= str_pad(dechex($word), 8, '0', STR_PAD_LEFT);
    }
    return $out;
}
