<?php
// =============================================================================
// Xcamp Gym — مولّد QR نقي بالـ PHP (بدون مكتبات/إنترنت). وضع البايت، تصحيح L،
// الإصدارات 1..4 (كتلة واحدة). يُنتج مصفوفة الوحدات ثم SVG قابل للمسح.
// مُتحقَّق منه بمطابقة مصفوفته لمكتبة segno وفكّه بـ OpenCV.
// =============================================================================

/** جداول GF(256) بالمولّد البدائي 0x11d */
function qr_gf_tables(): array {
    static $exp = null, $log = null;
    if ($exp !== null) return [$exp, $log];
    $exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x; $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11d;
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return [$exp, $log];
}

/** ضرب في GF(256) */
function qr_gf_mul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    [$exp, $log] = qr_gf_tables();
    return $exp[$log[$a] + $log[$b]];
}

/** مولّد كثيرة حدود تصحيح الأخطاء لعدد codewords */
function qr_rs_generator(int $n): array {
    $g = [1];
    [$exp, $log] = qr_gf_tables();
    for ($i = 0; $i < $n; $i++) {
        $ng = array_fill(0, count($g) + 1, 0);
        foreach ($g as $j => $c) {
            $ng[$j]     ^= qr_gf_mul($c, 1);
            $ng[$j + 1] ^= qr_gf_mul($c, $exp[$i]);
        }
        $g = $ng;
    }
    return $g;
}

/** حساب codewords تصحيح الأخطاء لبيانات معطاة */
function qr_rs_encode(array $data, int $ecLen): array {
    $gen = qr_rs_generator($ecLen);
    $res = array_fill(0, $ecLen, 0);
    foreach ($data as $d) {
        $factor = $d ^ $res[0];
        array_shift($res); $res[] = 0;
        if ($factor !== 0) foreach ($gen as $i => $g) if ($i > 0) $res[$i - 1] ^= qr_gf_mul($g, $factor);
    }
    return $res;
}

/**
 * يبني مصفوفة QR (مصفوفة 2D من 0/1) للبيانات النصّية. يرجّع [matrix, size].
 * ثابت: وضع البايت، تصحيح L، أصغر إصدار مناسب من 1..4، أفضل قناع حسب الجزاء.
 */
function qr_matrix(string $data, ?int $forceMask = null): array {
    // سعة البايت (تصحيح L) للإصدارات 1..4 + طول codewords تصحيح الأخطاء + إجمالي codewords البيانات
    $CAP     = [1 => 17, 2 => 32, 3 => 53, 4 => 78];
    $ECLEN   = [1 => 7,  2 => 10, 3 => 15, 4 => 20];
    $DATACW  = [1 => 19, 2 => 34, 3 => 55, 4 => 80];
    $ALIGN   = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26]];
    $bytes = array_values(unpack('C*', $data));
    $len = count($bytes);
    $ver = 0;
    foreach ($CAP as $v => $cap) if ($len <= $cap) { $ver = $v; break; }
    if ($ver === 0) throw new RuntimeException('البيانات أطول من طاقة QR (v4-L).');

    // ---- تيار البِتّات: وضع البايت (0100) + العدّ (8 بت) + البيانات ----
    $bits = '';
    $bits .= '0100';
    $bits .= str_pad(decbin($len), 8, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $totalDataBits = $DATACW[$ver] * 8;
    // منهٍ (حتى 4 أصفار)
    $bits .= str_repeat('0', min(4, $totalDataBits - strlen($bits)));
    // حشو لإكمال بايت (مطابق لـ ISO/segno: يضيف 8 - (len%8) دائمًا — بايت صفري عند المحاذاة)
    $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
    // بايتات حشو 0xEC 0x11 بالتناوب
    $pads = [0xEC, 0x11]; $pi = 0;
    while (strlen($bits) < $totalDataBits) { $bits .= str_pad(decbin($pads[$pi % 2]), 8, '0', STR_PAD_LEFT); $pi++; }
    $bits = substr($bits, 0, $totalDataBits);   // اقتطاع أي فائض عند امتلاء السعة تمامًا
    // codewords البيانات
    $dataCW = [];
    for ($i = 0; $i < strlen($bits); $i += 8) $dataCW[] = bindec(substr($bits, $i, 8));
    // تصحيح الأخطاء (كتلة واحدة)
    $ecCW = qr_rs_encode($dataCW, $ECLEN[$ver]);
    $allCW = array_merge($dataCW, $ecCW);
    // بِتّات نهائية (MSB أولًا)
    $final = '';
    foreach ($allCW as $cw) $final .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);

    $size = 17 + $ver * 4;
    $m = []; $fn = [];   // m: الوحدات، fn: هل الوحدة وظيفية (لا تُقنَّع)
    for ($r = 0; $r < $size; $r++) { $m[$r] = array_fill(0, $size, 0); $fn[$r] = array_fill(0, $size, false); }

    $setF = function ($r, $c, $v) use (&$m, &$fn) { $m[$r][$c] = $v; $fn[$r][$c] = true; };
    // finder + separators
    $placeFinder = function ($ro, $co) use ($setF, $size) {
        for ($r = -1; $r <= 7; $r++) for ($c = -1; $c <= 7; $c++) {
            $rr = $ro + $r; $cc = $co + $c;
            if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) continue;
            $v = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6)) ||
                 ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6)) ||
                 ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4) ? 1 : 0;
            $setF($rr, $cc, $v);
        }
    };
    $placeFinder(0, 0); $placeFinder(0, $size - 7); $placeFinder($size - 7, 0);
    // timing
    for ($i = 8; $i < $size - 8; $i++) { $setF(6, $i, ($i % 2 === 0) ? 1 : 0); $setF($i, 6, ($i % 2 === 0) ? 1 : 0); }
    // dark module
    $setF($size - 8, 8, 1);
    // alignment
    $ap = $ALIGN[$ver];
    foreach ($ap as $ar) foreach ($ap as $ac) {
        if (($ar <= 8 && $ac <= 8) || ($ar <= 8 && $ac >= $size - 9) || ($ar >= $size - 9 && $ac <= 8)) continue;
        for ($r = -2; $r <= 2; $r++) for ($c = -2; $c <= 2; $c++)
            $setF($ar + $r, $ac + $c, (max(abs($r), abs($c)) !== 1) ? 1 : 0);
    }
    // احجز مناطق معلومات الصيغة (تُملأ لاحقًا)
    $reserveFormat = function () use (&$fn, $size) {
        for ($i = 0; $i <= 8; $i++) { if ($i !== 6) { $fn[8][$i] = true; $fn[$i][8] = true; } }
        for ($i = 0; $i < 8; $i++) { $fn[8][$size - 1 - $i] = true; $fn[$size - 1 - $i][8] = true; }
    };
    $reserveFormat();

    // ---- وضع بِتّات البيانات (زجزاج من أسفل-يمين) ----
    // الاتجاه يُشتقّ من موضع العمود (لا بالتبديل) حتى لا يختلّ بعد تخطّي عمود التوقيت.
    // مطابق لخوارزمية ISO/segno: أعمدة بعرض وحدتين، الاتجاه من العمود مع قلبه يسار عمود التوقيت.
    $idx = 0;
    for ($right = $size - 1; $right > 0; $right -= 2) {
        $rr = ($right <= 6) ? $right - 1 : $right;   // تخطّي عمود التوقيت (6)
        for ($vertical = 0; $vertical < $size; $vertical++) {
            for ($z = 0; $z < 2; $z++) {
                $j  = $rr - $z;
                $up = ((($rr & 2) === 0) !== ($j < 6));   // XOR منطقي (تجنّب أولوية xor في PHP)
                $i  = $up ? ($size - 1 - $vertical) : $vertical;
                if ($fn[$i][$j]) continue;
                $bit = ($idx < strlen($final)) ? (int)$final[$idx] : 0;
                $m[$i][$j] = $bit; $idx++;
            }
        }
    }

    // ---- اختيار أفضل قناع ----
    $best = null; $bestPen = PHP_INT_MAX; $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        if ($forceMask !== null && $mask !== $forceMask) continue;
        $mm = $m;
        for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) {
            if ($fn[$r][$c]) continue;
            $inv = match ($mask) {
                0 => ($r + $c) % 2 === 0,
                1 => $r % 2 === 0,
                2 => $c % 3 === 0,
                3 => ($r + $c) % 3 === 0,
                4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
                6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            };
            if ($inv) $mm[$r][$c] ^= 1;
        }
        qr_place_format($mm, $fn, $size, $mask);
        $pen = qr_penalty($mm, $size);
        if ($pen < $bestPen) { $bestPen = $pen; $best = $mm; $bestMask = $mask; }
    }
    return [$best, $size];
}

/** يضع معلومات الصيغة (تصحيح L=01 + القناع) بترميز BCH */
function qr_place_format(array &$m, array $fn, int $size, int $mask): void {
    $data = (0b01 << 3) | $mask;               // L=01
    $bch = $data << 10;
    $g = 0b10100110111;
    for ($i = 4; $i >= 0; $i--) if (($bch >> ($i + 10)) & 1) $bch ^= $g << $i;
    $fi = (($data << 10) | $bch) ^ 0b101010000010010;   // قيمة الصيغة (15 بت، البت 14 الأعلى)
    // وضع مطابق لـ ISO/segno: نسختان حول أنماط الكاشف
    for ($i = 0; $i < 8; $i++) {
        $vbit = ($fi >> $i) & 1;
        $hbit = ($fi >> (14 - $i)) & 1;
        $off  = ($i >= 6) ? 1 : 0;                 // تخطّي عمود/صف التوقيت (6)
        $m[$i + $off][8]        = $vbit;           // عمودي — أعلى اليسار
        $m[8][$i + $off]        = $hbit;           // أفقي — أعلى اليسار
        $m[8][$size - 1 - $i]   = $vbit;           // أفقي — أعلى اليمين
        $m[$size - 1 - $i][8]   = $hbit;           // عمودي — أسفل اليسار
    }
    $m[$size - 8][8] = 1;                          // الوحدة الداكنة
}

/** جزاء القناع (قواعد QR الأربع) */
function qr_penalty(array $m, int $size): int {
    $p = 0;
    // القاعدة 1: تتابعات ≥5
    for ($r = 0; $r < $size; $r++) {
        $run = 1;
        for ($c = 1; $c < $size; $c++) {
            if ($m[$r][$c] === $m[$r][$c-1]) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; }
            else $run = 1;
        }
    }
    for ($c = 0; $c < $size; $c++) {
        $run = 1;
        for ($r = 1; $r < $size; $r++) {
            if ($m[$r][$c] === $m[$r-1][$c]) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; }
            else $run = 1;
        }
    }
    // القاعدة 2: كتل 2×2
    for ($r = 0; $r < $size - 1; $r++) for ($c = 0; $c < $size - 1; $c++)
        if ($m[$r][$c] === $m[$r][$c+1] && $m[$r][$c] === $m[$r+1][$c] && $m[$r][$c] === $m[$r+1][$c+1]) $p += 3;
    // القاعدة 3: نمط 1011101 + 0000
    $pat1 = [1,0,1,1,1,0,1,0,0,0,0]; $pat2 = [0,0,0,0,1,0,1,1,1,0,1];
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c <= $size - 11; $c++) {
        $seg = array_slice($m[$r], $c, 11);
        if ($seg === $pat1 || $seg === $pat2) $p += 40;
    }
    for ($c = 0; $c < $size; $c++) for ($r = 0; $r <= $size - 11; $r++) {
        $seg = []; for ($k = 0; $k < 11; $k++) $seg[] = $m[$r+$k][$c];
        if ($seg === $pat1 || $seg === $pat2) $p += 40;
    }
    // القاعدة 4: توازن الأبيض/الأسود
    $dark = 0; foreach ($m as $row) $dark += array_sum($row);
    $ratio = $dark / ($size * $size) * 100;
    $p += (int)(abs($ratio - 50) / 5) * 10;
    return $p;
}

/** يرسم QR كـ SVG (وحدات سوداء على أبيض) بحدّ هادئ (quiet zone) */
function qr_svg(string $data, int $scale = 6, int $quiet = 4): string {
    [$m, $size] = qr_matrix($data);
    $dim = ($size + $quiet * 2) * $scale;
    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$dim\" height=\"$dim\" viewBox=\"0 0 $dim $dim\" shape-rendering=\"crispEdges\">";
    $svg .= "<rect width=\"$dim\" height=\"$dim\" fill=\"#fff\"/>";
    $svg .= '<path fill="#000" d="';
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) if ($m[$r][$c]) {
        $x = ($c + $quiet) * $scale; $y = ($r + $quiet) * $scale;
        $svg .= "M$x $y h$scale v$scale h-$scale z";
    }
    $svg .= '"/></svg>';
    return $svg;
}
