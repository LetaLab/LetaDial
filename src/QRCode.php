<?php
/*
 * QRCode — Pure PHP QR Code SVG Generator
 *
 * Supports Byte mode encoding, EC Level M (15% recovery)
 * Versions 1–10 (handles up to 216 bytes — enough for any otpauth:// URI)
 * Output: Inline SVG string. Zero external dependencies.
 * ISO/IEC 18004:2015
 *
 * Usage:
 *   $svg = QRCode::svg('otpauth://totp/...');
 *   echo $svg; // complete <svg> element, embed directly in HTML
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

final class QRCode
{
    // ── GF(256) tables for Reed-Solomon ──────────────────────────────────────
    // Primitive polynomial: x^8 + x^4 + x^3 + x^2 + 1 = 0x11D
    private static array $EXP = [];
    private static array $LOG = [];

    // ── QR version data for EC Level M ───────────────────────────────────────
    // [data_capacity_bytes, ec_cw_per_block, [g1_count, g1_data_cw], [g2_count, g2_data_cw]|null]
    // Values from ISO 18004:2015 Table 9, EC Level M
    private const VERSIONS = [
        1  => [ 16, 10, [1, 16], null    ],
        2  => [ 28, 16, [1, 28], null    ],
        3  => [ 44, 26, [1, 44], null    ],
        4  => [ 64, 18, [2, 32], null    ],
        5  => [ 86, 24, [2, 43], null    ],
        6  => [108, 16, [4, 27], null    ],
        7  => [124, 18, [4, 31], null    ],
        8  => [154, 22, [2, 38], [2, 39] ],
        9  => [182, 22, [3, 36], [2, 37] ],
        10 => [216, 26, [4, 43], [1, 44] ],
    ];

    // Alignment pattern center coordinates per version
    private const ALIGN = [
        1  => [],
        2  => [6, 18],       3  => [6, 22],
        4  => [6, 26],       5  => [6, 30],
        6  => [6, 34],       7  => [6, 22, 38],
        8  => [6, 24, 42],   9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    // Pre-computed format info for EC Level M (00), masks 0–7
    // 15-bit value: 5-bit data + 10-bit BCH, XOR'd with 101010000010010
    private const FORMAT_M = [21522, 20773, 24188, 23371, 17913, 16590, 20375, 19104];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate an SVG QR code for $data.
     *
     * @param string $data       Data to encode (UTF-8 byte mode)
     * @param int    $moduleSize Pixels per QR module (default 4)
     * @param int    $margin     Quiet zone in modules (default 4, spec minimum)
     * @return string Complete <svg>…</svg> string
     */
    public static function svg(string $data, int $moduleSize = 4, int $margin = 4): string
    {
        $bytes   = self::stringToBytes($data);
        $version = self::pickVersion(count($bytes));
        if ($version === 0) {
            return '<!-- QRCode: data too long -->';
        }

        $codewords = self::encodeData($bytes, $version);

        // buildMatrix returns [dataMatrix, funcMatrix]
        // funcMatrix = snapshot BEFORE placeData — identifies functional modules
        [$matrix, $funcMatrix] = self::buildMatrix($version, $codewords);
        [, $matrix] = self::bestMask($matrix, $funcMatrix, $version);

        return self::toSVG($matrix, $moduleSize, $margin);
    }

    // ── Encoding ──────────────────────────────────────────────────────────────

    private static function stringToBytes(string $s): array
    {
        return array_values(unpack('C*', $s) ?: []);
    }

    private static function pickVersion(int $len): int
    {
        foreach (self::VERSIONS as $v => [$cap]) {
            if ($len <= $cap) return $v;
        }
        return 0; // too long
    }

    private static function encodeData(array $bytes, int $version): array
    {
        [$cap, $ecPerBlock, $g1, $g2] = self::VERSIONS[$version];

        // ── Build bit stream ─────────────────────────────────────────────────
        $bits  = '';
        $bits .= '0100';                            // Mode: byte
        $bits .= sprintf('%08b', count($bytes));    // Char count (8 bits for v1-9)
        foreach ($bytes as $b) {
            $bits .= sprintf('%08b', $b);           // Data bytes
        }

        // Terminator (up to 4 zero bits)
        $totalBits = $cap * 8;
        $bits .= substr('0000', 0, min(4, $totalBits - strlen($bits)));

        // Pad to byte boundary
        while (strlen($bits) % 8 !== 0) $bits .= '0';

        // Pad with alternating bytes to fill capacity
        $padBytes = ['11101100', '00010001'];
        $pi = 0;
        while (strlen($bits) < $totalBits) {
            $bits .= $padBytes[$pi++ % 2];
        }

        // Convert bit string to codeword bytes
        $codewords = [];
        for ($i = 0; $i < $totalBits; $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        // ── Split into blocks and add Reed-Solomon EC ─────────────────────────
        $blocks = [];
        $offset = 0;
        $groups = array_filter([$g1, $g2]);
        foreach ($groups as [$count, $dataCW]) {
            for ($i = 0; $i < $count; $i++) {
                $blocks[] = array_slice($codewords, $offset, $dataCW);
                $offset  += $dataCW;
            }
        }

        $ecBlocks = array_map(fn($b) => self::reedSolomon($b, $ecPerBlock), $blocks);

        // ── Interleave data codewords ─────────────────────────────────────────
        $result     = [];
        $maxDataLen = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) $result[] = $block[$i];
            }
        }

        // ── Interleave EC codewords ───────────────────────────────────────────
        $maxECLen = max(array_map('count', $ecBlocks));
        for ($i = 0; $i < $maxECLen; $i++) {
            foreach ($ecBlocks as $ecBlock) {
                if (isset($ecBlock[$i])) $result[] = $ecBlock[$i];
            }
        }

        return $result;
    }

    // ── Reed-Solomon in GF(256) ───────────────────────────────────────────────

    private static function initGF(): void
    {
        if (!empty(self::$EXP)) return;
        self::$EXP = array_fill(0, 512, 0);
        self::$LOG = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$EXP[$i] = $x;
            self::$LOG[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D; // primitive poly
        }
        for ($i = 255; $i < 512; $i++) {
            self::$EXP[$i] = self::$EXP[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$EXP[(self::$LOG[$a] + self::$LOG[$b]) % 255];
    }

    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGF();

        // Build generator polynomial: product of (x + α^i) for i=0..ecCount-1
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $factor = [1, self::$EXP[$i]];
            $res    = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $gv) {
                foreach ($factor as $k => $fv) {
                    $res[$j + $k] ^= self::gfMul($gv, $fv);
                }
            }
            $gen = $res;
        }

        // Polynomial long division
        $msg = array_merge($data, array_fill(0, $ecCount, 0));
        for ($i = 0; $i < count($data); $i++) {
            $c = $msg[$i];
            if ($c !== 0) {
                for ($j = 1; $j <= $ecCount; $j++) {
                    $msg[$i + $j] ^= self::gfMul($gen[$j], $c);
                }
            }
        }
        return array_slice($msg, count($data));
    }

    // ── Matrix Construction ───────────────────────────────────────────────────

    /**
     * Build the QR matrix and return [dataMatrix, funcMatrix].
     *
     * funcMatrix is a snapshot taken BEFORE placeData() — cells set by
     * functional patterns are non-null; data cells are still null.
     * This is the only reliable way to distinguish functional from data
     * modules after placeData() fills all remaining cells.
     */
    private static function buildMatrix(int $version, array $codewords): array
    {
        $size   = 17 + 4 * $version;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));

        self::placeFinderPatterns($matrix, $size);
        self::placeTimingPatterns($matrix, $size);
        self::placeAlignmentPatterns($matrix, $version);
        self::reserveFormatAreas($matrix, $size);

        // Snapshot BEFORE data placement: non-null = functional, null = data
        $funcMatrix = $matrix;

        self::placeData($matrix, $size, $codewords);

        return [$matrix, $funcMatrix];
    }

    private static function placeFinderPatterns(array &$m, int $size): void
    {
        $positions = [[0, 0], [0, $size - 7], [$size - 7, 0]];
        foreach ($positions as [$r, $c]) {
            self::placeFinder($m, $r, $c);
        }

        // Separators
        for ($i = 0; $i <= 7; $i++) {
            self::set($m, 7, $i, false);
            self::set($m, $i, 7, false);
        }
        for ($i = 0; $i <= 7; $i++) {
            self::set($m, 7, $size - 8 + $i, false);
            self::set($m, $i, $size - 8, false);
        }
        for ($i = 0; $i <= 7; $i++) {
            self::set($m, $size - 8, $i, false);
            self::set($m, $size - 8 + $i, 7, false);
        }
    }

    private static function placeFinder(array &$m, int $row, int $col): void
    {
        $pattern = [
            [1,1,1,1,1,1,1],
            [1,0,0,0,0,0,1],
            [1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],
            [1,0,1,1,1,0,1],
            [1,0,0,0,0,0,1],
            [1,1,1,1,1,1,1],
        ];
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                self::set($m, $row + $r, $col + $c, (bool)$pattern[$r][$c]);
            }
        }
    }

    private static function placeTimingPatterns(array &$m, int $size): void
    {
        for ($c = 8; $c < $size - 8; $c++) {
            self::set($m, 6, $c, ($c % 2 === 0));
        }
        for ($r = 8; $r < $size - 8; $r++) {
            self::set($m, $r, 6, ($r % 2 === 0));
        }
    }

    private static function placeAlignmentPatterns(array &$m, int $version): void
    {
        $locs = self::ALIGN[$version];
        $n    = count($locs);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $r = $locs[$i];
                $c = $locs[$j];
                if (self::overlapsFinderOrTiming($r, $c, $version)) continue;
                self::placeAlignment($m, $r, $c);
            }
        }
    }

    private static function overlapsFinderOrTiming(int $r, int $c, int $version): bool
    {
        $size = 17 + 4 * $version;
        if ($r <= 8 && $c <= 8)         return true;
        if ($r <= 8 && $c >= $size - 9) return true;
        if ($r >= $size - 9 && $c <= 8) return true;
        return false;
    }

    private static function placeAlignment(array &$m, int $row, int $col): void
    {
        $pattern = [
            [1,1,1,1,1],
            [1,0,0,0,1],
            [1,0,1,0,1],
            [1,0,0,0,1],
            [1,1,1,1,1],
        ];
        for ($r = 0; $r < 5; $r++) {
            for ($c = 0; $c < 5; $c++) {
                self::set($m, $row - 2 + $r, $col - 2 + $c, (bool)$pattern[$r][$c]);
            }
        }
    }

    private static function reserveFormatAreas(array &$m, int $size): void
    {
        foreach (self::formatPositions($size) as [$r, $c]) {
            if ($m[$r][$c] === null) $m[$r][$c] = false;
        }
        // Dark module always dark
        self::set($m, $size - 8, 8, true);
    }

    private static function formatPositions(int $size): array
    {
        $pos = [];
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) $pos[] = [8, $i];
        }
        for ($i = 7; $i >= 0; $i--) {
            if ($i !== 6) $pos[] = [$i, 8];
        }
        for ($i = $size - 1; $i >= $size - 8; $i--) {
            $pos[] = [8, $i];
        }
        for ($i = $size - 7; $i <= $size - 1; $i++) {
            $pos[] = [$i, 8];
        }
        return $pos;
    }

    private static function placeData(array &$m, int $size, array $codewords): void
    {
        $bits   = '';
        foreach ($codewords as $cw) {
            $bits .= sprintf('%08b', $cw);
        }

        $bitLen = strlen($bits);
        $bitIdx = 0;
        $col    = $size - 1;
        $goUp   = true;

        while ($col >= 0) {
            if ($col === 6) { $col--; continue; }

            $range = $goUp
                ? range($size - 1, 0, -1)
                : range(0, $size - 1);

            foreach ($range as $row) {
                foreach ([0, -1] as $dc) {
                    $c = $col + $dc;
                    if ($c < 0) continue;
                    if ($m[$row][$c] !== null) continue;

                    $bit         = ($bitIdx < $bitLen) ? (int)$bits[$bitIdx++] : 0;
                    $m[$row][$c] = (bool)$bit;
                }
            }

            $col -= 2;
            $goUp = !$goUp;
        }
    }

    // ── Masking ───────────────────────────────────────────────────────────────

    private static function bestMask(array $matrix, array $funcMatrix, int $version): array
    {
        $size       = 17 + 4 * $version;
        $bestScore  = PHP_INT_MAX;
        $bestMatrix = $matrix;
        $bestMask   = 0;

        for ($mask = 0; $mask < 8; $mask++) {
            $m = self::applyMask($matrix, $funcMatrix, $mask);
            self::writeFormatInfo($m, $size, $mask);
            $score = self::penaltyScore($m, $size);
            if ($score < $bestScore) {
                $bestScore  = $score;
                $bestMatrix = $m;
                $bestMask   = $mask;
            }
        }

        return [$bestMask, $bestMatrix];
    }

    private static function applyMask(array $matrix, array $funcMatrix, int $mask): array
    {
        $m    = $matrix;
        $size = count($m);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($funcMatrix[$r][$c] !== null) continue; // functional — skip
                if (self::maskCondition($mask, $r, $c)) {
                    $m[$r][$c] = !$m[$r][$c];
                }
            }
        }
        return $m;
    }

    private static function maskCondition(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
            6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
            7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
        };
    }

    /**
     * Write format information — ISO 18004 Fig.25.
     *
     * FIX: $bits built MSB-first: $bits[0]=bit14 ... $bits[14]=bit0
     *
     * Top-left:  (8,0)=bit14 (8,1)=bit13 (8,2)=bit12 (8,3)=bit11 (8,4)=bit10 (8,5)=bit9
     *            (8,7)=bit8  (8,8)=bit7
     *            (7,8)=bit6  (5,8)=bit5  (4,8)=bit4  (3,8)=bit3  (2,8)=bit2  (1,8)=bit1  (0,8)=bit0
     * Top-right: (8,size-1)=bit0 .. (8,size-8)=bit7
     * Bot-left:  (size-7,8)=bit8 .. (size-1,8)=bit14
     */
    private static function writeFormatInfo(array &$m, int $size, int $mask): void
    {
        $fmt  = self::FORMAT_M[$mask];

        // FIX #1: MSB-first — $bits[0]=bit14, $bits[14]=bit0
        $bits = [];
        for ($i = 14; $i >= 0; $i--) {
            $bits[] = (bool)(($fmt >> $i) & 1);
        }

        // Top-left primary copy — placements are ordered bit14..bit0, matching $bits[0..$14]
        $placements = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        foreach ($placements as $idx => [$r, $c]) {
            $m[$r][$c] = $bits[$idx];
        }

        // FIX #2: Top-right copy — (8,size-1)=bit0 .. (8,size-8)=bit7
        // With MSB-first bits[], bit0=bits[14], bit7=bits[7]
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = $bits[14 - $i];
        }

        // Bottom-left copy — (size-7,8)=bit8 .. (size-1,8)=bit14
        // With MSB-first bits[], bit8=bits[6], bit14=bits[12]
        for ($i = 0; $i < 7; $i++) {
            $m[$size - 7 + $i][8] = $bits[6 + $i];
        }

        // Dark module always dark
        $m[$size - 8][8] = true;
    }

    // ── Penalty Score (ISO 18004 §7.8.3.1) ───────────────────────────────────

    private static function penaltyScore(array $m, int $size): int
    {
        $score = 0;

        // Rule 1: 5+ consecutive same-color in row/col
        for ($r = 0; $r < $size; $r++) {
            $run = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) { $run++; }
                else { if ($run >= 5) $score += $run - 2; $run = 1; }
            }
            if ($run >= 5) $score += $run - 2;
        }
        for ($c = 0; $c < $size; $c++) {
            $run = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($m[$r][$c] === $m[$r - 1][$c]) { $run++; }
                else { if ($run >= 5) $score += $run - 2; $run = 1; }
            }
            if ($run >= 5) $score += $run - 2;
        }

        // Rule 2: 2×2 blocks
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $color = $m[$r][$c];
                if (
                    $color === $m[$r][$c + 1] &&
                    $color === $m[$r + 1][$c] &&
                    $color === $m[$r + 1][$c + 1]
                ) {
                    $score += 3;
                }
            }
        }

        // Rule 3: finder-like patterns
        $p1 = [true, false, true, true, true, false, true, false, false, false, false];
        $p2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size - 10; $c++) {
                $rm1 = $rm2 = $cm1 = $cm2 = true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r][$c + $k] !== $p1[$k]) $rm1 = false;
                    if ($m[$r][$c + $k] !== $p2[$k]) $rm2 = false;
                    if ($r + $k < $size) {
                        if ($m[$r + $k][$c] !== $p1[$k]) $cm1 = false;
                        if ($m[$r + $k][$c] !== $p2[$k]) $cm2 = false;
                    }
                }
                if ($rm1) $score += 40;
                if ($rm2) $score += 40;
                if ($cm1) $score += 40;
                if ($cm2) $score += 40;
            }
        }

        // Rule 4: dark module proportion
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) $dark++;
            }
        }
        $pct    = (int)(abs($dark / ($size * $size) * 100 - 50) / 5);
        $score += $pct * 10;

        return $score;
    }

    // ── SVG Output ────────────────────────────────────────────────────────────

    private static function toSVG(array $matrix, int $moduleSize, int $margin): string
    {
        $size     = count($matrix);
        $total    = ($size + 2 * $margin) * $moduleSize;
        $offsetPx = $margin * $moduleSize;

        $rects = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === true) {
                    $x      = $offsetPx + $c * $moduleSize;
                    $y      = $offsetPx + $r * $moduleSize;
                    $rects .= "<rect x=\"{$x}\" y=\"{$y}\" width=\"{$moduleSize}\" height=\"{$moduleSize}\"/>";
                }
            }
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$total} {$total}" width="{$total}" height="{$total}" role="img" aria-label="QR code">
<rect width="{$total}" height="{$total}" fill="white"/>
<g fill="black">{$rects}</g>
</svg>
SVG;
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    private static function set(array &$m, int $r, int $c, bool $v): void
    {
        if ($r >= 0 && $r < count($m) && $c >= 0 && $c < count($m[0])) {
            $m[$r][$c] = $v;
        }
    }
}
