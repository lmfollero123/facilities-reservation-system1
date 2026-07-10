<?php
/**
 * Generate draw.io (.drawio) thesis diagrams for FRS capstone documentation.
 * Open in https://app.diagrams.net or draw.io desktop, then export PNG/SVG for Word.
 *
 * Usage: php scripts/generate_thesis_diagrams.php
 */

declare(strict_types=1);

$outDir = dirname(__DIR__) . '/docs/diagrams';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

final class DrawioBuilder
{
    private int $nextId = 2;
    /** @var array<int, array<string, mixed>> */
    private array $cells = [];

    public function __construct()
    {
        $this->cells[] = ['id' => '0', 'type' => 'root'];
        $this->cells[] = ['id' => '1', 'type' => 'layer', 'parent' => '0'];
    }

    public function box(
        string $label,
        float $x,
        float $y,
        float $w,
        float $h,
        string $style = 'process'
    ): string {
        $id = (string)$this->nextId++;
        $styles = [
            'external' => 'rounded=0;whiteSpace=wrap;html=1;fillColor=#dae8fc;strokeColor=#6c8ebf;fontStyle=1',
            'process' => 'rounded=1;whiteSpace=wrap;html=1;fillColor=#fff2cc;strokeColor=#d6b656;',
            'system' => 'rounded=1;whiteSpace=wrap;html=1;fillColor=#d5e8d4;strokeColor=#82b366;fontStyle=1;fontSize=14;',
            'datastore' => 'shape=partialRectangle;whiteSpace=wrap;html=1;fillColor=#f8cecc;strokeColor=#b85450;left=0;right=0;bottom=1;top=0;',
            'integration' => 'rounded=1;whiteSpace=wrap;html=1;fillColor=#e1d5e7;strokeColor=#9673a6;dashed=1;',
            'bpmn_task' => 'rounded=1;whiteSpace=wrap;html=1;fillColor=#ffffff;strokeColor=#000000;',
            'bpmn_start' => 'ellipse;whiteSpace=wrap;html=1;aspect=fixed;fillColor=#d5e8d4;strokeColor=#82b366;',
            'bpmn_end' => 'ellipse;whiteSpace=wrap;html=1;aspect=fixed;fillColor=#f8cecc;strokeColor=#b85450;strokeWidth=3;',
            'bpmn_gateway' => 'rhombus;whiteSpace=wrap;html=1;fillColor=#fff2cc;strokeColor=#d6b656;',
            'module' => 'rounded=1;whiteSpace=wrap;html=1;fillColor=#ffe6cc;strokeColor=#d79b00;fontStyle=1',
        ];
        $this->cells[] = [
            'id' => $id,
            'type' => 'vertex',
            'parent' => '1',
            'value' => htmlspecialchars($label, ENT_QUOTES | ENT_XML1),
            'style' => $styles[$style] ?? $styles['process'],
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
        ];
        return $id;
    }

    public function edge(string $from, string $to, string $label = '', string $style = ''): void
    {
        $id = (string)$this->nextId++;
        $edgeStyle = 'edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;'
            . 'strokeColor=#333333;fontSize=11;';
        if ($style !== '') {
            $edgeStyle .= $style;
        }
        $this->cells[] = [
            'id' => $id,
            'type' => 'edge',
            'parent' => '1',
            'source' => $from,
            'target' => $to,
            'value' => htmlspecialchars($label, ENT_QUOTES | ENT_XML1),
            'style' => $edgeStyle,
        ];
    }

    public function save(string $path, string $pageName): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<mxfile host="app.diagrams.net" modified="' . date('c') . '" agent="FRS Thesis Generator" version="22.1.0" type="device">' . "\n";
        $xml .= '  <diagram id="' . htmlspecialchars(basename($path, '.drawio')) . '" name="' . htmlspecialchars($pageName) . '">' . "\n";
        $xml .= '    <mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1600" pageHeight="1200" math="0" shadow="0">' . "\n";
        $xml .= "      <root>\n";
        foreach ($this->cells as $cell) {
            if ($cell['type'] === 'root') {
                $xml .= '        <mxCell id="0"/>' . "\n";
                continue;
            }
            if ($cell['type'] === 'layer') {
                $xml .= '        <mxCell id="1" parent="0"/>' . "\n";
                continue;
            }
            if ($cell['type'] === 'vertex') {
                $xml .= sprintf(
                    '        <mxCell id="%s" value="%s" style="%s" vertex="1" parent="%s"><mxGeometry x="%s" y="%s" width="%s" height="%s" as="geometry"/></mxCell>' . "\n",
                    $cell['id'],
                    $cell['value'],
                    $cell['style'],
                    $cell['parent'],
                    $cell['x'],
                    $cell['y'],
                    $cell['w'],
                    $cell['h']
                );
                continue;
            }
            if ($cell['type'] === 'edge') {
                $xml .= sprintf(
                    '        <mxCell id="%s" value="%s" style="%s" edge="1" parent="%s" source="%s" target="%s"><mxGeometry relative="1" as="geometry"/></mxCell>' . "\n",
                    $cell['id'],
                    $cell['value'],
                    $cell['style'],
                    $cell['parent'],
                    $cell['source'],
                    $cell['target']
                );
            }
        }
        $xml .= "      </root>\n";
        $xml .= "    </mxGraphModel>\n";
        $xml .= "  </diagram>\n";
        $xml .= "</mxfile>\n";
        file_put_contents($path, $xml);
    }
}

function saveDiagram(string $filename, string $pageName, callable $draw): void
{
    global $outDir;
    $builder = new DrawioBuilder();
    $draw($builder);
    $builder->save($outDir . '/' . $filename, $pageName);
    echo "Created: docs/diagrams/$filename\n";
}

// --- BPMN: Reservation & Approval ---
saveDiagram('01_BPMN_Reservation_Approval.drawio', 'BPMN - Reservation and Approval', function (DrawioBuilder $d) {
    $start = $d->box('Start', 80, 280, 50, 50, 'bpmn_start');
    $t1 = $d->box('Resident/Staff\nopens Book Facility', 180, 260, 160, 70, 'bpmn_task');
    $g1 = $d->box('Valid?', 400, 275, 70, 70, 'bpmn_gateway');
    $t2 = $d->box('Show validation\nerror', 380, 120, 150, 60, 'bpmn_task');
    $g2 = $d->box('Auto-\napprove?', 540, 275, 80, 80, 'bpmn_gateway');
    $t3 = $d->box('Status = Approved', 680, 200, 150, 60, 'bpmn_task');
    $t4 = $d->box('Status = Pending\n(or Pending Payment)', 680, 340, 170, 70, 'bpmn_task');
    $t5 = $d->box('Staff reviews\nReservations Manage', 900, 340, 170, 70, 'bpmn_task');
    $g3 = $d->box('Decision', 1120, 355, 70, 70, 'bpmn_gateway');
    $t6 = $d->box('Approved /\nDenied', 1260, 320, 140, 60, 'bpmn_task');
    $t7 = $d->box('Notify resident\n(in-app + email)', 1260, 420, 150, 60, 'bpmn_task');
    $end = $d->box('End', 1460, 335, 50, 50, 'bpmn_end');

    $d->edge($start, $t1);
    $d->edge($t1, $g1);
    $d->edge($g1, $t2, 'No');
    $d->edge($g1, $g2, 'Yes');
    $d->edge($g2, $t3, 'Yes');
    $d->edge($g2, $t4, 'No');
    $d->edge($t4, $t5);
    $d->edge($t5, $g3);
    $d->edge($g3, $t6, 'Approve');
    $d->edge($g3, $t7, 'Deny');
    $d->edge($t3, $end);
    $d->edge($t6, $end);
    $d->edge($t7, $end);
});

// --- BPMN: CIMM Maintenance ---
saveDiagram('02_BPMN_CIMM_Maintenance_Sync.drawio', 'BPMN - CIMM Maintenance Sync', function (DrawioBuilder $d) {
    $start = $d->box('Start', 60, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('CIMM sets facility\nunder maintenance', 150, 240, 170, 70, 'bpmn_task');
    $t2 = $d->box('CIMM API / webhook\nsends update', 380, 240, 160, 70, 'bpmn_task');
    $t3 = $d->box('FRS matches facility\n(Cassanova, etc.)', 600, 240, 170, 70, 'bpmn_task');
    $t4 = $d->box('Update facilities.status\n= maintenance', 820, 240, 170, 70, 'bpmn_task');
    $t5 = $d->box('Apply blackout dates\n+ block bookings', 1040, 240, 170, 70, 'bpmn_task');
    $t6 = $d->box('Postpone/cancel\naffected reservations', 1260, 240, 170, 70, 'bpmn_task');
    $end = $d->box('End', 1480, 255, 50, 50, 'bpmn_end');

    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $t3);
    $d->edge($t3, $t4);
    $d->edge($t4, $t5);
    $d->edge($t5, $t6);
    $d->edge($t6, $end);
});

// --- BPMN: PayMongo ---
saveDiagram('03_BPMN_PayMongo_Payment.drawio', 'BPMN - PayMongo Payment (Demo)', function (DrawioBuilder $d) {
    $start = $d->box('Start', 80, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('Reservation requires\npayment', 170, 240, 150, 70, 'bpmn_task');
    $t2 = $d->box('Redirect to\nPayMongo checkout', 370, 240, 150, 70, 'bpmn_task');
    $g1 = $d->box('Paid?', 570, 255, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Webhook confirms\npayment', 700, 180, 150, 60, 'bpmn_task');
    $t4 = $d->box('Update reservation\nstatus', 900, 180, 140, 60, 'bpmn_task');
    $t5 = $d->box('Payment failed /\nexpired', 700, 360, 150, 60, 'bpmn_task');
    $end = $d->box('End', 1100, 255, 50, 50, 'bpmn_end');

    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $t3, 'Yes');
    $d->edge($g1, $t5, 'No');
    $d->edge($t3, $t4);
    $d->edge($t4, $end);
    $d->edge($t5, $end);
});

// --- DFD Level 0 ---
saveDiagram('04_DFD_Level0_Context.drawio', 'DFD Level 0 - Context', function (DrawioBuilder $d) {
    $sys = $d->box('0.0 Facilities Reservation\nSystem (FRS)', 620, 340, 280, 120, 'system');
    $resident = $d->box('Resident', 80, 320, 140, 70, 'external');
    $staff = $d->box('Staff', 80, 480, 140, 70, 'external');
    $admin = $d->box('Admin', 80, 160, 140, 70, 'external');
    $cimm = $d->box('CIMM', 1180, 160, 140, 70, 'integration');
    $pay = $d->box('PayMongo', 1180, 320, 140, 70, 'integration');
    $gemini = $d->box('Google Gemini', 1180, 480, 140, 70, 'integration');
    $smtp = $d->box('Email / SMS', 1180, 640, 140, 70, 'integration');

    $d->edge($resident, $sys, 'Booking requests, profile');
    $d->edge($staff, $sys, 'Approvals, facilities');
    $d->edge($admin, $sys, 'Configuration, reports');
    $d->edge($sys, $resident, 'Status, notifications');
    $d->edge($sys, $staff, 'Queue, reports');
    $d->edge($cimm, $sys, 'Maintenance schedules');
    $d->edge($sys, $cimm, 'Facility share API');
    $d->edge($pay, $sys, 'Payment webhook');
    $d->edge($sys, $pay, 'Checkout redirect');
    $d->edge($sys, $gemini, 'Chat prompts');
    $d->edge($gemini, $sys, 'AI responses');
    $d->edge($sys, $smtp, 'Email/SMS');
});

// --- DFD Level 1 ---
saveDiagram('05_DFD_Level1_System.drawio', 'DFD Level 1 - System', function (DrawioBuilder $d) {
    $p1 = $d->box('1.0 Auth &\nUser Mgmt', 120, 120, 150, 70, 'process');
    $p2 = $d->box('2.0 Public\nPortal', 320, 120, 130, 70, 'process');
    $p3 = $d->box('3.0 Booking &\nReservations', 520, 120, 150, 70, 'process');
    $p4 = $d->box('4.0 Facility\nManagement', 720, 120, 140, 70, 'process');
    $p5 = $d->box('5.0 Approval &\nOperations', 920, 120, 150, 70, 'process');
    $p6 = $d->box('6.0 Payments', 520, 280, 130, 70, 'process');
    $p7 = $d->box('7.0 Maintenance\nIntegration', 720, 280, 150, 70, 'process');
    $p8 = $d->box('8.0 AI Services', 320, 280, 130, 70, 'process');
    $p9 = $d->box('9.0 Notifications\n& Comms', 120, 280, 150, 70, 'process');
    $p10 = $d->box('10.0 Reports &\nAudit', 920, 280, 140, 70, 'process');

    $d1 = $d->box('D1 Users', 120, 420, 120, 50, 'datastore');
    $d2 = $d->box('D2 Facilities', 320, 420, 120, 50, 'datastore');
    $d3 = $d->box('D3 Reservations', 520, 420, 130, 50, 'datastore');
    $d4 = $d->box('D4 Payments', 720, 420, 120, 50, 'datastore');
    $d5 = $d->box('D5 Audit/Notif', 920, 420, 130, 50, 'datastore');

    $ext = $d->box('Users & External APIs', 520, 520, 200, 50, 'external');

    foreach ([$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8, $p9, $p10] as $p) {
        $d->edge($ext, $p, 'requests');
    }
    $d->edge($p1, $d1, 'R/W');
    $d->edge($p3, $d3, 'R/W');
    $d->edge($p4, $d2, 'R/W');
    $d->edge($p6, $d4, 'R/W');
    $d->edge($p5, $d3, 'R');
    $d->edge($p10, $d5, 'R/W');
    $d->edge($p9, $d5, 'W');
});

// --- DFD Level 2 modules ---
saveDiagram('06_DFD_Level2_Auth.drawio', 'DFD Level 2 - Auth Module', function (DrawioBuilder $d) {
    $p11 = $d->box('1.1 Register', 100, 100, 120, 60, 'process');
    $p12 = $d->box('1.2 Login/OTP', 260, 100, 120, 60, 'process');
    $p13 = $d->box('1.3 Profile', 420, 100, 120, 60, 'process');
    $p14 = $d->box('1.4 User Admin', 580, 100, 120, 60, 'process');
    $d1 = $d->box('D1 Users', 340, 240, 120, 50, 'datastore');
    $d->edge($p11, $d1, 'create');
    $d->edge($p12, $d1, 'verify');
    $d->edge($p13, $d1, 'update');
    $d->edge($p14, $d1, 'manage');
});

saveDiagram('07_DFD_Level2_Booking.drawio', 'DFD Level 2 - Booking Module', function (DrawioBuilder $d) {
    $p31 = $d->box('3.1 Validate\nrules', 80, 100, 120, 60, 'process');
    $p32 = $d->box('3.2 Conflict\ncheck', 240, 100, 120, 60, 'process');
    $p33 = $d->box('3.3 Auto-\napproval', 400, 100, 120, 60, 'process');
    $p34 = $d->box('3.4 Reschedule/\nCancel', 560, 100, 120, 60, 'process');
    $d2 = $d->box('D2 Facilities', 180, 240, 120, 50, 'datastore');
    $d3 = $d->box('D3 Reservations', 400, 240, 130, 50, 'datastore');
    $d->edge($p31, $d2, 'read status');
    $d->edge($p32, $d3, 'read');
    $d->edge($p33, $d3, 'write');
    $d->edge($p34, $d3, 'update');
});

saveDiagram('08_DFD_Level2_Facility_Maintenance.drawio', 'DFD Level 2 - Facility & Maintenance', function (DrawioBuilder $d) {
    $p41 = $d->box('4.1 Facility CRUD', 100, 90, 130, 60, 'process');
    $p42 = $d->box('4.2 Blackouts', 280, 90, 120, 60, 'process');
    $p71 = $d->box('7.1 Fetch CIMM', 100, 220, 120, 60, 'process');
    $p72 = $d->box('7.2 Match facility', 280, 220, 120, 60, 'process');
    $p73 = $d->box('7.3 Sync status', 460, 220, 120, 60, 'process');
    $d2 = $d->box('D2 Facilities', 280, 360, 120, 50, 'datastore');
    $d6 = $d->box('D6 Blackouts', 460, 360, 120, 50, 'datastore');
    $cimm = $d->box('CIMM API', 100, 360, 100, 50, 'integration');
    $d->edge($cimm, $p71, 'schedules');
    $d->edge($p41, $d2, 'R/W');
    $d->edge($p42, $d6, 'R/W');
    $d->edge($p73, $d2, 'update status');
    $d->edge($p72, $d2, 'match');
});

saveDiagram('09_DFD_Level2_Payments.drawio', 'DFD Level 2 - Payments Module', function (DrawioBuilder $d) {
    $p61 = $d->box('6.1 Create checkout', 120, 100, 140, 60, 'process');
    $p62 = $d->box('6.2 Webhook handler', 320, 100, 140, 60, 'process');
    $p63 = $d->box('6.3 Update reservation', 520, 100, 140, 60, 'process');
    $d3 = $d->box('D3 Reservations', 200, 260, 130, 50, 'datastore');
    $d4 = $d->box('D4 Payments', 400, 260, 120, 50, 'datastore');
    $pay = $d->box('PayMongo', 320, 360, 120, 50, 'integration');
    $d->edge($p61, $pay, 'checkout');
    $d->edge($pay, $p62, 'webhook');
    $d->edge($p62, $d4, 'write');
    $d->edge($p63, $d3, 'update status');
});

saveDiagram('17_DFD_Level2_Notifications.drawio', 'DFD Level 2 - Notifications', function (DrawioBuilder $d) {
    $p91 = $d->box('9.1 In-app alert', 100, 100, 130, 60, 'process');
    $p92 = $d->box('9.2 Email queue', 280, 100, 130, 60, 'process');
    $p93 = $d->box('9.3 SMS (preview)', 460, 100, 130, 60, 'process');
    $d5 = $d->box('D5 Notifications', 280, 240, 140, 50, 'datastore');
    $smtp = $d->box('SMTP', 280, 360, 100, 50, 'integration');
    $d->edge($p91, $d5, 'write');
    $d->edge($p92, $d5, 'queue');
    $d->edge($p92, $smtp, 'send');
});

saveDiagram('18_DFD_Level2_AI.drawio', 'DFD Level 2 - AI Services', function (DrawioBuilder $d) {
    $p81 = $d->box('8.1 Chat UI', 100, 100, 120, 60, 'process');
    $p82 = $d->box('8.2 Context build', 260, 100, 120, 60, 'process');
    $p83 = $d->box('8.3 Gemini API', 420, 100, 120, 60, 'process');
    $p84 = $d->box('8.4 Fallback reply', 580, 100, 120, 60, 'process');
    $gemini = $d->box('Google Gemini', 420, 240, 120, 50, 'integration');
    $d->edge($p81, $p82, 'message');
    $d->edge($p82, $p83, 'prompt');
    $d->edge($p83, $gemini, 'API call');
    $d->edge($p83, $p84, 'on error');
});

saveDiagram('19_DFD_Level2_Admin_Reports.drawio', 'DFD Level 2 - Admin & Reports', function (DrawioBuilder $d) {
    $p101 = $d->box('10.1 Dashboard', 100, 100, 120, 60, 'process');
    $p102 = $d->box('10.2 Reports', 260, 100, 120, 60, 'process');
    $p103 = $d->box('10.3 Audit log', 420, 100, 120, 60, 'process');
    $d3 = $d->box('D3 Reservations', 180, 240, 130, 50, 'datastore');
    $d5 = $d->box('D5 Audit', 400, 240, 120, 50, 'datastore');
    $d->edge($p101, $d3, 'read');
    $d->edge($p102, $d3, 'aggregate');
    $d->edge($p103, $d5, 'R/W');
});

saveDiagram('20_DFD_Level2_Checkin.drawio', 'DFD Level 2 - Attendance Check-in', function (DrawioBuilder $d) {
    $p71 = $d->box('7.1 QR scan', 120, 100, 120, 60, 'process');
    $p72 = $d->box('7.2 Verify reservation', 300, 100, 140, 60, 'process');
    $p73 = $d->box('7.3 Record attendance', 500, 100, 140, 60, 'process');
    $d3 = $d->box('D3 Reservations', 300, 240, 130, 50, 'datastore');
    $d7 = $d->box('D7 Attendance', 500, 240, 120, 50, 'datastore');
    $d->edge($p71, $p72, 'code');
    $d->edge($p72, $d3, 'read');
    $d->edge($p73, $d7, 'write');
});

// --- BPA Level 0 ---
saveDiagram('10_BPA_Level0_Integration.drawio', 'BPA Level 0 - With Integrations', function (DrawioBuilder $d) {
    $core = $d->box('Barangay Culiat\nFacility Reservation\n(E2E)', 600, 300, 240, 100, 'system');
    $r = $d->box('Residents', 80, 280, 120, 60, 'external');
    $s = $d->box('Barangay Staff', 80, 420, 120, 60, 'external');
    $a = $d->box('Admin', 80, 140, 120, 60, 'external');
    $cimm = $d->box('CIMM', 1100, 140, 120, 60, 'integration');
    $pay = $d->box('PayMongo', 1100, 300, 120, 60, 'integration');
    $ai = $d->box('Gemini AI', 1100, 460, 120, 60, 'integration');
    $d->edge($r, $core, 'Book & track');
    $d->edge($s, $core, 'Approve & operate');
    $d->edge($a, $core, 'Govern');
    $d->edge($cimm, $core, 'Maintenance status');
    $d->edge($core, $cimm, 'Facility catalog');
    $d->edge($pay, $core, 'Payment events');
    $d->edge($core, $pay, 'Checkout');
    $d->edge($ai, $core, 'AI responses');
    $d->edge($core, $ai, 'Chat context');
});

// --- BPA Level 1 ---
saveDiagram('11_BPA_Level1_System.drawio', 'BPA Level 1 - Entire System', function (DrawioBuilder $d) {
    $bp1 = $d->box('BP-01\nOnboarding', 100, 80, 130, 70, 'module');
    $bp2 = $d->box('BP-02\nFacility Catalog', 280, 80, 130, 70, 'module');
    $bp3 = $d->box('BP-03\nReservation', 460, 80, 130, 70, 'module');
    $bp4 = $d->box('BP-04\nApproval', 640, 80, 130, 70, 'module');
    $bp5 = $d->box('BP-05\nPayment', 820, 80, 130, 70, 'module');
    $bp6 = $d->box('BP-06\nMaintenance Sync', 100, 240, 150, 70, 'module');
    $bp7 = $d->box('BP-07\nCheck-in', 300, 240, 130, 70, 'module');
    $bp8 = $d->box('BP-08\nReporting', 500, 240, 130, 70, 'module');
    $bp9 = $d->box('BP-09\nNotifications', 700, 240, 140, 70, 'module');
    $bp10 = $d->box('BP-10\nAI Assist', 900, 240, 120, 70, 'module');

    $d->edge($bp1, $bp3, '');
    $d->edge($bp2, $bp3, '');
    $d->edge($bp3, $bp4, '');
    $d->edge($bp3, $bp5, '');
    $d->edge($bp6, $bp2, '');
    $d->edge($bp4, $bp7, '');
    $d->edge($bp4, $bp9, '');
    $d->edge($bp8, $bp9, '');
});

// --- BPA Level 2 per module ---
$bpaL2 = [
    '12_BPA_Level2_Onboarding.drawio' => ['Onboarding', [
        ['Register account', 80, 120],
        ['Verify email', 260, 120],
        ['Staff verify ID', 440, 120],
        ['Activate account', 620, 120],
    ]],
    '13_BPA_Level2_Reservation.drawio' => ['Reservation', [
        ['Select facility/date', 80, 120],
        ['Validate limits', 260, 120],
        ['Check conflicts', 440, 120],
        ['Submit request', 620, 120],
        ['Auto-approve or pending', 800, 120],
    ]],
    '14_BPA_Level2_Maintenance.drawio' => ['Maintenance Sync', [
        ['CIMM updates status', 80, 120],
        ['FRS receives webhook/API', 260, 120],
        ['Match facility', 440, 120],
        ['Set maintenance + blackout', 620, 120],
        ['Block new bookings', 800, 120],
    ]],
    '15_BPA_Level2_Payments.drawio' => ['Payments', [
        ['Payment required', 80, 120],
        ['PayMongo checkout', 260, 120],
        ['Webhook confirm', 440, 120],
        ['Update reservation', 620, 120],
    ]],
    '16_BPA_Level2_Approval.drawio' => ['Approval', [
        ['Staff opens queue', 80, 120],
        ['Review details', 260, 120],
        ['Approve or deny', 440, 120],
        ['Record history', 620, 120],
        ['Notify resident', 800, 120],
    ]],
    '21_BPA_Level2_Facility.drawio' => ['Facility Catalog', [
        ['Admin adds facility', 80, 120],
        ['Set capacity/hours', 260, 120],
        ['Publish to portal', 440, 120],
        ['Sync to CIMM API', 620, 120],
    ]],
    '22_BPA_Level2_Checkin.drawio' => ['Check-in', [
        ['Resident arrives', 80, 120],
        ['Staff scans QR', 260, 120],
        ['Validate booking', 440, 120],
        ['Log attendance', 620, 120],
    ]],
    '23_BPA_Level2_Reports.drawio' => ['Reporting', [
        ['Select report type', 80, 120],
        ['Query reservations', 260, 120],
        ['Generate summary', 440, 120],
        ['Export / display', 620, 120],
    ]],
    '24_BPA_Level2_AI_Assist.drawio' => ['AI Assist', [
        ['User opens chatbot', 80, 120],
        ['Send question', 260, 120],
        ['Gemini or fallback', 440, 120],
        ['Display response', 620, 120],
    ]],
];

foreach ($bpaL2 as $file => [$title, $steps]) {
    saveDiagram($file, "BPA Level 2 - $title", function (DrawioBuilder $d) use ($steps) {
        $prev = null;
        $start = $d->box('Start', 40, 130, 40, 40, 'bpmn_start');
        $prev = $start;
        $x = 100;
        foreach ($steps as $step) {
            $id = $d->box($step[0], $x, 110, 140, 60, 'bpmn_task');
            $d->edge($prev, $id);
            $prev = $id;
            $x += 180;
        }
        $end = $d->box('End', $x + 20, 125, 40, 40, 'bpmn_end');
        $d->edge($prev, $end);
    });
}

// README
$readme = <<<'MD'
# Thesis Diagrams (draw.io)

Generated from the **Barangay Culiat Facilities Reservation System** codebase.

## Files

| File | Diagram type | Use in thesis |
|------|----------------|---------------|
| `01_BPMN_Reservation_Approval.drawio` | BPMN | §5.2 Business Process Diagrams |
| `02_BPMN_CIMM_Maintenance_Sync.drawio` | BPMN | §5.2 / §7.4 CIMM sync |
| `03_BPMN_PayMongo_Payment.drawio` | BPMN | §5.2 / §6.3 Payments |
| `04_DFD_Level0_Context.drawio` | DFD Level 0 | §7.2 Data Flow (Context) |
| `05_DFD_Level1_System.drawio` | DFD Level 1 | §7.2 Data Flow (System) |
| `06_DFD_Level2_Auth.drawio` | DFD Level 2 | Auth module |
| `07_DFD_Level2_Booking.drawio` | DFD Level 2 | Booking module |
| `08_DFD_Level2_Facility_Maintenance.drawio` | DFD Level 2 | Facility + CIMM |
| `09_DFD_Level2_Payments.drawio` | DFD Level 2 | PayMongo module |
| `17-20_DFD_Level2_*.drawio` | DFD Level 2 | Notifications, AI, Admin, Check-in |
| `10_BPA_Level0_Integration.drawio` | BPA Level 0 | §5 Business Process (integration view) |
| `11_BPA_Level1_System.drawio` | BPA Level 1 | Entire system processes |
| `12-16, 21-24_BPA_Level2_*.drawio` | BPA Level 2 | Per-module subprocesses |
| `export/*.png` | PNG images | Ready to insert in Word |

## How to open and export images

### Option A — diagrams.net (recommended)
1. Go to [https://app.diagrams.net](https://app.diagrams.net)
2. **File → Open from → Device** → select any `.drawio` file in this folder
3. Adjust layout if needed (colors match thesis: blue=external, yellow=process, green=system, purple=integration)
4. **File → Export as → PNG** (300 DPI for Word) or **SVG**
5. Insert PNG into Word: **Insert → Pictures**

### Option B — draw.io Desktop
1. Install [draw.io Desktop](https://github.com/jgraph/drawio-desktop/releases)
2. Open `.drawio` files → Export PNG

### Option C — Visio
1. Open draw.io file in diagrams.net
2. **File → Export as → VSDX** (Visio format)
3. Open in Microsoft Visio and refine if required

## Suggested figure captions (Word)

- Figure X. BPMN — Facility Reservation and Approval Process
- Figure X. BPMN — CIMM Maintenance Synchronization Process
- Figure X. BPMN — PayMongo Payment Process (Capstone Demo)
- Figure X. DFD Level 0 — Context Diagram with External Integrations
- Figure X. DFD Level 1 — System Decomposition
- Figure X. DFD Level 2 — Booking and Reservation Module
- Figure X. BPA Level 0 — Business Process Architecture with Integrations
- Figure X. BPA Level 1 — System Business Processes

## Regenerate

```bash
php scripts/generate_thesis_diagrams.php
```

## Batch export PNG (optional)

```powershell
cd docs/diagrams
mkdir export -Force
Get-ChildItem *.drawio | ForEach-Object {
  npx --yes draw.io-export $_.FullName -o "export\$($_.BaseName).png"
}
```

MD;

file_put_contents($outDir . '/README.md', $readme);
echo "Created: docs/diagrams/README.md\n";
echo "Done. Open files in https://app.diagrams.net and export PNG for Word.\n";
