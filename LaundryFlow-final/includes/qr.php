<?php
/**
 * Small QR code generator (no libraries needed). Byte mode, error
 * correction level M, versions 1–10 (up to 213 characters).
 * Used on the claim stub so customers can SCAN instead of typing codes.
 *
 *   echo qr_svg('https://example.com/track.php?o=...');
 */

function qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

function qr_rs_divisor(int $degree): array
{
    $r = array_fill(0, $degree, 0);
    $r[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $r[$j] = qr_gf_mul($r[$j], $root);
            if ($j + 1 < $degree) {
                $r[$j] ^= $r[$j + 1];
            }
        }
        $root = qr_gf_mul($root, 0x02);
    }
    return $r;
}

function qr_rs_remainder(array $data, array $div): array
{
    $r = array_fill(0, count($div), 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($r);
        $r[] = 0;
        foreach ($div as $i => $d) {
            $r[$i] ^= qr_gf_mul($d, $factor);
        }
    }
    return $r;
}

/** Returns a 2D array of booleans (true = dark), or null if text is too long. */
function qr_matrix(string $text): ?array
{
    // version => [total codewords, ecc codewords per block, number of blocks]  (level M)
    $table = [1 => [26, 10, 1], [44, 16, 1], [70, 26, 1], [100, 18, 2], [134, 24, 2],
              [172, 16, 4], [196, 18, 4], [242, 22, 4], [292, 22, 5], [346, 26, 5]];
    $align = [1 => [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];
    $bytes = array_values(unpack('C*', $text) ?: []);
    $len = count($bytes);
    $ver = 0;
    foreach ($table as $v => [$total, $ecc, $nb]) {
        $cap = ($total - $ecc * $nb) * 8;
        $ccBits = $v < 10 ? 8 : 16;
        if (4 + $ccBits + 8 * $len <= $cap) {
            $ver = $v;
            break;
        }
    }
    if (!$ver) {
        return null;
    }
    [$total, $ecc, $nb] = $table[$ver];
    $dataCw = $total - $ecc * $nb;

    // ---- Bit stream ----
    $bits = [];
    $push = function (int $val, int $n) use (&$bits) {
        for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1;
    };
    $push(0b0100, 4);
    $push($len, $ver < 10 ? 8 : 16);
    foreach ($bytes as $b) $push($b, 8);
    $cap = $dataCw * 8;
    $push(0, min(4, $cap - count($bits)));
    while (count($bits) % 8) $bits[] = 0;
    for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $push($pad, 8);
    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $v = 0;
        for ($j = 0; $j < 8; $j++) $v = ($v << 1) | $bits[$i + $j];
        $data[] = $v;
    }

    // ---- Error correction blocks + interleaving ----
    $short = intdiv($total, $nb);
    $numShort = $nb - $total % $nb;
    $div = qr_rs_divisor($ecc);
    $blocks = [];
    $eccs = [];
    $k = 0;
    for ($i = 0; $i < $nb; $i++) {
        $n = $short - $ecc + ($i < $numShort ? 0 : 1);
        $blk = array_slice($data, $k, $n);
        $k += $n;
        $blocks[] = $blk;
        $eccs[] = qr_rs_remainder($blk, $div);
    }
    $final = [];
    $maxLen = max(array_map('count', $blocks));
    for ($i = 0; $i < $maxLen; $i++) foreach ($blocks as $b) if (isset($b[$i])) $final[] = $b[$i];
    for ($i = 0; $i < $ecc; $i++) foreach ($eccs as $e) $final[] = $e[$i];

    // ---- Function patterns ----
    $size = $ver * 4 + 17;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = function (int $x, int $y, bool $dark) use (&$m, &$fn) {
        $m[$y][$x] = $dark;
        $fn[$y][$x] = true;
    };
    for ($i = 0; $i < $size; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                    $d = max(abs($dx), abs($dy));
                    $set($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
    }
    $pos = $align[$ver];
    $np = count($pos);
    for ($i = 0; $i < $np; $i++) {
        for ($j = 0; $j < $np; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $np - 1) || ($i === $np - 1 && $j === 0)) continue;
            for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
                $set($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }
    $drawFormat = function (int $mask) use ($set, $size) {
        $d = (0 << 3) | $mask; // level M = 00
        $rem = $d;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $b = (($d << 10) | $rem) ^ 0x5412;
        $bit = fn($i) => (($b >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
        $set(8, $size - 8, true);
    };
    $drawFormat(0);
    if ($ver >= 7) {
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $vb = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = (($vb >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $set($a, $b, $bit);
            $set($b, $a, $bit);
        }
    }

    // ---- Place data (zig-zag) ----
    $i = 0;
    $nbits = count($final) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $up = (($right + 1) & 2) === 0;
                $y = $up ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $nbits) {
                    $m[$y][$x] = (($final[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    // ---- Choose the best mask ----
    $maskFn = [
        fn($x, $y) => ($x + $y) % 2 === 0,
        fn($x, $y) => $y % 2 === 0,
        fn($x, $y) => $x % 3 === 0,
        fn($x, $y) => ($x + $y) % 3 === 0,
        fn($x, $y) => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
        fn($x, $y) => ($x * $y) % 2 + ($x * $y) % 3 === 0,
        fn($x, $y) => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
        fn($x, $y) => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
    ];
    $best = null;
    $bestScore = PHP_INT_MAX;
    $base = $m;
    foreach ($maskFn as $mi => $f) {
        $m = $base;
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if (!$fn[$y][$x] && $f($x, $y)) $m[$y][$x] = !$m[$y][$x];
        }
        $drawFormat($mi);
        $score = qr_penalty($m, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $m;
        }
    }
    return $best;
}

function qr_penalty(array $m, int $size): int
{
    $p = 0;
    $dark = 0;
    for ($a = 0; $a < 2; $a++) {
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            $line = '';
            for ($x = 0; $x < $size; $x++) {
                $c = $a ? $m[$x][$y] : $m[$y][$x];
                $line .= $c ? '1' : '0';
                if ($x > 0) {
                    $prev = $a ? $m[$x - 1][$y] : $m[$y][$x - 1];
                    if ($c === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) $p += $run - 2;
                        $run = 1;
                    }
                }
            }
            if ($run >= 5) $p += $run - 2;
            $p += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }
    }
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        if ($m[$y][$x]) $dark++;
        if ($x < $size - 1 && $y < $size - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) $p += 3;
    }
    $total = $size * $size;
    $p += intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) * 10 - 10;
    return $p;
}

/** QR code as an inline SVG (prints sharp on any printer). */
function qr_svg(string $text, int $px = 160): string
{
    $m = qr_matrix($text);
    if (!$m) {
        return '';
    }
    $n = count($m);
    $q = 4; // quiet zone
    $path = '';
    foreach ($m as $y => $row) {
        foreach ($row as $x => $dark) {
            if ($dark) $path .= 'M' . ($x + $q) . ' ' . ($y + $q) . 'h1v1h-1z';
        }
    }
    $s = $n + 2 * $q;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $s . ' ' . $s . '" width="' . $px . '" height="' . $px .
        '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
}
