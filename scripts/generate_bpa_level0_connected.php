<?php
/**
 * BPA Level 0 — interconnected hub-and-spoke (NOT simplified rows).
 * Specific arrows to named modules; labels must be readable; crossings OK.
 */
declare(strict_types=1);

$out = dirname(__DIR__) . '/docs/diagrams/60_BPA_Level0_Whole_System_Mobile.drawio';

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
}

function box(string $id, string $label, float $x, float $y, float $w, float $h, string $fill): string
{
    $style = 'rounded=0;whiteSpace=wrap;html=1;fillColor=' . $fill
        . ';strokeColor=#000000;fontFamily=Helvetica;fontSize=11;fontStyle=1;';
    return '        <mxCell id="' . $id . '" value="' . esc($label) . '" style="' . $style
        . '" vertex="1" parent="1"><mxGeometry x="' . $x . '" y="' . $y
        . '" width="' . $w . '" height="' . $h . '" as="geometry"/></mxCell>' . "\n";
}

function edge(
    string $id,
    string $label,
    string $src,
    string $tgt,
    string $color,
    bool $both = false,
    ?string $exit = null,
    ?string $entry = null,
    array $pts = []
): string {
    $style = 'edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;html=1;'
        . 'strokeColor=' . $color . ';strokeWidth=1.5;fontSize=9;fontFamily=Helvetica;fontColor=#111111;'
        . 'labelBackgroundColor=#FFFFFF;endArrow=classic;'
        . ($both ? 'startArrow=classic;' : '');
    if ($exit !== null) {
        [$ex, $ey] = array_map('floatval', explode(',', $exit));
        $style .= 'exitX=' . $ex . ';exitY=' . $ey . ';';
    }
    if ($entry !== null) {
        [$enx, $eny] = array_map('floatval', explode(',', $entry));
        $style .= 'entryX=' . $enx . ';entryY=' . $eny . ';';
    }
    $geo = '<mxGeometry relative="1" as="geometry"/>';
    if ($pts !== []) {
        $arr = '';
        foreach ($pts as [$px, $py]) {
            $arr .= '<mxPoint x="' . $px . '" y="' . $py . '"/>';
        }
        $geo = '<mxGeometry relative="1" as="geometry"><Array as="points">' . $arr . '</Array></mxGeometry>';
    }
    return '        <mxCell id="' . $id . '" value="' . esc($label) . '" style="' . $style
        . '" edge="1" parent="1" source="' . $src . '" target="' . $tgt . '">' . $geo . '</mxCell>' . "\n";
}

$G = '#82B366';
$O = '#D79B00';
$P = '#E8D5F2';
$Bl = '#DAE8FC';
$b = '';

$b .= '        <mxCell id="t" value="BPA LEVEL 0 — WHOLE CPRF SYSTEM (WEB + MOBILE + INTEGRATIONS) — SPECIFIC MODULE LINKS" '
    . 'style="text;html=1;align=center;fontStyle=1;fontSize=14;fontFamily=Helvetica;" '
    . 'vertex="1" parent="1"><mxGeometry x="200" y="10" width="1400" height="28" as="geometry"/></mxCell>' . "\n";

// ===== ACTORS =====
$b .= box('a_web', 'RESIDENT (WEB)', 40, 140, 150, 44, '#DAE8FC');
$b .= box('a_apk', 'RESIDENT (FLUTTER APK)', 40, 280, 150, 44, '#D5E8D4');
$b .= box('a_staff', 'STAFF / ADMIN', 40, 480, 150, 44, '#FFF2CC');
$b .= box('a_pub', 'PUBLIC / GUEST', 40, 680, 150, 44, '#E1D5E7');

// ===== HUBS =====
$b .= box('web', 'WEB PORTAL' . "\n" . '(SESSION AUTH)', 260, 200, 170, 55, $P);
$b .= box('mob', 'MOBILE COMPANION API' . "\n" . '(/api/mobile/v1 JWT)', 260, 360, 170, 55, $P);

// ===== MODULES (spread out) =====
$b .= box('auth', 'AUTH & USERS', 520, 60, 140, 40, $P);
$b .= box('portal', 'PUBLIC PORTAL', 520, 700, 140, 40, $P);
$b .= box('res', 'RESERVATIONS', 520, 180, 140, 40, $P);
$b .= box('pay', 'PAYMENTS', 520, 280, 140, 40, $P);
$b .= box('att', 'ATTENDANCE / QR', 520, 380, 140, 40, $P);
$b .= box('ai', 'AI / SCHEDULER', 520, 480, 140, 40, $P);
$b .= box('cal', 'CALENDAR', 520, 580, 140, 40, $P);

$b .= box('fac', 'FACILITIES', 760, 100, 140, 40, $P);
$b .= box('occ', 'OCCUPANCY', 760, 220, 140, 40, $P);
$b .= box('notif', 'NOTIFICATIONS', 760, 340, 140, 40, $P);
$b .= box('ann', 'ANNOUNCEMENTS', 760, 460, 140, 40, $P);
$b .= box('rpt', 'REPORTS', 760, 560, 140, 40, $P);
$b .= box('users', 'USER MANAGEMENT', 760, 660, 140, 40, $P);
$b .= box('docs', 'DOCUMENTS / AUDIT', 760, 760, 140, 40, $P);
$b .= box('cimm', 'CIMM SYNC', 760, 40, 140, 40, $P);

// ===== INTEGRATIONS =====
$b .= box('x_cimm', 'CIMM', 1080, 40, 150, 40, $Bl);
$b .= box('x_nom', 'NOMINATIM / OSM', 1080, 100, 150, 40, $Bl);
$b .= box('x_pay', 'PAYMONGO', 1080, 280, 150, 40, $Bl);
$b .= box('x_fcm', 'FCM / SMTP / SMS', 1080, 340, 150, 40, $Bl);
$b .= box('x_gem', 'GEMINI', 1080, 480, 150, 40, $Bl);
$b .= box('x_ml', 'PYTHON ML', 1080, 560, 150, 40, $Bl);

$b .= box('db', 'MYSQL DATABASE', 760, 860, 140, 40, '#FFF2CC');

// ===== Actor → hubs =====
$b .= edge('e1', 'OPEN PORTAL', 'a_web', 'web', $G, false, '1,0.5', '0,0.35');
$b .= edge('e2', 'OPEN COMPANION', 'a_apk', 'mob', $G, false, '1,0.5', '0,0.5');
$b .= edge('e3', 'OPEN DASHBOARD', 'a_staff', 'web', $O, false, '1,0.5', '0,0.85');
$b .= edge('e4', 'BROWSE SITE', 'a_pub', 'portal', $G, false, '1,0.5', '0,0.5');

// ===== WEB PORTAL → modules (specific) =====
$b .= edge('w1', 'AUTHENTICATE', 'web', 'auth', $G, false, '1,0.15', '0,0.5');
$b .= edge('w2', 'SUBMIT BOOKING', 'web', 'res', $G, false, '1,0.35', '0,0.5');
$b .= edge('w3', 'START PAYMENT', 'web', 'pay', $G, false, '1,0.55', '0,0.5');
$b .= edge('w4', 'CHECK-IN / OUT', 'web', 'att', $G, false, '1,0.75', '0,0.5');
$b .= edge('w5', 'CHAT / SCHEDULE', 'web', 'ai', $G, false, '0.5,1', '0,0.5');
$b .= edge('w6', 'VIEW CALENDAR', 'web', 'cal', $G, false, '0.25,1', '0,0.5');
$b .= edge('w7', 'MANAGE FACILITIES', 'web', 'fac', $O, false, '0.85,0', '0,0.5');
$b .= edge('w8', 'LIVE OCCUPANCY', 'web', 'occ', $O, false, '0.7,0', '0,0.5');
$b .= edge('w9', 'READ / SEND ALERTS', 'web', 'notif', $G, false, '0.15,1', '0,0.5');
$b .= edge('w10', 'PUBLISH NEWS', 'web', 'ann', $O, false, '0.05,1', '0,0.5');
$b .= edge('w11', 'EXPORT REPORTS', 'web', 'rpt', $O, false, '0,0.9', '0,0.5');
$b .= edge('w12', 'MANAGE USERS', 'web', 'users', $O, false, '0,0.7', '0,0.5');
$b .= edge('w13', 'AUDIT / ARCHIVE', 'web', 'docs', $O, false, '0,0.5', '0,0.5');
$b .= edge('w14', 'TRIGGER CIMM SYNC', 'web', 'cimm', $O, false, '0.95,0', '0,0.5');

// ===== MOBILE API → modules (specific, shared resident features) =====
$b .= edge('m1', 'AUTHENTICATE', 'mob', 'auth', $G, false, '1,0.15', '0,0.5');
$b .= edge('m2', 'SUBMIT BOOKING', 'mob', 'res', $G, false, '1,0.3', '0,0.5');
$b .= edge('m3', 'START PAYMENT', 'mob', 'pay', $G, false, '1,0.45', '0,0.5');
$b .= edge('m4', 'CHECK-IN / OUT', 'mob', 'att', $G, false, '1,0.6', '0,0.5');
$b .= edge('m5', 'CHAT / SCHEDULE', 'mob', 'ai', $G, false, '1,0.8', '0,0.5');
$b .= edge('m6', 'VIEW CALENDAR', 'mob', 'cal', $G, false, '0.5,1', '0,0.5');
$b .= edge('m7', 'READ ALERTS', 'mob', 'notif', $G, false, '0.75,1', '0,0.5');

// ===== Module ↔ module =====
$b .= edge('i1', 'BLOCK SLOTS', 'fac', 'res', $O, false, '0,0.5', '1,0.5');
$b .= edge('i2', 'STATUS EVENT', 'res', 'notif', $G, false, '1,0.5', '0,0.5');
$b .= edge('i3', 'PAYMENT EVENT', 'pay', 'notif', $G, false, '1,0.5', '0,0.5');
$b .= edge('i4', 'UPDATE LIVE COUNT', 'att', 'occ', $G, false, '1,0.5', '0,0.5');
$b .= edge('i5', 'RISK SCORE', 'ai', 'res', $O, true, '0.5,0', '0.5,1');
$b .= edge('i6', 'APPLY BLACKOUTS', 'cimm', 'fac', $O, true, '0.5,1', '0.5,0');
$b .= edge('i7', 'OPTIONAL DRAFT POST', 'cimm', 'ann', $G, false, '1,0.5', '1,0.5');
$b .= edge('i8', 'SHOW EVENTS', 'res', 'cal', $G, false, '0.5,1', '0.5,0');
$b .= edge('i9', 'STAFF APPROVE ALSO', 'a_staff', 'res', $O, false, '1,0.25', '0,0.75');

// ===== Module → external (specific) =====
$b .= edge('x1', 'PULL SCHEDULES', 'cimm', 'x_cimm', $O, true, '1,0.5', '0,0.5');
$b .= edge('x2', 'GEOCODE ADDRESS', 'fac', 'x_nom', $O, true, '1,0.5', '0,0.5');
$b .= edge('x3', 'CHECKOUT / WEBHOOK', 'pay', 'x_pay', $O, true, '1,0.5', '0,0.5');
$b .= edge('x4', 'PUSH / EMAIL / SMS', 'notif', 'x_fcm', $G, false, '1,0.5', '0,0.5');
$b .= edge('x5', 'CHAT QUERY / RESPONSE', 'ai', 'x_gem', $O, true, '1,0.5', '0,0.5');
$b .= edge('x6', 'PREDICT RISK / PURPOSE', 'ai', 'x_ml', $O, true, '1,0.75', '0,0.5');

// ===== Persist =====
$b .= edge('d1', 'PERSIST / QUERY', 'res', 'db', $O, true, '0.5,1', '0.25,0');
$b .= edge('d2', 'PERSIST / QUERY', 'fac', 'db', $O, true, '0.5,1', '0.5,0');
$b .= edge('d3', 'PERSIST / QUERY', 'notif', 'db', $O, true, '0.5,1', '0.75,0');
$b .= edge('d4', 'PERSIST / QUERY', 'users', 'db', $O, true, '0.5,1', '0.9,0');
$b .= edge('d5', 'PERSIST / QUERY', 'docs', 'db', $O, true, '0.5,1', '1,0');

$b .= '        <mxCell id="leg" value="Green = request / notify · Orange = sync / payment / data · Every arrow ends on a named module or integration (Web Portal + Mobile API both reach shared resident modules). Line paths may bend; labels name the exact link." '
    . 'style="text;html=1;fontSize=10;fontColor=#555555;fontFamily=Helvetica;" vertex="1" parent="1">'
    . '<mxGeometry x="40" y="930" width="1200" height="40" as="geometry"/></mxCell>' . "\n";

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<mxfile host="app.diagrams.net" modified="' . date('c') . '" agent="FRS BPA Full Mesh" version="22.1.0" type="device">' . "\n"
    . '  <diagram id="60" name="BPA Level 0 Whole System Connected">' . "\n"
    . '    <mxGraphModel dx="1600" dy="1000" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1400" pageHeight="1000" math="0" shadow="0">' . "\n"
    . "      <root>\n        <mxCell id=\"0\"/>\n        <mxCell id=\"1\" parent=\"0\"/>\n"
    . $b
    . "      </root>\n    </mxGraphModel>\n  </diagram>\n</mxfile>\n";

file_put_contents($out, $xml);
echo "Wrote $out\n";
