<?php
/**
 * Generate whole-system thesis diagrams (60–65 family), split by module where needed.
 * Usage: php scripts/generate_whole_system_diagrams.php
 */
declare(strict_types=1);

$outDir = dirname(__DIR__) . '/docs/diagrams';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
}

function mxfile(string $id, string $name, int $w, int $h, string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<mxfile host="app.diagrams.net" modified="' . date('c') . '" agent="FRS Whole System" version="22.1.0" type="device">' . "\n"
        . '  <diagram id="' . esc($id) . '" name="' . esc($name) . '">' . "\n"
        . '    <mxGraphModel dx="1400" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . $w . '" pageHeight="' . $h . '" math="0" shadow="0">' . "\n"
        . "      <root>\n"
        . "        <mxCell id=\"0\"/>\n"
        . "        <mxCell id=\"1\" parent=\"0\"/>\n"
        . $body
        . "      </root>\n"
        . "    </mxGraphModel>\n"
        . "  </diagram>\n"
        . "</mxfile>\n";
}

function v(string $id, string $val, string $style, float $x, float $y, float $w, float $h): string
{
    return '        <mxCell id="' . $id . '" value="' . esc($val) . '" style="' . $style . '" vertex="1" parent="1">'
        . '<mxGeometry x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" as="geometry"/></mxCell>' . "\n";
}

function e(string $id, string $val, string $style, string $src, string $tgt, string $pts = ''): string
{
    $geo = $pts !== ''
        ? '<mxGeometry relative="1" as="geometry"><Array as="points">' . $pts . '</Array></mxGeometry>'
        : '<mxGeometry relative="1" as="geometry"/>';
    return '        <mxCell id="' . $id . '" value="' . esc($val) . '" style="' . $style . '" edge="1" parent="1" source="' . $src . '" target="' . $tgt . '">' . $geo . '</mxCell>' . "\n";
}

function el(string $id, string $val, string $style, float $x1, float $y1, float $x2, float $y2): string
{
    return '        <mxCell id="' . $id . '" value="' . esc($val) . '" style="' . $style . '" edge="1" parent="1">'
        . '<mxGeometry relative="1" as="geometry"><mxPoint x="' . $x1 . '" y="' . $y1 . '" as="sourcePoint"/><mxPoint x="' . $x2 . '" y="' . $y2 . '" as="targetPoint"/></mxGeometry></mxCell>' . "\n";
}

function save(string $file, string $xml): void
{
    global $outDir;
    file_put_contents($outDir . '/' . $file, $xml);
    echo "Created: docs/diagrams/$file\n";
}

$R = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;fontFamily=Helvetica;fontSize=11;fontStyle=1;';
$RP = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#E8D5F2;strokeColor=#000000;fontFamily=Helvetica;fontSize=11;fontStyle=1;';
$RB = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#DAE8FC;strokeColor=#000000;fontFamily=Helvetica;fontSize=11;fontStyle=1;';
$RY = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#FFF2CC;strokeColor=#000000;fontFamily=Helvetica;fontSize=11;fontStyle=1;';
$RG = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#D5E8D4;strokeColor=#000000;fontFamily=Helvetica;fontSize=11;fontStyle=1;';
$TITLE = 'text;html=1;strokeColor=none;fillColor=none;align=center;fontStyle=1;fontSize=14;fontFamily=Helvetica;';
$NOTE = 'text;html=1;strokeColor=none;fillColor=none;align=left;fontSize=10;fontColor=#555555;fontFamily=Helvetica;';
$ACTOR = 'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;html=1;outlineConnect=0;fillColor=#FFFFFF;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;fontStyle=1;';
$EDGE = 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;fontFamily=Helvetica;rounded=0;';
$EDGEG = 'endArrow=classic;html=1;strokeColor=#82B366;strokeWidth=1.5;fontSize=9;fontFamily=Helvetica;';
$EDGEO = 'endArrow=classic;html=1;strokeColor=#D79B00;strokeWidth=1.5;fontSize=9;fontFamily=Helvetica;';
$EDGE2 = 'endArrow=classic;startArrow=classic;html=1;strokeColor=#D79B00;strokeWidth=1.5;fontSize=9;fontFamily=Helvetica;';
$UC = 'ellipse;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;fontStyle=1;';
$UCLINK = 'endArrow=none;html=1;strokeColor=#000000;curved=1;';
$PROC = 'rounded=1;whiteSpace=wrap;html=1;fillColor=#BDD7EE;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;align=center;';
$DS = 'shape=partialRectangle;whiteSpace=wrap;html=1;left=1;right=0;top=0;bottom=0;fillColor=#FFFFFF;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;align=left;spacingLeft=6;';
$EXT = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#666666;fontColor=#FFFFFF;strokeColor=#000000;fontStyle=1;fontSize=10;fontFamily=Helvetica;';
$FLOW_START = 'ellipse;whiteSpace=wrap;html=1;fillColor=#F4CCCC;strokeColor=#000000;fontStyle=1;fontSize=12;fontFamily=Helvetica;';
$FLOW_IO = 'shape=parallelogram;perimeter=parallelogramPerimeter;whiteSpace=wrap;html=1;fixedSize=1;fillColor=#CFE2F3;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;';
$FLOW_P = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#FFE599;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;';
$FLOW_D = 'rhombus;whiteSpace=wrap;html=1;fillColor=#F9CB9C;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;';
$FLOW_E = 'endArrow=classic;html=1;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;';

// ============================================================================
// 60 BPA Level 0 — full interconnected mesh (specific module arrows); see dedicated script
// ============================================================================
require __DIR__ . '/generate_bpa_level0_connected.php';

// ============================================================================
// 61 API Gateway — full module list, clean columns (user said this is good — expand services)
// ============================================================================
$svcs = [
    'AUTH / USERS', 'PUBLIC PORTAL', 'FACILITIES', 'RESERVATIONS',
    'PAYMENTS', 'ATTENDANCE / QR', 'OCCUPANCY', 'AI / SCHEDULER',
    'CALENDAR', 'NOTIFICATIONS', 'ANNOUNCEMENTS', 'REPORTS',
    'USER MGMT', 'DOCUMENTS / AUDIT', 'CIMM SYNC', 'MOBILE API',
];
$body = v('t', 'API GATEWAY — WHOLE SYSTEM + INTEGRATIONS + MOBILE APP', $TITLE, 100, 15, 1400, 28);
$body .= v('cliBox', '', 'rounded=1;fillColor=none;strokeColor=#000000;strokeWidth=1.5;', 140, 80, 150, 360);
$body .= v('cliLbl', 'CLIENTS', 'text;html=1;align=center;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 140, 450, 150, 22);
$body .= v('web', 'WEB BROWSER', $R, 160, 110, 110, 55);
$body .= v('apk', 'FLUTTER&#xa;COMPANION', $R, 160, 200, 110, 55);
$body .= v('cron', 'CRON /&#xa;WEBHOOKS', $R, 160, 290, 110, 55);
$body .= v('user', 'USER', $ACTOR, 40, 200, 35, 60);

$body .= v('gwBox', '', 'rounded=1;fillColor=none;strokeColor=#000000;strokeWidth=1.5;', 400, 70, 240, 420);
$body .= v('gwLbl', 'API GATEWAY', 'text;html=1;align=center;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 400, 500, 240, 22);
$body .= v('g1', 'AUTHENTICATION&#xa;SESSION · JWT · OTP', $R, 420, 95, 200, 50);
$body .= v('g2', 'HTTPS / CSRF', $R, 420, 165, 200, 40);
$body .= v('g3', 'ROUTE DISPATCH&#xa;index.php', $R, 420, 225, 200, 50);
$body .= v('g4', 'MOBILE JWT GUARD&#xa;/api/mobile/v1/*', $R, 420, 295, 200, 50);
$body .= v('g5', 'AUDIT LOG', $R, 420, 365, 200, 40);

$body .= v('svcBox', '', 'rounded=1;fillColor=none;strokeColor=#000000;strokeWidth=1.5;', 740, 60, 360, 520);
$body .= v('svcLbl', 'APP SERVICES (ALL MODULES)', 'text;html=1;align=center;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 740, 590, 360, 22);
foreach ($svcs as $i => $s) {
    $col = $i % 2;
    $row = intdiv($i, 2);
    $body .= v('s' . $i, $s, $R, 760 + $col * 170, 80 + $row * 55, 155, 42);
}

$body .= v('extBox', '', 'rounded=1;fillColor=none;strokeColor=#000000;strokeWidth=1.5;', 1200, 100, 170, 360);
$body .= v('extLbl', 'EXTERNAL', 'text;html=1;align=center;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 1200, 470, 170, 22);
$body .= v('x1', 'PAYMONGO', $R, 1220, 130, 130, 40);
$body .= v('x2', 'CIMM', $R, 1220, 200, 130, 40);
$body .= v('x3', 'GEMINI', $R, 1220, 270, 130, 40);
$body .= v('x4', 'FCM / SMTP', $R, 1220, 340, 130, 40);
$body .= v('db', "MYSQL\nDATABASE", 'shape=cylinder3;whiteSpace=wrap;html=1;boundedLbl=1;backgroundOutline=1;size=10;fillColor=#FFFFFF;strokeColor=#000000;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 470, 580, 120, 70);

$body .= e('f0', '', $EDGE, 'user', 'apk');
$body .= e('f1', 'Request', $EDGE, 'cliBox', 'gwBox');
$body .= e('f2', 'Response', $EDGE, 'gwBox', 'cliBox');
$body .= e('f3', 'Routing', $EDGE, 'gwBox', 'svcBox');
$body .= e('f4', 'Aggregation', $EDGE, 'gwBox', 'svcBox');
$body .= e('f5', 'Checkout', $EDGE, 's4', 'x1');
$body .= e('f6', 'Sync', $EDGE, 's14', 'x2');
$body .= e('f7', 'Prompt', $EDGE, 's7', 'x3');
$body .= e('f8', 'Push / Email', $EDGE, 's9', 'x4');
$body .= e('f9', 'Audit', $EDGE, 'g5', 'db');
$body .= e('f10', 'CRUD', $EDGE, 'svcBox', 'db');
save('61_API_Gateway_Whole_System_Mobile.drawio', mxfile('61', 'API Gateway Whole System', 1600, 750, $body));

// ============================================================================
// 62 DFD Overview — all Level-1 processes in rows (no crossing within row)
// ============================================================================
function dfdRow(string &$body, int $n, string $pA, string $ds, string $pB, string $color, float $y, string $fMid1, string $fMid2): void
{
    global $DS;
    $proc = 'rounded=1;whiteSpace=wrap;html=1;fillColor=' . $color . ';strokeColor=#000000;fontSize=10;fontFamily=Helvetica;align=center;';
    $pa = 'p' . $n . 'a';
    $d = 'd' . $n;
    $pb = 'p' . $n . 'b';
    $body .= v($pa, $pA, $proc, 220, $y, 160, 60);
    $body .= v($d, $ds, $DS, 470, $y + 5, 170, 50);
    $body .= v($pb, $pB, $proc, 730, $y, 170, 60);
    $body .= e('fm' . $n . 'a', $fMid1, 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;', $pa, $d);
    $body .= e('fm' . $n . 'b', $fMid2, 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;', $d, $pb);
}

$body = v('t', 'DFD LEVEL 1 — WHOLE SYSTEM OVERVIEW (ALL MODULES + MOBILE + INTEGRATIONS)', $TITLE, 80, 15, 1400, 28);
$body .= v('eeR', 'RESIDENT&#xa;WEB / MOBILE', $EXT, 40, 200, 120, 55);
$body .= v('eeS', 'STAFF / ADMIN', $EXT, 40, 700, 120, 50);
$body .= v('eeP', 'PUBLIC', $EXT, 40, 80, 120, 45);
$body .= v('xePay', 'PAYMONGO', $EXT, 1000, 470, 110, 40);
$body .= v('xeCimm', 'CIMM', $EXT, 1000, 670, 110, 40);
$body .= v('xeGem', 'GEMINI', $EXT, 1000, 870, 110, 40);
$body .= v('xeFcm', 'FCM/SMTP', $EXT, 1000, 970, 110, 40);
$body .= v('xeNom', 'NOMINATIM', $EXT, 1000, 275, 110, 40);
$body .= v('xeMl', 'PYTHON ML', $EXT, 1000, 1175, 110, 40);

$edge = 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;';

dfdRow($body, 1, "1.0 Public Portal", 'D0  Content', "1.1 Serve Pages", '#D9EAD3', 70, 'read', 'html');
$body .= e('f1in', 'browse', $edge, 'eeP', 'p1a');
$body .= e('f1out', 'pages', $edge, 'p1b', 'eeP');

dfdRow($body, 2, "2.0 Authenticate", 'D1  Users', "2.1 Session / JWT", '#BDD7EE', 170, 'lookup', 'profile');
$body .= e('f2in', 'credentials', $edge, 'eeR', 'p2a');
$body .= e('f2out', 'token', $edge, 'p2b', 'eeR');

dfdRow($body, 3, "3.0 Manage Facilities", 'D2  Facilities', "3.1 Geocode / Hours", '#FCE4D6', 270, 'save', 'config');
$body .= e('f3in', 'CRUD', $edge, 'eeS', 'p3a');
$body .= e('f3out', 'lookup', $edge, 'p3b', 'xeNom');

dfdRow($body, 4, "4.0 Submit Booking", 'D3  Reservations', "4.1 Validate / Auto", '#FFE599', 370, 'insert', 'status');
$body .= e('f4in', 'booking', $edge, 'eeR', 'p4a');
$body .= e('f4out', 'confirm', $edge, 'p4b', 'eeR');

dfdRow($body, 5, "5.0 Start Payment", 'T1  Pay Hold', "5.1 Webhook Confirm", '#F8CBAD', 470, 'hold', 'checkout');
$body .= e('f5in', 'pay', $edge, 'eeR', 'p5a');
$body .= e('f5out', 'event', $edge, 'p5b', 'xePay');

dfdRow($body, 6, "6.0 Staff Approve", 'D3  Reservations', "6.1 Notify Decision", '#D9A7E7', 570, 'update', 'result');
$body .= e('f6in', 'decision', $edge, 'eeS', 'p6a');

dfdRow($body, 7, "7.0 Sync CIMM", 'D4  Blackouts', "7.1 Apply Status", '#A9D08E', 670, 'store', 'apply');
$body .= e('f7in', 'trigger', $edge, 'eeS', 'p7a');
$body .= e('f7out', 'schedules', $edge, 'p7b', 'xeCimm');

dfdRow($body, 8, "8.0 QR Check-in", 'D5  Attendance', "8.1 Update Occupancy", '#9DC3E6', 770, 'log', 'live');
$body .= e('f8in', 'scan', $edge, 'eeR', 'p8a');
$body .= e('f8out', 'result', $edge, 'p8b', 'eeR');

dfdRow($body, 9, "9.0 AI Chat / Schedule", 'T2  Chat Ctx', "9.1 Gemini / ML Reply", '#B4C6E7', 870, 'context', 'prompt');
$body .= e('f9in', 'query', $edge, 'eeR', 'p9a');
$body .= e('f9out', 'LLM', $edge, 'p9b', 'xeGem');

dfdRow($body, 10, "10.0 Notify User", 'D6  Notifications', "10.1 Push/Email/SMS", '#C6EFCE', 970, 'store', 'send');
$body .= e('f10in', 'event', $edge, 'p6b', 'p10a');
$body .= e('f10out', 'push', $edge, 'p10b', 'xeFcm');

dfdRow($body, 11, "11.0 Reports / Audit", 'D7  Audit Log', "11.1 Export CSV/PDF", '#FFF2CC', 1070, 'read', 'export');
$body .= e('f11in', 'request', $edge, 'eeS', 'p11a');
$body .= e('f11out', 'report', $edge, 'p11b', 'eeS');

dfdRow($body, 12, "12.0 Risk Score", 'T3  ML Buffer', "12.1 Auto-approval Aid", '#E2D5F1', 1170, 'buffer', 'score');
$body .= e('f12in', 'features', $edge, 'p4a', 'p12a');
$body .= e('f12out', 'predict', $edge, 'p12b', 'xeMl');

$body .= v('leg', 'Each horizontal row is one module flow (no line crossings). Resident Web and Flutter Companion share the same processes via Session or JWT. See split DFD files 62b–62g for detail.', $NOTE, 40, 1260, 1100, 40);
save('62_DFD_Level1_Whole_System_Mobile.drawio', mxfile('62', 'DFD Level 1 Overview', 1200, 1350, $body));

// Helper: simple 3-box DFD detail
function saveDfdDetail(string $file, string $title, array $rows): void
{
    global $TITLE, $NOTE, $DS, $EXT;
    $body = v('t', $title, $TITLE, 40, 15, 1100, 28);
    $y = 70;
    $n = 0;
    foreach ($rows as $r) {
        $proc = 'rounded=1;whiteSpace=wrap;html=1;fillColor=' . $r['color'] . ';strokeColor=#000000;fontSize=10;fontFamily=Helvetica;';
        $body .= v('pa' . $n, $r['pa'], $proc, 200, $y, 170, 55);
        $body .= v('d' . $n, $r['ds'], $DS, 460, $y + 2, 180, 50);
        $body .= v('pb' . $n, $r['pb'], $proc, 730, $y, 170, 55);
        if (!empty($r['left'])) {
            $body .= v('L' . $n, $r['left'], $EXT, 30, $y + 5, 120, 45);
            $body .= e('eL' . $n, $r['fin'] ?? '', 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;', 'L' . $n, 'pa' . $n);
        }
        $body .= e('e1' . $n, $r['f2'] ?? '', 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;', 'pa' . $n, 'd' . $n);
        $body .= e('e2' . $n, $r['f3'] ?? '', 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;', 'd' . $n, 'pb' . $n);
        if (!empty($r['right'])) {
            $body .= v('R' . $n, $r['right'], $EXT, 980, $y + 5, 120, 45);
            $body .= e('eR' . $n, $r['fout'] ?? '', 'endArrow=classic;html=1;strokeColor=#000000;fontSize=9;', 'pb' . $n, 'R' . $n);
        }
        $y += 90;
        $n++;
    }
    $body .= v('leg', 'Split DFD — one module family per diagram. Orthogonal flows; no crossings.', $NOTE, 30, $y + 10, 900, 30);
    save($file, mxfile(basename($file, '.drawio'), $title, 1200, $y + 80, $body));
}

saveDfdDetail('62b_DFD_Auth_Users_Public.drawio', 'DFD L1 — AUTH, USERS, PUBLIC PORTAL', [
    ['pa' => '2.0 Register / Login', 'ds' => 'D1 Users', 'pb' => '2.1 OTP / Session / JWT', 'color' => '#BDD7EE', 'left' => 'RESIDENT', 'right' => 'SMTP', 'fin' => 'credentials', 'f2' => 'store', 'f3' => 'profile', 'fout' => 'OTP email'],
    ['pa' => '2.2 Manage Users', 'ds' => 'D1 Users', 'pb' => '2.3 Roles / Lock', 'color' => '#BDD7EE', 'left' => 'ADMIN', 'fin' => 'admin action', 'f2' => 'update', 'f3' => 'record'],
    ['pa' => '1.0 Public Browse', 'ds' => 'D0 Content', 'pb' => '1.1 FAQ / Contact', 'color' => '#D9EAD3', 'left' => 'GUEST', 'fin' => 'request', 'f2' => 'read', 'f3' => 'page'],
]);

saveDfdDetail('62c_DFD_Booking_Payments.drawio', 'DFD L1 — RESERVATIONS & PAYMENTS', [
    ['pa' => '4.0 Book Facility', 'ds' => 'D3 Reservations', 'pb' => '4.1 Auto-Approve', 'color' => '#FFE599', 'left' => 'RESIDENT W/M', 'fin' => 'form', 'f2' => 'insert', 'f3' => 'status'],
    ['pa' => '6.0 Staff Review', 'ds' => 'D3 Reservations', 'pb' => '6.1 Approve/Deny', 'color' => '#D9A7E7', 'left' => 'STAFF', 'fin' => 'decision', 'f2' => 'update', 'f3' => 'result'],
    ['pa' => '5.0 Pay Now', 'ds' => 'T1 Pay Hold', 'pb' => '5.1 Webhook', 'color' => '#F8CBAD', 'left' => 'RESIDENT', 'right' => 'PAYMONGO', 'fin' => 'pay', 'f2' => 'hold', 'f3' => 'checkout', 'fout' => 'paid event'],
]);

saveDfdDetail('62d_DFD_Facilities_CIMM.drawio', 'DFD L1 — FACILITIES, BLACKOUTS, CIMM', [
    ['pa' => '3.0 Facility CRUD', 'ds' => 'D2 Facilities', 'pb' => '3.1 Hours / QR', 'color' => '#FCE4D6', 'left' => 'STAFF', 'fin' => 'CRUD', 'f2' => 'save', 'f3' => 'config'],
    ['pa' => '3.2 Blackout Dates', 'ds' => 'D4 Blackouts', 'pb' => '3.3 Block Slots', 'color' => '#FCE4D6', 'left' => 'STAFF', 'fin' => 'dates', 'f2' => 'write', 'f3' => 'rules'],
    ['pa' => '7.0 CIMM Pull', 'ds' => 'D4 Blackouts', 'pb' => '7.1 Status Sync', 'color' => '#A9D08E', 'left' => 'CRON', 'right' => 'CIMM', 'fin' => 'run', 'f2' => 'store', 'f3' => 'apply', 'fout' => 'schedules'],
    ['pa' => '3.4 Geocode', 'ds' => 'D2 Facilities', 'pb' => '3.5 Lat/Long', 'color' => '#FCE4D6', 'left' => 'STAFF', 'right' => 'NOMINATIM', 'fin' => 'address', 'f2' => 'update', 'f3' => 'coords', 'fout' => 'lookup'],
]);

saveDfdDetail('62e_DFD_Attendance_Occupancy.drawio', 'DFD L1 — ATTENDANCE, QR, OCCUPANCY', [
    ['pa' => '8.0 QR / Manual In', 'ds' => 'D5 Attendance', 'pb' => '8.1 Check-out', 'color' => '#9DC3E6', 'left' => 'RESIDENT W/M', 'fin' => 'scan', 'f2' => 'log', 'f3' => 'update'],
    ['pa' => '8.2 Occupancy Board', 'ds' => 'D5 Attendance', 'pb' => '8.3 Live API', 'color' => '#9DC3E6', 'left' => 'STAFF', 'fin' => 'poll', 'f2' => 'read', 'f3' => 'json'],
    ['pa' => '8.4 No-show Cron', 'ds' => 'D5 Attendance', 'pb' => '8.5 Violations', 'color' => '#9DC3E6', 'left' => 'CRON', 'fin' => 'tick', 'f2' => 'flag', 'f3' => 'record'],
]);

saveDfdDetail('62f_DFD_AI_Comms.drawio', 'DFD L1 — AI, CALENDAR, NOTIFICATIONS', [
    ['pa' => '9.0 Chatbot Query', 'ds' => 'T2 Chat Ctx', 'pb' => '9.1 Gemini Reply', 'color' => '#B4C6E7', 'left' => 'RESIDENT W/M', 'right' => 'GEMINI', 'fin' => 'message', 'f2' => 'context', 'f3' => 'prompt', 'fout' => 'LLM'],
    ['pa' => '9.2 Smart Scheduler', 'ds' => 'D3 Reservations', 'pb' => '9.3 Suggest Slot', 'color' => '#B4C6E7', 'left' => 'RESIDENT', 'right' => 'PYTHON ML', 'fin' => 'prefs', 'f2' => 'query', 'f3' => 'hints', 'fout' => 'score'],
    ['pa' => '10.0 Create Notif', 'ds' => 'D6 Notifications', 'pb' => '10.1 Deliver', 'color' => '#C6EFCE', 'left' => 'SYSTEM', 'right' => 'FCM/SMTP', 'fin' => 'event', 'f2' => 'store', 'f3' => 'send', 'fout' => 'push'],
    ['pa' => '10.2 Announcements', 'ds' => 'D0 Content', 'pb' => '10.3 Publish', 'color' => '#C6EFCE', 'left' => 'STAFF', 'fin' => 'draft', 'f2' => 'save', 'f3' => 'public'],
]);

saveDfdDetail('62g_DFD_Admin_Reports_Compliance.drawio', 'DFD L1 — REPORTS, AUDIT, DOCUMENTS', [
    ['pa' => '11.0 Build Report', 'ds' => 'D3 Reservations', 'pb' => '11.1 CSV / PDF', 'color' => '#FFF2CC', 'left' => 'STAFF', 'fin' => 'filters', 'f2' => 'aggregate', 'f3' => 'export'],
    ['pa' => '11.2 Audit Trail', 'ds' => 'D7 Audit', 'pb' => '11.3 Filter / PDF', 'color' => '#FFF2CC', 'left' => 'ADMIN', 'fin' => 'query', 'f2' => 'read', 'f3' => 'view'],
    ['pa' => '11.4 Archive Docs', 'ds' => 'D8 Documents', 'pb' => '11.5 Retention', 'color' => '#F4CCCC', 'left' => 'CRON', 'fin' => 'daily', 'f2' => 'move', 'f3' => 'policy'],
]);

// ============================================================================
// 63 Use Case — integrations focus; Resident = web+mobile same; Admin includes resident UCs
// ============================================================================
$ucs = [
    'REGISTER / LOGIN',
    'BROWSE FACILITIES',
    'SUBMIT / MANAGE RESERVATION',
    'PAY VIA PAYMONGO',
    'RECEIVE NOTIFICATIONS',
    'USE AI CHAT / SCHEDULER',
    'QR CHECK-IN / OUT',
    'VIEW CALENDAR / REPORTS',
    'APPROVE / DENY BOOKING',
    'MANAGE FACILITIES / BLACKOUTS',
    'SYNC CIMM MAINTENANCE',
    'MANAGE USERS / AUDIT / DOCS',
    'PUBLISH ANNOUNCEMENTS',
    'GEOCODE ADDRESSES',
];
$body = v('t', 'USE CASE — CPRF INTEGRATIONS WITH OTHER SYSTEMS (WEB + MOBILE)', $TITLE, 100, 15, 1200, 28);
$body .= v('bound', '', 'rounded=0;fillColor=none;strokeColor=#000000;dashed=1;dashPattern=8 8;strokeWidth=1.5;', 380, 70, 380, 1180);
$body .= v('sysn', 'BARANGAY CULIAT PFRS / CPRF', 'text;html=1;align=center;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 380, 1260, 380, 22);
foreach ($ucs as $i => $u) {
    $body .= v('uc' . $i, $u, $UC, 420, 90 + $i * 82, 300, 50);
}
// Actors: one Resident (web+mobile), Staff/Admin, externals
$body .= v('ar', 'RESIDENT&#xa;(WEB + FLUTTER)', $ACTOR, 80, 280, 40, 70);
$body .= v('as', 'STAFF / ADMIN', $ACTOR, 80, 750, 40, 70);
$body .= v('xp', 'PAYMONGO', $ACTOR, 900, 280, 40, 70);
$body .= v('xc', 'CIMM', $ACTOR, 900, 520, 40, 70);
$body .= v('xg', 'GEMINI', $ACTOR, 900, 420, 40, 70);
$body .= v('xf', 'FCM / SMTP', $ACTOR, 900, 340, 40, 70);
$body .= v('xn', 'NOMINATIM', $ACTOR, 900, 980, 40, 70);
$body .= v('note', 'Resident Web and Flutter Companion share the same use cases. Staff/Admin also perform all Resident use cases, plus staff-only ones.', $NOTE, 40, 1300, 1100, 40);

// Resident → shared UCs 0-7
foreach ([0, 1, 2, 3, 4, 5, 6, 7] as $i) {
    $body .= e('lr' . $i, '', $UCLINK, 'ar', 'uc' . $i);
}
// Staff → ALL resident UCs + staff UCs
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13] as $i) {
    $body .= e('ls' . $i, '', $UCLINK, 'as', 'uc' . $i);
}
$body .= e('rp', '', $UCLINK, 'xp', 'uc3');
$body .= e('rf', '', $UCLINK, 'xf', 'uc4');
$body .= e('rg', '', $UCLINK, 'xg', 'uc5');
$body .= e('rc', '', $UCLINK, 'xc', 'uc10');
$body .= e('rn', '', $UCLINK, 'xn', 'uc13');
save('63_UseCase_Integrations_Other_Systems.drawio', mxfile('63', 'Use Case Integrations', 1200, 1400, $body));

// ============================================================================
// Sequences (detailed, split by module)
// ============================================================================
function saveSeq(string $file, string $title, array $parts, array $msgs): void
{
    global $TITLE, $NOTE, $ACTOR, $R;
    $n = count($parts);
    $pageW = max(1200, 80 + $n * 160);
    $pageH = 160 + count($msgs) * 55 + 80;
    $xs = [];
    $body = v('t', $title, $TITLE, 40, 15, $pageW - 80, 28);
    foreach ($parts as $i => $p) {
        $x = 80 + $i * 160;
        $xs[$i] = $x + 50;
        if (!empty($p['actor'])) {
            $body .= v('h' . $i, $p['label'], $ACTOR, $x + 35, 50, 30, 55);
        } else {
            $body .= v('h' . $i, $p['label'], $R, $x, 55, 100, 40);
        }
        $body .= el('ll' . $i, '', 'endArrow=none;dashed=1;html=1;strokeColor=#000000;dashPattern=4 4;', $xs[$i], 120, $xs[$i], $pageH - 60);
    }
    $y = 150;
    foreach ($msgs as $mi => $m) {
        $style = !empty($m['ret'])
            ? 'endArrow=open;html=1;dashed=1;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;'
            : 'endArrow=block;html=1;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;endFill=1;';
        $body .= el('m' . $mi, $m['text'], $style, $xs[$m['from']], $y, $xs[$m['to']], $y);
        $y += 50;
    }
    $body .= v('leg', 'Solid = request · Dashed = response. Split sequence — one module flow per diagram.', $NOTE, 40, $pageH - 45, 800, 30);
    save($file, mxfile(basename($file, '.drawio'), $title, $pageW, $pageH, $body));
}

saveSeq('64a_Sequence_Auth_Web_Mobile.drawio', 'SEQUENCE — AUTH (WEB SESSION + MOBILE JWT)', [
    ['label' => 'User', 'actor' => true],
    ['label' => 'Web / APK'],
    ['label' => 'Auth API'],
    ['label' => 'MySQL'],
    ['label' => 'SMTP'],
], [
    ['from' => 0, 'to' => 1, 'text' => '1. Login form'],
    ['from' => 1, 'to' => 2, 'text' => '2. POST /login or /api/mobile/v1/auth/login'],
    ['from' => 2, 'to' => 3, 'text' => '3. Verify credentials'],
    ['from' => 3, 'to' => 2, 'text' => '4. User row', 'ret' => true],
    ['from' => 2, 'to' => 4, 'text' => '5. Send OTP email'],
    ['from' => 4, 'to' => 0, 'text' => '6. OTP inbox'],
    ['from' => 0, 'to' => 2, 'text' => '7. Submit OTP'],
    ['from' => 2, 'to' => 1, 'text' => '8. Session cookie or JWT', 'ret' => true],
    ['from' => 1, 'to' => 0, 'text' => '9. Enter dashboard / home', 'ret' => true],
]);

saveSeq('64b_Sequence_Booking_Payment_Notify.drawio', 'SEQUENCE — BOOKING → PAYMENT → NOTIFY (WEB OR MOBILE)', [
    ['label' => 'Resident', 'actor' => true],
    ['label' => 'Web/APK'],
    ['label' => 'CPRF API'],
    ['label' => 'MySQL'],
    ['label' => 'PayMongo'],
    ['label' => 'FCM/SMTP'],
], [
    ['from' => 0, 'to' => 1, 'text' => '1. Submit booking'],
    ['from' => 1, 'to' => 2, 'text' => '2. Create reservation'],
    ['from' => 2, 'to' => 3, 'text' => '3. Validate + INSERT'],
    ['from' => 3, 'to' => 2, 'text' => '4. booking id + status', 'ret' => true],
    ['from' => 2, 'to' => 1, 'text' => '5. pending_payment / approved / pending', 'ret' => true],
    ['from' => 0, 'to' => 2, 'text' => '6. Request checkout (if paid)'],
    ['from' => 2, 'to' => 4, 'text' => '7. Create checkout session'],
    ['from' => 4, 'to' => 0, 'text' => '8. checkout_url → pay', 'ret' => true],
    ['from' => 4, 'to' => 2, 'text' => '9. Webhook payment.paid'],
    ['from' => 2, 'to' => 3, 'text' => '10. UPDATE paid + approved'],
    ['from' => 2, 'to' => 5, 'text' => '11. Notify channels'],
    ['from' => 5, 'to' => 0, 'text' => '12. Push / email / in-app'],
]);

saveSeq('64c_Sequence_Staff_Approval.drawio', 'SEQUENCE — STAFF APPROVAL MODULE', [
    ['label' => 'Staff', 'actor' => true],
    ['label' => 'Dashboard'],
    ['label' => 'Reservations'],
    ['label' => 'MySQL'],
    ['label' => 'Notify'],
    ['label' => 'Resident', 'actor' => true],
], [
    ['from' => 0, 'to' => 1, 'text' => '1. Open pending queue'],
    ['from' => 1, 'to' => 2, 'text' => '2. Load reservations'],
    ['from' => 2, 'to' => 3, 'text' => '3. SELECT pending'],
    ['from' => 3, 'to' => 1, 'text' => '4. Queue list', 'ret' => true],
    ['from' => 0, 'to' => 2, 'text' => '5. Approve / Deny / Postpone'],
    ['from' => 2, 'to' => 3, 'text' => '6. UPDATE status + history'],
    ['from' => 2, 'to' => 4, 'text' => '7. createNotification'],
    ['from' => 4, 'to' => 5, 'text' => '8. Email / SMS / FCM / in-app'],
]);

saveSeq('64d_Sequence_CIMM_Sync.drawio', 'SEQUENCE — CIMM MAINTENANCE SYNC', [
    ['label' => 'Cron/Staff'],
    ['label' => 'CIMM Sync'],
    ['label' => 'CIMM API'],
    ['label' => 'MySQL'],
    ['label' => 'Gemini'],
    ['label' => 'Announce'],
], [
    ['from' => 0, 'to' => 1, 'text' => '1. Trigger sync'],
    ['from' => 1, 'to' => 2, 'text' => '2. GET maintenance schedules'],
    ['from' => 2, 'to' => 1, 'text' => '3. Schedule payload', 'ret' => true],
    ['from' => 1, 'to' => 3, 'text' => '4. Upsert blackouts + facility status'],
    ['from' => 1, 'to' => 4, 'text' => '5. Optional draft announcement'],
    ['from' => 4, 'to' => 1, 'text' => '6. Announcement text', 'ret' => true],
    ['from' => 1, 'to' => 5, 'text' => '7. Publish / queue announcement'],
]);

saveSeq('64e_Sequence_QR_Checkin.drawio', 'SEQUENCE — FACILITY QR CHECK-IN (WEB + MOBILE)', [
    ['label' => 'Resident', 'actor' => true],
    ['label' => 'Web/APK'],
    ['label' => 'Check-in API'],
    ['label' => 'MySQL'],
    ['label' => 'Occupancy'],
], [
    ['from' => 0, 'to' => 1, 'text' => '1. Scan facility QR'],
    ['from' => 1, 'to' => 2, 'text' => '2. POST check-in token'],
    ['from' => 2, 'to' => 3, 'text' => '3. Match today booking'],
    ['from' => 3, 'to' => 2, 'text' => '4. Reservation row', 'ret' => true],
    ['from' => 2, 'to' => 3, 'text' => '5. INSERT attendance'],
    ['from' => 2, 'to' => 4, 'text' => '6. Refresh live occupancy'],
    ['from' => 2, 'to' => 1, 'text' => '7. Success / already in', 'ret' => true],
]);

saveSeq('64f_Sequence_AI_Chatbot.drawio', 'SEQUENCE — AI CHATBOT (GEMINI + FALLBACK)', [
    ['label' => 'User', 'actor' => true],
    ['label' => 'Web/APK'],
    ['label' => 'Chat API'],
    ['label' => 'Gemini'],
    ['label' => 'Rules/ML'],
    ['label' => 'MySQL'],
], [
    ['from' => 0, 'to' => 1, 'text' => '1. Send chat message'],
    ['from' => 1, 'to' => 2, 'text' => '2. POST chatbot-api / mobile assistant'],
    ['from' => 2, 'to' => 5, 'text' => '3. Load facilities / context'],
    ['from' => 2, 'to' => 3, 'text' => '4. If key: generateContent'],
    ['from' => 3, 'to' => 2, 'text' => '5. Model reply (or fail)', 'ret' => true],
    ['from' => 2, 'to' => 4, 'text' => '6. Else rules / ML fallback'],
    ['from' => 4, 'to' => 2, 'text' => '7. Fallback reply', 'ret' => true],
    ['from' => 2, 'to' => 1, 'text' => '8. Plain-text answer (+ optional prefill)', 'ret' => true],
]);

// Keep 64 as alias copy of booking sequence for prior references
copy($outDir . '/64b_Sequence_Booking_Payment_Notify.drawio', $outDir . '/64_Sequence_Mobile_Booking_Payment_Notify.drawio');
echo "Created: docs/diagrams/64_Sequence_Mobile_Booking_Payment_Notify.drawio (alias of 64b)\n";

// ============================================================================
// Flowcharts — overview + per module
// ============================================================================
function flowChain(string &$body, array $nodes, string $prefix): void
{
    global $FLOW_START, $FLOW_IO, $FLOW_P, $FLOW_D, $FLOW_E;
    $styles = [
        'start' => $FLOW_START, 'end' => $FLOW_START, 'io' => $FLOW_IO,
        'p' => $FLOW_P, 'd' => $FLOW_D,
    ];
    foreach ($nodes as $i => $n) {
        $st = $styles[$n['t']] ?? $FLOW_P;
        $w = $n['w'] ?? (($n['t'] === 'd') ? 150 : 200);
        $h = $n['h'] ?? (($n['t'] === 'd') ? 80 : 45);
        $body .= v($prefix . $i, $n['v'], $st, $n['x'], $n['y'], $w, $h);
    }
    foreach ($nodes as $i => $n) {
        if (!isset($n['to'])) {
            continue;
        }
        foreach ((array)$n['to'] as $t) {
            $label = '';
            $tid = $t;
            if (is_array($t)) {
                $tid = $t[0];
                $label = $t[1] ?? '';
            }
            $body .= e($prefix . 'e' . $i . '_' . $tid, $label, $FLOW_E, $prefix . $i, $prefix . $tid);
        }
    }
}

$body = v('t', 'FLOWCHART OVERVIEW — WHOLE SYSTEM (ENTRY TO ALL MODULES)', $TITLE, 80, 15, 1000, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 460, 'y' => 60, 'w' => 100, 'h' => 45, 'to' => 1],
    ['t' => 'io', 'v' => 'Open Web or Flutter Companion', 'x' => 400, 'y' => 130, 'to' => 2],
    ['t' => 'd', 'v' => 'Authenticated?', 'x' => 440, 'y' => 210, 'to' => [[3, 'No'], [4, 'Yes']]],
    ['t' => 'p', 'v' => 'Auth module (see 65b)', 'x' => 160, 'y' => 225, 'to' => 2],
    ['t' => 'd', 'v' => 'Select function', 'x' => 430, 'y' => 340, 'w' => 170, 'h' => 90, 'to' => [5, 6, 7, 8, 9, 10, 11]],
    ['t' => 'p', 'v' => 'Booking / Reservation (65c)', 'x' => 40, 'y' => 500, 'w' => 180, 'to' => 12],
    ['t' => 'p', 'v' => 'Payment PayMongo (65d)', 'x' => 240, 'y' => 500, 'w' => 180, 'to' => 12],
    ['t' => 'p', 'v' => 'Staff Approval (65e)', 'x' => 440, 'y' => 500, 'w' => 180, 'to' => 12],
    ['t' => 'p', 'v' => 'CIMM / Facilities (65f)', 'x' => 640, 'y' => 500, 'w' => 180, 'to' => 12],
    ['t' => 'p', 'v' => 'Attendance / QR (65g)', 'x' => 140, 'y' => 600, 'w' => 180, 'to' => 12],
    ['t' => 'p', 'v' => 'AI Chat / Scheduler (65h)', 'x' => 360, 'y' => 600, 'w' => 200, 'to' => 12],
    ['t' => 'p', 'v' => 'Notif / Announce / Reports (65i)', 'x' => 600, 'y' => 600, 'w' => 220, 'to' => 12],
    ['t' => 'end', 'v' => 'Return to home / End', 'x' => 430, 'y' => 720, 'w' => 180, 'h' => 50],
];
flowChain($body, $nodes, 'o');
$body .= v('leg', 'Overview only — detailed Yes/No logic is in per-module flowcharts 65b–65i (includes mobile + integrations).', $NOTE, 40, 800, 900, 40);
save('65a_Flowchart_Overview_All_Modules.drawio', mxfile('65a', 'Flowchart Overview', 1100, 880, $body));

// 65b Auth
$body = v('t', 'FLOWCHART — AUTH & ONBOARDING (WEB + MOBILE)', $TITLE, 80, 15, 900, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'io', 'v' => 'Open Login / Register', 'x' => 370, 'y' => 120, 'to' => 2],
    ['t' => 'd', 'v' => 'Have account?', 'x' => 400, 'y' => 200, 'to' => [[3, 'No'], [4, 'Yes']]],
    ['t' => 'p', 'v' => 'Register + Terms + Captcha', 'x' => 120, 'y' => 215, 'w' => 190, 'to' => 5],
    ['t' => 'p', 'v' => 'Enter email / password', 'x' => 620, 'y' => 215, 'to' => 6],
    ['t' => 'io', 'v' => 'Verify email', 'x' => 140, 'y' => 310, 'to' => 4],
    ['t' => 'd', 'v' => 'Credentials OK?', 'x' => 640, 'y' => 300, 'to' => [[7, 'No'], [8, 'Yes']]],
    ['t' => 'io', 'v' => 'Show error', 'x' => 860, 'y' => 315, 'w' => 120, 'to' => 4],
    ['t' => 'p', 'v' => 'Send OTP (SMTP)', 'x' => 620, 'y' => 420, 'to' => 9],
    ['t' => 'd', 'v' => 'OTP valid?', 'x' => 640, 'y' => 500, 'to' => [[10, 'No'], [11, 'Yes']]],
    ['t' => 'io', 'v' => 'Retry OTP', 'x' => 860, 'y' => 515, 'w' => 120, 'to' => 9],
    ['t' => 'p', 'v' => 'Issue Session (Web) or JWT (Mobile)', 'x' => 560, 'y' => 620, 'w' => 260, 'to' => 12],
    ['t' => 'end', 'v' => 'Enter system / End', 'x' => 620, 'y' => 720, 'w' => 160],
];
flowChain($body, $nodes, 'a');
save('65b_Flowchart_Auth_Onboarding.drawio', mxfile('65b', 'Flowchart Auth', 1100, 820, $body));

// 65c Booking — keep detailed
$body = v('t', 'FLOWCHART — BOOKING / RESERVATION (WEB + MOBILE)', $TITLE, 60, 15, 1000, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 460, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'io', 'v' => 'Select facility + slot (Web or /book)', 'x' => 380, 'y' => 120, 'w' => 260, 'to' => 2],
    ['t' => 'd', 'v' => 'Validation OK?', 'x' => 440, 'y' => 200, 'to' => [[3, 'No'], [4, 'Yes']]],
    ['t' => 'io', 'v' => 'Show errors / alternatives', 'x' => 140, 'y' => 215, 'w' => 200, 'to' => 1],
    ['t' => 'd', 'v' => 'Blackout / CIMM?', 'x' => 440, 'y' => 320, 'to' => [[5, 'Yes'], [6, 'No']]],
    ['t' => 'io', 'v' => 'Block booking', 'x' => 160, 'y' => 335, 'to' => 1],
    ['t' => 'd', 'v' => 'Auto-approve?', 'x' => 440, 'y' => 440, 'to' => [[7, 'No'], [8, 'Yes']]],
    ['t' => 'p', 'v' => 'status=pending → Staff (65e)', 'x' => 140, 'y' => 455, 'w' => 210, 'to' => 9],
    ['t' => 'd', 'v' => 'Payment needed?', 'x' => 440, 'y' => 560, 'to' => [[10, 'Yes'], [11, 'No']]],
    ['t' => 'end', 'v' => 'Wait staff / End', 'x' => 170, 'y' => 560, 'w' => 150],
    ['t' => 'p', 'v' => 'Go to Payment (65d)', 'x' => 200, 'y' => 680, 'to' => 12],
    ['t' => 'p', 'v' => 'status=approved + Notify (65i)', 'x' => 480, 'y' => 680, 'w' => 240, 'to' => 12],
    ['t' => 'end', 'v' => 'End', 'x' => 460, 'y' => 780, 'w' => 100],
];
flowChain($body, $nodes, 'b');
save('65c_Flowchart_Booking_Reservation.drawio', mxfile('65c', 'Flowchart Booking', 1000, 880, $body));
// Also update main 65 to overview pointer
copy($outDir . '/65a_Flowchart_Overview_All_Modules.drawio', $outDir . '/65_Flowchart_Whole_System_Mobile_Integrations.drawio');
echo "Created: docs/diagrams/65_Flowchart_Whole_System_Mobile_Integrations.drawio (alias of 65a overview)\n";

$body = v('t', 'FLOWCHART — PAYMONGO PAYMENT INTEGRATION', $TITLE, 80, 15, 900, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'p', 'v' => 'Create checkout (PayMongo API)', 'x' => 350, 'y' => 130, 'w' => 240, 'to' => 2],
    ['t' => 'io', 'v' => 'Resident pays in browser', 'x' => 360, 'y' => 220, 'to' => 3],
    ['t' => 'd', 'v' => 'Webhook paid?', 'x' => 400, 'y' => 310, 'to' => [[4, 'No'], [5, 'Yes']]],
    ['t' => 'p', 'v' => 'Cron expire hold', 'x' => 140, 'y' => 325, 'to' => 6],
    ['t' => 'p', 'v' => 'Mark paid + approved', 'x' => 620, 'y' => 325, 'to' => 7],
    ['t' => 'end', 'v' => 'End (unpaid)', 'x' => 150, 'y' => 420, 'w' => 140],
    ['t' => 'p', 'v' => 'Notify FCM/SMTP/in-app', 'x' => 600, 'y' => 420, 'w' => 200, 'to' => 8],
    ['t' => 'end', 'v' => 'End', 'x' => 650, 'y' => 520, 'w' => 100],
];
flowChain($body, $nodes, 'p');
save('65d_Flowchart_Payment_PayMongo.drawio', mxfile('65d', 'Flowchart Payment', 1000, 620, $body));

$body = v('t', 'FLOWCHART — STAFF APPROVAL MODULE', $TITLE, 80, 15, 900, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'io', 'v' => 'Open pending queue', 'x' => 360, 'y' => 130, 'to' => 2],
    ['t' => 'p', 'v' => 'Review booking details', 'x' => 360, 'y' => 220, 'to' => 3],
    ['t' => 'd', 'v' => 'Decision?', 'x' => 400, 'y' => 310, 'to' => [[4, 'Deny'], [5, 'Approve'], [6, 'Postpone']]],
    ['t' => 'p', 'v' => 'status=denied + notify', 'x' => 80, 'y' => 450, 'w' => 180, 'to' => 7],
    ['t' => 'd', 'v' => 'Needs payment?', 'x' => 400, 'y' => 440, 'to' => [[8, 'Yes'], [9, 'No']]],
    ['t' => 'p', 'v' => 'status=postponed + notify', 'x' => 700, 'y' => 450, 'w' => 200, 'to' => 7],
    ['t' => 'end', 'v' => 'End', 'x' => 420, 'y' => 640, 'w' => 100],
    ['t' => 'p', 'v' => 'pending_payment (65d)', 'x' => 240, 'y' => 550, 'to' => 7],
    ['t' => 'p', 'v' => 'approved + notify (65i)', 'x' => 500, 'y' => 550, 'w' => 200, 'to' => 7],
];
flowChain($body, $nodes, 's');
save('65e_Flowchart_Staff_Approval.drawio', mxfile('65e', 'Flowchart Staff Approval', 1000, 740, $body));

$body = v('t', 'FLOWCHART — FACILITIES + CIMM MAINTENANCE INTEGRATION', $TITLE, 60, 15, 1000, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'd', 'v' => 'Source?', 'x' => 400, 'y' => 130, 'to' => [[2, 'Staff'], [3, 'Cron']]],
    ['t' => 'p', 'v' => 'Facility CRUD / Blackouts / QR', 'x' => 80, 'y' => 250, 'w' => 220, 'to' => 4],
    ['t' => 'p', 'v' => 'Pull CIMM API schedules', 'x' => 560, 'y' => 250, 'w' => 220, 'to' => 5],
    ['t' => 'd', 'v' => 'Geocode needed?', 'x' => 100, 'y' => 360, 'to' => [[6, 'Yes'], [7, 'No']]],
    ['t' => 'p', 'v' => 'Upsert blackouts + status', 'x' => 560, 'y' => 360, 'w' => 220, 'to' => 8],
    ['t' => 'p', 'v' => 'Call Nominatim / OSM', 'x' => 40, 'y' => 480, 'w' => 180, 'to' => 7],
    ['t' => 'p', 'v' => 'Save facility record', 'x' => 280, 'y' => 480, 'to' => 9],
    ['t' => 'd', 'v' => 'Announce?', 'x' => 580, 'y' => 480, 'to' => [[10, 'Yes'], [9, 'No']]],
    ['t' => 'end', 'v' => 'End', 'x' => 420, 'y' => 640, 'w' => 100],
    ['t' => 'p', 'v' => 'Gemini draft → publish', 'x' => 700, 'y' => 560, 'w' => 200, 'to' => 9],
];
flowChain($body, $nodes, 'c');
save('65f_Flowchart_CIMM_Facilities.drawio', mxfile('65f', 'Flowchart CIMM Facilities', 1100, 740, $body));

$body = v('t', 'FLOWCHART — ATTENDANCE / QR / OCCUPANCY', $TITLE, 80, 15, 900, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'd', 'v' => 'Method?', 'x' => 400, 'y' => 130, 'to' => [[2, 'QR'], [3, 'Manual']]],
    ['t' => 'io', 'v' => 'Scan facility QR (Web/APK)', 'x' => 100, 'y' => 250, 'w' => 220, 'to' => 4],
    ['t' => 'io', 'v' => 'Time-tracking form', 'x' => 560, 'y' => 250, 'to' => 4],
    ['t' => 'd', 'v' => 'Valid today booking?', 'x' => 400, 'y' => 360, 'to' => [[5, 'No'], [6, 'Yes']]],
    ['t' => 'io', 'v' => 'Reject / message', 'x' => 140, 'y' => 375, 'to' => 9],
    ['t' => 'p', 'v' => 'Record attendance', 'x' => 600, 'y' => 375, 'to' => 7],
    ['t' => 'p', 'v' => 'Update live occupancy', 'x' => 580, 'y' => 480, 'w' => 200, 'to' => 8],
    ['t' => 'd', 'v' => 'No-show cron later?', 'x' => 580, 'y' => 580, 'to' => [[10, 'Yes'], [9, 'No']]],
    ['t' => 'end', 'v' => 'End', 'x' => 420, 'y' => 700, 'w' => 100],
    ['t' => 'p', 'v' => 'Flag violation', 'x' => 800, 'y' => 595, 'w' => 140, 'to' => 9],
];
flowChain($body, $nodes, 'q');
save('65g_Flowchart_Attendance_QR.drawio', mxfile('65g', 'Flowchart Attendance', 1050, 800, $body));

$body = v('t', 'FLOWCHART — AI CHAT + SMART SCHEDULER', $TITLE, 80, 15, 900, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'd', 'v' => 'Feature?', 'x' => 400, 'y' => 130, 'to' => [[2, 'Chat'], [3, 'Scheduler']]],
    ['t' => 'io', 'v' => 'User message (Web widget / APK)', 'x' => 80, 'y' => 250, 'w' => 240, 'to' => 4],
    ['t' => 'io', 'v' => 'Enter prefs / date', 'x' => 560, 'y' => 250, 'to' => 5],
    ['t' => 'd', 'v' => 'Gemini key OK?', 'x' => 120, 'y' => 360, 'to' => [[6, 'Yes'], [7, 'No']]],
    ['t' => 'p', 'v' => 'Score slots (ML / rules)', 'x' => 560, 'y' => 360, 'w' => 200, 'to' => 8],
    ['t' => 'p', 'v' => 'Call Gemini API', 'x' => 40, 'y' => 480, 'to' => 9],
    ['t' => 'p', 'v' => 'Rules / ML fallback', 'x' => 260, 'y' => 480, 'to' => 9],
    ['t' => 'io', 'v' => 'Show suggestions → Book (65c)', 'x' => 540, 'y' => 480, 'w' => 240, 'to' => 10],
    ['t' => 'io', 'v' => 'Show reply (+ optional prefill)', 'x' => 120, 'y' => 580, 'w' => 240, 'to' => 10],
    ['t' => 'end', 'v' => 'End', 'x' => 420, 'y' => 680, 'w' => 100],
];
flowChain($body, $nodes, 'i');
save('65h_Flowchart_AI_Chat_Scheduler.drawio', mxfile('65h', 'Flowchart AI', 1000, 780, $body));

$body = v('t', 'FLOWCHART — NOTIFICATIONS, ANNOUNCEMENTS, REPORTS', $TITLE, 60, 15, 1000, 28);
$nodes = [
    ['t' => 'start', 'v' => 'Start', 'x' => 420, 'y' => 50, 'w' => 100, 'to' => 1],
    ['t' => 'd', 'v' => 'Module?', 'x' => 400, 'y' => 130, 'to' => [[2, 'Notify'], [3, 'Announce'], [4, 'Reports']]],
    ['t' => 'p', 'v' => 'createNotification + prefs', 'x' => 40, 'y' => 280, 'w' => 200, 'to' => 5],
    ['t' => 'p', 'v' => 'Staff compose announcement', 'x' => 360, 'y' => 280, 'w' => 220, 'to' => 8],
    ['t' => 'p', 'v' => 'Staff set filters', 'x' => 700, 'y' => 280, 'to' => 9],
    ['t' => 'd', 'v' => 'Channels?', 'x' => 60, 'y' => 400, 'to' => [[6, 'FCM'], [7, 'Email/SMS'], [10, 'In-app']]],
    ['t' => 'p', 'v' => 'FCM HTTP v1 push', 'x' => 40, 'y' => 540, 'to' => 11],
    ['t' => 'p', 'v' => 'SMTP / PhilSMS', 'x' => 220, 'y' => 540, 'to' => 11],
    ['t' => 'p', 'v' => 'Publish to public portal', 'x' => 380, 'y' => 400, 'w' => 200, 'to' => 11],
    ['t' => 'p', 'v' => 'Aggregate + CSV/PDF export', 'x' => 680, 'y' => 400, 'w' => 220, 'to' => 11],
    ['t' => 'p', 'v' => 'Inbox bell only', 'x' => 400, 'y' => 540, 'to' => 11],
    ['t' => 'end', 'v' => 'End', 'x' => 420, 'y' => 660, 'w' => 100],
];
flowChain($body, $nodes, 'n');
save('65i_Flowchart_Notifications_Reports.drawio', mxfile('65i', 'Flowchart Notif Reports', 1100, 760, $body));

echo "\nDone generating whole-system diagram set.\n";
