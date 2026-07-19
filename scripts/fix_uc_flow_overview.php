<?php
declare(strict_types=1);
$outDir = dirname(__DIR__) . '/docs/diagrams';

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1); }
function mxfile(string $id, string $name, int $w, int $h, string $body): string {
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<mxfile host="app.diagrams.net" modified="' . date('c') . '" agent="FRS Fix" version="22.1.0" type="device">' . "\n"
        . '  <diagram id="' . esc($id) . '" name="' . esc($name) . '">' . "\n"
        . '    <mxGraphModel dx="1400" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . $w . '" pageHeight="' . $h . '" math="0" shadow="0">' . "\n"
        . "      <root>\n        <mxCell id=\"0\"/>\n        <mxCell id=\"1\" parent=\"0\"/>\n"
        . $body . "      </root>\n    </mxGraphModel>\n  </diagram>\n</mxfile>\n";
}
function v(string $id, string $val, string $style, float $x, float $y, float $w, float $h): string {
    return '        <mxCell id="' . $id . '" value="' . esc($val) . '" style="' . $style . '" vertex="1" parent="1"><mxGeometry x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" as="geometry"/></mxCell>' . "\n";
}
function e(string $id, string $val, string $style, string $src, string $tgt): string {
    return '        <mxCell id="' . $id . '" value="' . esc($val) . '" style="' . $style . '" edge="1" parent="1" source="' . $src . '" target="' . $tgt . '"><mxGeometry relative="1" as="geometry"/></mxCell>' . "\n";
}

$TITLE = 'text;html=1;strokeColor=none;fillColor=none;align=center;fontStyle=1;fontSize=14;fontFamily=Helvetica;';
$NOTE = 'text;html=1;strokeColor=none;fillColor=none;align=left;fontSize=10;fontColor=#555555;fontFamily=Helvetica;';
$ACTOR = 'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;html=1;outlineConnect=0;fillColor=#FFFFFF;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;fontStyle=1;';
$UC = 'ellipse;whiteSpace=wrap;html=1;fillColor=#FFFFFF;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;fontStyle=1;';
$LINK = 'endArrow=none;html=1;strokeColor=#000000;edgeStyle=orthogonalEdgeStyle;rounded=0;exitX=1;exitY=0.5;entryX=0;entryY=0.5;';
$LINKR = 'endArrow=none;html=1;strokeColor=#000000;edgeStyle=orthogonalEdgeStyle;rounded=0;exitX=0;exitY=0.5;entryX=1;entryY=0.5;';
$GEN = 'endArrow=block;endFill=0;html=1;strokeColor=#000000;edgeStyle=orthogonalEdgeStyle;';
$FLOW_START = 'ellipse;whiteSpace=wrap;html=1;fillColor=#F4CCCC;strokeColor=#000000;fontStyle=1;fontSize=12;fontFamily=Helvetica;';
$FLOW_IO = 'shape=parallelogram;perimeter=parallelogramPerimeter;whiteSpace=wrap;html=1;fixedSize=1;fillColor=#CFE2F3;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;';
$FLOW_P = 'rounded=0;whiteSpace=wrap;html=1;fillColor=#FFE599;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;';
$FLOW_D = 'rhombus;whiteSpace=wrap;html=1;fillColor=#F9CB9C;strokeColor=#000000;fontSize=11;fontFamily=Helvetica;';
$FLOW_E = 'endArrow=classic;html=1;strokeColor=#000000;fontSize=10;fontFamily=Helvetica;edgeStyle=orthogonalEdgeStyle;';

$ucsRes = [
    'REGISTER / LOGIN',
    'BROWSE FACILITIES',
    'SUBMIT / MANAGE RESERVATION',
    'PAY VIA PAYMONGO',
    'RECEIVE NOTIFICATIONS',
    'USE AI CHAT / SCHEDULER',
    'QR CHECK-IN / OUT',
    'VIEW CALENDAR',
];
$ucsStaff = [
    'APPROVE / DENY BOOKING',
    'MANAGE FACILITIES / BLACKOUTS',
    'SYNC CIMM MAINTENANCE',
    'MANAGE USERS / AUDIT / DOCS',
    'PUBLISH ANNOUNCEMENTS',
    'VIEW REPORTS',
    'GEOCODE ADDRESSES',
];

$body = v('t', 'USE CASE — INTEGRATIONS WITH OTHER SYSTEMS (WEB + MOBILE)', $TITLE, 150, 15, 1200, 28);
$body .= v('bound', '', 'rounded=0;fillColor=none;strokeColor=#000000;dashed=1;dashPattern=8 8;strokeWidth=1.5;', 320, 60, 400, 1200);
$body .= v('sysn', 'BARANGAY CULIAT PFRS / CPRF', 'text;html=1;align=center;fontStyle=1;fontSize=11;fontFamily=Helvetica;', 320, 1270, 400, 22);

// Resident UCs top, staff UCs bottom — spacing so lines stay short
foreach ($ucsRes as $i => $u) {
    $body .= v('ucr' . $i, $u, $UC, 370, 80 + $i * 70, 300, 45);
}
foreach ($ucsStaff as $i => $u) {
    $body .= v('ucs' . $i, $u, $UC, 370, 660 + $i * 70, 300, 45);
}

$body .= v('ar', 'RESIDENT&#xa;(WEB + FLUTTER)', $ACTOR, 70, 280, 40, 70);
$body .= v('as', 'STAFF / ADMIN', $ACTOR, 70, 900, 40, 70);

// Generalization: Staff inherits Resident use cases (UML-correct, no spaghetti)
$body .= e('gen', 'inherits', $GEN, 'as', 'ar');

foreach ($ucsRes as $i => $_) {
    $body .= e('lr' . $i, '', $LINK, 'ar', 'ucr' . $i);
}
foreach ($ucsStaff as $i => $_) {
    $body .= e('ls' . $i, '', $LINK, 'as', 'ucs' . $i);
}

// Externals aligned to matching UC Y on the right
$body .= v('xp', 'PAYMONGO', $ACTOR, 900, 80 + 3 * 70, 40, 70);
$body .= v('xf', 'FCM / SMTP', $ACTOR, 900, 80 + 4 * 70, 40, 70);
$body .= v('xg', 'GEMINI', $ACTOR, 900, 80 + 5 * 70, 40, 70);
$body .= v('xc', 'CIMM', $ACTOR, 900, 660 + 2 * 70, 40, 70);
$body .= v('xn', 'NOMINATIM', $ACTOR, 900, 660 + 6 * 70, 40, 70);

$body .= e('rp', '', $LINKR, 'xp', 'ucr3');
$body .= e('rf', '', $LINKR, 'xf', 'ucr4');
$body .= e('rg', '', $LINKR, 'xg', 'ucr5');
$body .= e('rc', '', $LINKR, 'xc', 'ucs2');
$body .= e('rn', '', $LINKR, 'xn', 'ucs6');

$body .= v('note', 'Hollow arrow = generalization: Staff/Admin inherit all Resident use cases (same on Web and Flutter). Separate lines only for staff-only use cases and external systems.', $NOTE, 40, 1310, 1200, 40);
file_put_contents($outDir . '/63_UseCase_Integrations_Other_Systems.drawio', mxfile('63', 'Use Case Integrations', 1200, 1420, $body));
echo "Fixed 63 use case (generalization)\n";

// Overview flowchart — single row
$body = v('t', 'FLOWCHART OVERVIEW — WHOLE SYSTEM (ALL MODULES)', $TITLE, 80, 15, 1400, 28);
$body .= v('s0', 'Start', $FLOW_START, 700, 40, 100, 45);
$body .= v('s1', 'Open Web or Flutter Companion', $FLOW_IO, 620, 110, 260, 45);
$body .= v('s2', 'Authenticated?', $FLOW_D, 670, 190, 150, 80);
$body .= v('s3', 'Auth detail (65b)', $FLOW_P, 360, 205, 160, 45);
$body .= v('s4', 'Select module', $FLOW_D, 670, 310, 150, 80);

$mods = [
    ['65c Booking', 40],
    ['65d Payment', 230],
    ['65e Approval', 420],
    ['65f CIMM/Facilities', 610],
    ['65g QR/Attendance', 830],
    ['65h AI/Scheduler', 1040],
    ['65i Notif/Reports', 1240],
];
foreach ($mods as $i => $m) {
    $body .= v('m' . $i, $m[0], $FLOW_P, $m[1], 460, 170, 50);
    $body .= e('e4m' . $i, '', $FLOW_E . 'exitX=0.5;exitY=1;entryX=0.5;entryY=0;', 's4', 'm' . $i);
    $body .= e('em' . $i, '', $FLOW_E . 'exitX=0.5;exitY=1;entryX=' . round(0.08 + $i * 0.14, 2) . ';entryY=0;', 'm' . $i, 'end');
}
$body .= v('end', 'Return to home / End', $FLOW_START, 680, 580, 180, 50);
$body .= e('e01', '', $FLOW_E, 's0', 's1');
$body .= e('e12', '', $FLOW_E, 's1', 's2');
$body .= e('e23', 'No', $FLOW_E, 's2', 's3');
$body .= e('e32', '', $FLOW_E, 's3', 's2');
$body .= e('e24', 'Yes', $FLOW_E, 's2', 's4');
$body .= v('leg', 'Overview hub — modules in one row (no lines through boxes). Open 65b–65i for detailed Yes/No logic including mobile and integrations.', $NOTE, 40, 660, 1200, 40);
$xml = mxfile('65a', 'Flowchart Overview', 1600, 740, $body);
file_put_contents($outDir . '/65a_Flowchart_Overview_All_Modules.drawio', $xml);
file_put_contents($outDir . '/65_Flowchart_Whole_System_Mobile_Integrations.drawio', $xml);
echo "Fixed 65 overview\n";
