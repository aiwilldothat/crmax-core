<?php
/**
 * CRMax Core - a small QR code generator, used to show the two-factor set-up code.
 *
 * It runs here, on your server: the text in the code (which holds a sign-in secret) is never sent to
 * an outside QR service. Plain PHP, no extensions. Byte mode, error correction level M, versions 1-20
 * (up to 666 bytes of text), drawn as SVG. Follows ISO/IEC 18004.
 */
declare(strict_types=1);
if (!defined('APP_VERSION')) { http_response_code(404); exit; }

/**
 * The QR code for $text as rows of 0 (light) and 1 (dark), without the quiet zone around it.
 * Returns [] when the text is too long for a version 20 code. $mask picks one of the eight mask
 * patterns (0-7); left out, the one that reads best is chosen.
 */
function qr_matrix(string $text, ?int $mask = null): array {
    static $ecLen  = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];             // EC codewords per block, level M
    static $blocks = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];                           // number of blocks, level M
    static $total  = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346, 404, 466, 532, 581, 655, 733, 815, 901, 991, 1085];   // codewords in the symbol
    static $align  = [[], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54], [6, 32, 58], [6, 34, 62],
        [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78], [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90]];

    // The smallest version that holds the text: mode (4 bits) + length (8 bits, 16 from version 10) + the bytes.
    $len = strlen($text); $ver = 0;
    for ($v = 1; $v <= 20; $v++) {
        $dataCw = $total[$v - 1] - $ecLen[$v - 1] * $blocks[$v - 1];
        if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= $dataCw * 8) { $ver = $v; break; }
    }
    if ($ver === 0) return [];
    $dataCw = $total[$ver - 1] - $ecLen[$ver - 1] * $blocks[$ver - 1];

    // Data codewords: the byte segment, a terminator, then the two pad bytes in turn.
    $bits = '0100' . str_pad(decbin($len), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $dataCw * 8 - strlen($bits)));
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    $data = [];
    foreach (str_split($bits, 8) as $b) $data[] = bindec($b);
    for ($pad = 0xEC; count($data) < $dataCw; $pad ^= 0xEC ^ 0x11) $data[] = $pad;

    // Split into blocks (the later blocks are one codeword longer), add error correction, interleave.
    $nb = $blocks[$ver - 1]; $ec = $ecLen[$ver - 1];
    $short = intdiv($total[$ver - 1], $nb) - $ec; $nShort = $nb - $total[$ver - 1] % $nb;
    $gen = qr_rs_generator($ec);
    $dBlocks = []; $eBlocks = []; $at = 0;
    for ($b = 0; $b < $nb; $b++) {
        $n = $short + ($b < $nShort ? 0 : 1);
        $chunk = array_slice($data, $at, $n); $at += $n;
        $dBlocks[] = $chunk; $eBlocks[] = qr_rs_remainder($chunk, $gen);
    }
    $all = [];
    for ($i = 0; $i <= $short; $i++) foreach ($dBlocks as $blk) if (isset($blk[$i])) $all[] = $blk[$i];
    for ($i = 0; $i < $ec; $i++) foreach ($eBlocks as $blk) $all[] = $blk[$i];

    // The fixed patterns. $m holds the modules, $fn marks the ones that are not data.
    $size = 17 + 4 * $ver;
    $m = array_fill(0, $size, array_fill(0, $size, 0));
    $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = function (int $x, int $y, int $dark) use (&$m, &$fn): void { $m[$y][$x] = $dark; $fn[$y][$x] = true; };
    for ($i = 0; $i < $size; $i++) { $set(6, $i, ($i + 1) % 2); $set($i, 6, ($i + 1) % 2); }                       // timing
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {                                              // finders, with their separators
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy;
            if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) continue;
            $d = max(abs($dx), abs($dy));
            $set($x, $y, $d !== 2 && $d !== 4 ? 1 : 0);
        }
    }
    $pos = $align[$ver - 1]; $last = count($pos) - 1;
    foreach ($pos as $i => $cy) foreach ($pos as $j => $cx) {                                                       // alignment, except where the finders are
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1 ? 1 : 0);
    }
    $format = function (int $mask) use ($set, $size): void {                                                        // level M + the mask, with its check bits
        $rem = $mask;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $f = (($mask << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i): int => ($f >> $i) & 1;
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
        $set(8, $size - 8, 1);
    };
    $format(0);   // reserves the format area; the real bits are written once the mask is chosen
    if ($ver >= 7) {                                                                                                // version number, twice
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $vbits = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + $i % 3; $b = intdiv($i, 3);
            $set($a, $b, ($vbits >> $i) & 1); $set($b, $a, ($vbits >> $i) & 1);
        }
    }

    // The codewords, two columns at a time from the right, going up and down in turn.
    $i = 0; $nBits = count($all) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $y = (($right + 1) & 2) === 0 ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $nBits) { $m[$y][$x] = ($all[$i >> 3] >> (7 - ($i & 7))) & 1; $i++; }
            }
        }
    }

    // Try each mask over the data modules and keep the one with the lowest penalty.
    $best = null; $bestScore = PHP_INT_MAX;
    foreach ($mask === null ? range(0, 7) : [max(0, min(7, $mask))] as $k) {
        $t = $m;
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if ($fn[$y][$x]) continue;
            $flip = match ($k) {
                0 => ($x + $y) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($x + $y) % 3 === 0,
                4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                5 => $x * $y % 2 + $x * $y % 3 === 0,
                6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
            };
            if ($flip) $t[$y][$x] ^= 1;
        }
        $keep = $m; $m = $t; $format($k); $t = $m; $m = $keep;   // write this mask's format bits into the candidate
        $score = qr_penalty($t);
        if ($score < $bestScore) { $bestScore = $score; $best = $t; }
    }
    return $best ?? [];
}

/** How hard a finished code is to read, by the standard's four rules. Lower is better. */
function qr_penalty(array $m): int {
    $size = count($m); $score = 0; $dark = 0;
    $lines = [];
    foreach ($m as $row) { $lines[] = implode('', $row); $dark += array_sum($row); }
    for ($x = 0; $x < $size; $x++) $lines[] = implode('', array_column($m, $x));
    foreach ($lines as $line) {
        preg_match_all('/0{5,}|1{5,}/', $line, $runs);                                    // rule 1: five or more in a row
        foreach ($runs[0] as $r) $score += strlen($r) - 2;
        $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));   // rule 3: looks like a finder
    }
    for ($y = 0; $y < $size - 1; $y++) for ($x = 0; $x < $size - 1; $x++) {               // rule 2: 2 x 2 blocks of one colour
        $c = $m[$y][$x];
        if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) $score += 3;
    }
    return $score + 10 * intdiv((int) abs($dark * 20 - $size * $size * 10), $size * $size);   // rule 4: far from half dark
}

/** Multiply two numbers in the QR code's field, GF(256) with the polynomial x^8 + x^4 + x^3 + x^2 + 1. */
function qr_gf_mul(int $x, int $y): int {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) { $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF; if (($y >> $i) & 1) $z ^= $x; }
    return $z;
}
/** The Reed-Solomon generator polynomial for $degree error correction codewords (highest term left out). */
function qr_rs_generator(int $degree): array {
    $g = array_fill(0, $degree, 0); $g[$degree - 1] = 1; $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) { $g[$j] = qr_gf_mul($g[$j], $root); if ($j + 1 < $degree) $g[$j] ^= $g[$j + 1]; }
        $root = qr_gf_mul($root, 2);
    }
    return $g;
}
/** The error correction codewords for one block of data codewords. */
function qr_rs_remainder(array $data, array $gen): array {
    $r = array_fill(0, count($gen), 0);
    foreach ($data as $b) {
        $f = $b ^ array_shift($r); $r[] = 0;
        foreach ($gen as $i => $g) $r[$i] ^= qr_gf_mul($g, $f);
    }
    return $r;
}

/**
 * The QR code for $text as an inline SVG, $px pixels wide, dark on white with the quiet zone the
 * standard asks for. Returns '' when the text is too long.
 */
function qr_svg(string $text, int $px = 220, string $label = 'QR code'): string {
    $m = qr_matrix($text);
    if (!$m) return '';
    $size = count($m); $d = '';
    foreach ($m as $y => $row) {
        for ($x = 0; $x < $size; $x++) {
            if (!$row[$x]) continue;
            $run = 1;
            while ($x + $run < $size && $row[$x + $run]) $run++;
            $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h' . $run . 'v1h-' . $run . 'z';
            $x += $run;
        }
    }
    $box = $size + 8;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges" role="img" aria-label="'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><rect width="' . $box . '" height="' . $box . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
}
