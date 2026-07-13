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
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('Cron or staff\nopens Maintenance', 120, 240, 150, 70, 'bpmn_task');
    $t2 = $d->box('Fetch CIMM\nschedules API', 310, 240, 140, 70, 'bpmn_task');
    $g1 = $d->box('OK?', 490, 255, 60, 60, 'bpmn_gateway');
    $t3 = $d->box('Match CPRF\nfacilities', 590, 240, 140, 70, 'bpmn_task');
    $t4 = $d->box('Update status\n+ blackout dates', 770, 240, 150, 70, 'bpmn_task');
    $g2 = $d->box('New\nschedule?', 960, 255, 60, 60, 'bpmn_gateway');
    $t5 = $d->box('Gemini auto-\nannouncement', 1060, 180, 140, 60, 'bpmn_task');
    $t6 = $d->box('Postpone affected\nreservations', 1060, 320, 150, 70, 'bpmn_task');
    $end = $d->box('End', 1260, 255, 50, 50, 'bpmn_end');
    $terr = $d->box('Log API error', 490, 380, 120, 50, 'bpmn_task');

    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $t3, 'Yes');
    $d->edge($g1, $terr, 'No');
    $d->edge($t3, $t4);
    $d->edge($t4, $g2);
    $d->edge($g2, $t5, 'Yes');
    $d->edge($g2, $t6, 'Always');
    $d->edge($t5, $end);
    $d->edge($t6, $end);
    $d->edge($terr, $end);
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
    $sys = $d->box('0.0 CPRF\nFacilities Reservation', 580, 360, 300, 120, 'system');
    $resident = $d->box('Resident', 60, 320, 130, 60, 'external');
    $staff = $d->box('Staff', 60, 460, 130, 60, 'external');
    $admin = $d->box('Admin', 60, 180, 130, 60, 'external');
    $visitor = $d->box('Visitor', 60, 40, 130, 60, 'external');
    $cimm = $d->box('CIMM\nMaintenance', 1180, 80, 150, 60, 'integration');
    $infra = $d->box('QC Infrastructure\n(Brgy Culiat scope)', 1180, 200, 150, 70, 'integration');
    $uman = $d->box('UMAN\nUtilities', 1180, 320, 130, 60, 'integration');
    $pay = $d->box('PayMongo\n(optional)', 1180, 440, 130, 60, 'integration');
    $gemini = $d->box('Google Gemini', 1180, 560, 130, 60, 'integration');
    $smtp = $d->box('Email / SMS', 1180, 680, 130, 60, 'integration');

    $d->edge($resident, $sys, 'Bookings, profile');
    $d->edge($staff, $sys, 'Approvals, facilities');
    $d->edge($admin, $sys, 'Config, reports');
    $d->edge($visitor, $sys, 'Browse, contact');
    $d->edge($sys, $resident, 'Status, notifications');
    $d->edge($sys, $staff, 'Queues, reports');
    $d->edge($sys, $visitor, 'Public pages');
    $d->edge($cimm, $sys, 'Maintenance schedules');
    $d->edge($sys, $cimm, 'Maintenance requests');
    $d->edge($infra, $sys, 'Construction reports');
    $d->edge($uman, $sys, 'Utility assets');
    $d->edge($sys, $uman, 'Asset requests');
    $d->edge($pay, $sys, 'Payment webhook');
    $d->edge($sys, $pay, 'Checkout');
    $d->edge($sys, $gemini, 'Chat / announcements');
    $d->edge($gemini, $sys, 'AI text');
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
    $p91 = $d->box('9.1 In-app', 80, 100, 120, 60, 'process');
    $p92 = $d->box('9.2 Email', 240, 100, 120, 60, 'process');
    $p93 = $d->box('9.3 SMS', 400, 100, 120, 60, 'process');
    $p94 = $d->box('9.4 Public\nannouncements', 560, 100, 130, 60, 'process');
    $d5 = $d->box('D5 Notifications', 300, 240, 140, 50, 'datastore');
    $smtp = $d->box('SMTP', 240, 360, 100, 50, 'integration');
    $sms = $d->box('SMS Gateway', 400, 360, 110, 50, 'integration');
    $d->edge($p91, $d5, 'write');
    $d->edge($p92, $smtp, 'send');
    $d->edge($p93, $sms, 'send');
    $d->edge($p94, $d5, 'public row');
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
    $core = $d->box('Barangay Culiat CPRF\n(Facility Reservation E2E)', 560, 320, 260, 100, 'system');
    $r = $d->box('Residents', 60, 300, 120, 60, 'external');
    $s = $d->box('Barangay Staff', 60, 440, 120, 60, 'external');
    $a = $d->box('Admin', 60, 160, 120, 60, 'external');
    $v = $d->box('Visitors', 60, 40, 120, 50, 'external');
    $cimm = $d->box('CIMM', 1100, 80, 130, 60, 'integration');
    $infra = $d->box('QC Infrastructure\n(Brgy Culiat)', 1100, 200, 150, 70, 'integration');
    $uman = $d->box('UMAN', 1100, 320, 120, 60, 'integration');
    $pay = $d->box('PayMongo', 1100, 440, 120, 60, 'integration');
    $ai = $d->box('Gemini AI', 1100, 560, 120, 60, 'integration');
    $d->edge($r, $core, 'Book & track');
    $d->edge($s, $core, 'Approve & operate');
    $d->edge($a, $core, 'Govern');
    $d->edge($v, $core, 'Browse');
    $d->edge($cimm, $core, 'Maintenance');
    $d->edge($infra, $core, 'Construction reports');
    $d->edge($uman, $core, 'Assets');
    $d->edge($pay, $core, 'Payments');
    $d->edge($ai, $core, 'AI assist');
});

// --- BPA Level 1 ---
saveDiagram('11_BPA_Level1_System.drawio', 'BPA Level 1 - Entire System', function (DrawioBuilder $d) {
    $bp1 = $d->box('BP-01\nOnboarding', 80, 60, 120, 65, 'module');
    $bp2 = $d->box('BP-02\nPublic Portal', 230, 60, 120, 65, 'module');
    $bp3 = $d->box('BP-03\nReservation', 380, 60, 120, 65, 'module');
    $bp4 = $d->box('BP-04\nApproval', 530, 60, 120, 65, 'module');
    $bp5 = $d->box('BP-05\nPayment', 680, 60, 110, 65, 'module');
    $bp6 = $d->box('BP-06\nMaintenance', 830, 60, 120, 65, 'module');
    $bp7 = $d->box('BP-07\nAttendance', 980, 60, 120, 65, 'module');
    $bp8 = $d->box('BP-08\nOccupancy', 80, 200, 120, 65, 'module');
    $bp9 = $d->box('BP-09\nCommunications', 230, 200, 130, 65, 'module');
    $bp10 = $d->box('BP-10\nIntegrations', 400, 200, 120, 65, 'module');
    $bp11 = $d->box('BP-11\nReporting', 550, 200, 120, 65, 'module');
    $bp12 = $d->box('BP-12\nAI Assist', 700, 200, 110, 65, 'module');
    $bp13 = $d->box('BP-13\nCompliance', 850, 200, 120, 65, 'module');

    $d->edge($bp1, $bp3);
    $d->edge($bp2, $bp3);
    $d->edge($bp3, $bp4);
    $d->edge($bp3, $bp5);
    $d->edge($bp6, $bp3);
    $d->edge($bp10, $bp6);
    $d->edge($bp4, $bp7);
    $d->edge($bp7, $bp8);
    $d->edge($bp4, $bp9);
    $d->edge($bp9, $bp2);
    $d->edge($bp11, $bp13);
    $d->edge($bp12, $bp3);
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

// --- Additional BPMN processes ---
saveDiagram('25_BPMN_Registration.drawio', 'BPMN - User Registration', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('Submit registration\n+ Valid ID', 120, 240, 150, 70, 'bpmn_task');
    $t2 = $d->box('Accept Terms &\nPrivacy', 310, 240, 140, 70, 'bpmn_task');
    $t3 = $d->box('Verify email', 490, 240, 120, 70, 'bpmn_task');
    $g1 = $d->box('Staff\napproves?', 650, 255, 70, 70, 'bpmn_gateway');
    $t4 = $d->box('Activate account', 780, 200, 130, 60, 'bpmn_task');
    $t5 = $d->box('Deny / lock', 780, 340, 120, 60, 'bpmn_task');
    $end = $d->box('End', 960, 255, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $t3);
    $d->edge($t3, $g1);
    $d->edge($g1, $t4, 'Yes');
    $d->edge($g1, $t5, 'No');
    $d->edge($t4, $end);
    $d->edge($t5, $end);
});

saveDiagram('26_BPMN_Blackout_Gemini_Announcement.drawio', 'BPMN - CPRF Blackout + Auto Announcement', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('Staff adds\nblackout range', 120, 240, 140, 70, 'bpmn_task');
    $t2 = $d->box('Insert blackout\nrows', 300, 240, 130, 70, 'bpmn_task');
    $t3 = $d->box('Postpone conflicting\nreservations', 470, 240, 160, 70, 'bpmn_task');
    $g1 = $d->box('CPRF\nmanual?', 670, 255, 70, 70, 'bpmn_gateway');
    $t4 = $d->box('Gemini writes\nannouncement', 780, 180, 140, 60, 'bpmn_task');
    $t5 = $d->box('Publish public\nannouncement', 960, 180, 140, 60, 'bpmn_task');
    $end = $d->box('End', 1140, 255, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $t3);
    $d->edge($t3, $g1);
    $d->edge($g1, $t4, 'Yes');
    $d->edge($g1, $end, 'CIMM skip');
    $d->edge($t4, $t5);
    $d->edge($t5, $end);
});

saveDiagram('27_BPMN_Facility_QR_Checkin.drawio', 'BPMN - Facility QR Check-in', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('Scan facility QR', 120, 240, 130, 70, 'bpmn_task');
    $g1 = $d->box('Logged\nin?', 290, 255, 60, 60, 'bpmn_gateway');
    $t2 = $d->box('Redirect login', 270, 380, 120, 50, 'bpmn_task');
    $g2 = $d->box('Approved\nbooking today?', 400, 255, 70, 70, 'bpmn_gateway');
    $g3 = $d->box('Already\nchecked in?', 560, 255, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Record check-in', 700, 200, 130, 60, 'bpmn_task');
    $t4 = $d->box('Record check-out', 700, 320, 130, 60, 'bpmn_task');
    $end = $d->box('End', 880, 255, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $g1);
    $d->edge($g1, $t2, 'No');
    $d->edge($g1, $g2, 'Yes');
    $d->edge($g2, $end, 'No');
    $d->edge($g2, $g3, 'Yes');
    $d->edge($g3, $t3, 'No');
    $d->edge($g3, $t4, 'Yes');
    $d->edge($t3, $end);
    $d->edge($t4, $end);
    $d->edge($t2, $end);
});

saveDiagram('28_BPMN_AI_Chatbot.drawio', 'BPMN - AI Chatbot', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('User sends\nmessage', 120, 240, 130, 70, 'bpmn_task');
    $g1 = $d->box('Gemini\nkey set?', 290, 255, 60, 60, 'bpmn_gateway');
    $t2 = $d->box('Call Gemini API', 400, 180, 130, 60, 'bpmn_task');
    $t3 = $d->box('ML intent +\nrule fallback', 400, 340, 140, 60, 'bpmn_task');
    $g2 = $d->box('Prefill\nbooking?', 580, 255, 70, 70, 'bpmn_gateway');
    $t4 = $d->box('Populate booking\nform fields', 700, 200, 150, 60, 'bpmn_task');
    $t5 = $d->box('Show reply', 700, 320, 120, 60, 'bpmn_task');
    $end = $d->box('End', 880, 255, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $g1);
    $d->edge($g1, $t2, 'Yes');
    $d->edge($g1, $t3, 'No');
    $d->edge($t2, $g2);
    $d->edge($t3, $g2);
    $d->edge($g2, $t4, 'Yes');
    $d->edge($g2, $t5, 'No');
    $d->edge($t4, $end);
    $d->edge($t5, $end);
});

saveDiagram('29_BPMN_Reschedule.drawio', 'BPMN - Resident Reschedule', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('Request new\ndate/time', 120, 240, 130, 70, 'bpmn_task');
    $g1 = $d->box('Rules OK?\n(≥3 days)', 290, 255, 70, 70, 'bpmn_gateway');
    $t2 = $d->box('Show error', 270, 380, 110, 50, 'bpmn_task');
    $g2 = $d->box('Was\napproved?', 410, 255, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Set pending\nre-approval', 540, 200, 140, 60, 'bpmn_task');
    $t4 = $d->box('Update slot', 540, 320, 120, 60, 'bpmn_task');
    $t5 = $d->box('Notify user', 700, 260, 120, 60, 'bpmn_task');
    $end = $d->box('End', 860, 255, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $g1);
    $d->edge($g1, $t2, 'No');
    $d->edge($g1, $g2, 'Yes');
    $d->edge($g2, $t3, 'Yes');
    $d->edge($g2, $t4, 'No');
    $d->edge($t3, $t5);
    $d->edge($t4, $t5);
    $d->edge($t5, $end);
    $d->edge($t2, $end);
});

saveDiagram('30_BPMN_Infrastructure_Ingest.drawio', 'BPMN - Infrastructure Report (Brgy Culiat)', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 260, 50, 50, 'bpmn_start');
    $t1 = $d->box('QC Infrastructure\nplanned project', 120, 240, 160, 70, 'bpmn_task');
    $t2 = $d->box('Filter to\nBarangay Culiat', 320, 240, 150, 70, 'bpmn_task');
    $t3 = $d->box('CPRF receives\nconstruction report', 510, 240, 160, 70, 'bpmn_task');
    $t4 = $d->box('Display on\nInfrastructure dashboard', 700, 240, 170, 70, 'bpmn_task');
    $end = $d->box('End', 920, 255, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $t3);
    $d->edge($t3, $t4);
    $d->edge($t4, $end);
});

// --- WFD (Workflow) diagrams ---
saveDiagram('31_WFD_Registration_to_Booking.drawio', 'WFD - Registration to First Booking', function (DrawioBuilder $d) {
    $steps = [
        ['Register online', 100, 120],
        ['Verify email', 280, 120],
        ['Staff approves', 460, 120],
        ['Verify ID', 640, 120],
        ['Login OTP/TOTP', 820, 120],
        ['Book facility', 1000, 120],
    ];
    $prev = $d->box('Start', 40, 130, 40, 40, 'bpmn_start');
    $x = 100;
    foreach ($steps as $s) {
        $id = $d->box($s[0], $x, 110, 140, 60, 'bpmn_task');
        $d->edge($prev, $id);
        $prev = $id;
        $x += 180;
    }
    $end = $d->box('End', $x + 20, 125, 40, 40, 'bpmn_end');
    $d->edge($prev, $end);
});

saveDiagram('32_WFD_Staff_Approval_Tabs.drawio', 'WFD - Staff Approval Tabs', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 40, 40, 'bpmn_start');
    $t1 = $d->box('Open Reservations\nManage', 100, 180, 150, 70, 'bpmn_task');
    $g1 = $d->box('Tab?', 290, 195, 60, 60, 'bpmn_gateway');
    $t2 = $d->box('Pending queue\nfilter/search', 400, 120, 150, 60, 'bpmn_task');
    $t3 = $d->box('Approved list\nmonitor', 400, 280, 150, 60, 'bpmn_task');
    $t4 = $d->box('Review & act', 600, 200, 130, 60, 'bpmn_task');
    $end = $d->box('End', 780, 205, 40, 40, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $g1);
    $d->edge($g1, $t2, 'Pending');
    $d->edge($g1, $t3, 'Approved');
    $d->edge($t2, $t4);
    $d->edge($t3, $t4);
    $d->edge($t4, $end);
});

saveDiagram('33_WFD_Attendance_NoShow.drawio', 'WFD - Attendance & No-Show', function (DrawioBuilder $d) {
    $steps = [
        ['Send reminders', 100, 120],
        ['Event day grace', 280, 120],
        ['Checked in?', 460, 120],
        ['Record violation', 640, 120],
    ];
    $prev = $d->box('Start', 40, 130, 40, 40, 'bpmn_start');
    $x = 100;
    foreach ($steps as $s) {
        $id = $d->box($s[0], $x, 110, 140, 60, 'bpmn_task');
        $d->edge($prev, $id);
        $prev = $id;
        $x += 180;
    }
    $end = $d->box('End', $x + 20, 125, 40, 40, 'bpmn_end');
    $d->edge($prev, $end);
});

saveDiagram('34_WFD_Document_Archival.drawio', 'WFD - Document Archival Cron', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 40, 40, 'bpmn_start');
    $t1 = $d->box('Cron runs\narchive job', 100, 180, 140, 60, 'bpmn_task');
    $g1 = $d->box('Expired\ndocs?', 280, 195, 60, 60, 'bpmn_gateway');
    $t2 = $d->box('Archive files', 400, 160, 120, 60, 'bpmn_task');
    $t3 = $d->box('Write audit log', 560, 160, 130, 60, 'bpmn_task');
    $end = $d->box('End', 740, 205, 40, 40, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $g1);
    $d->edge($g1, $t2, 'Yes');
    $d->edge($g1, $end, 'No');
    $d->edge($t2, $t3);
    $d->edge($t3, $end);
});

// --- Additional DFD Level 2 ---
saveDiagram('35_DFD_Level2_Public_Portal.drawio', 'DFD Level 2 - Public Portal', function (DrawioBuilder $d) {
    $p1 = $d->box('2.1 Browse\nfacilities', 80, 100, 130, 60, 'process');
    $p2 = $d->box('2.2 Announcements', 260, 100, 130, 60, 'process');
    $p3 = $d->box('2.3 Contact form', 440, 100, 120, 60, 'process');
    $p4 = $d->box('2.4 Availability API', 600, 100, 130, 60, 'process');
    $d2 = $d->box('D2 Facilities', 180, 260, 120, 50, 'datastore');
    $d6 = $d->box('D6 Public Notif', 400, 260, 130, 50, 'datastore');
    $d->edge($p1, $d2, 'read');
    $d->edge($p2, $d6, 'read');
    $d->edge($p3, $d6, 'write inquiry');
});

saveDiagram('36_DFD_Level2_Integrations.drawio', 'DFD Level 2 - Integrations Hub', function (DrawioBuilder $d) {
    $p1 = $d->box('9.1 CIMM sync', 80, 100, 120, 60, 'process');
    $p2 = $d->box('9.2 Infrastructure\nreports', 240, 100, 130, 60, 'process');
    $p3 = $d->box('9.3 UMAN assets', 410, 100, 120, 60, 'process');
    $d2 = $d->box('D2 Facilities', 200, 260, 120, 50, 'datastore');
    $d5 = $d->box('D5 Blackouts', 380, 260, 120, 50, 'datastore');
    $d8 = $d->box('D8 Sync state', 540, 260, 110, 50, 'datastore');
    $cimm = $d->box('CIMM', 80, 380, 90, 50, 'integration');
    $infra = $d->box('Infrastructure', 240, 380, 110, 50, 'integration');
    $uman = $d->box('UMAN', 410, 380, 90, 50, 'integration');
    $d->edge($cimm, $p1);
    $d->edge($infra, $p2);
    $d->edge($uman, $p3);
    $d->edge($p1, $d2);
    $d->edge($p1, $d5);
    $d->edge($p1, $d8);
});

saveDiagram('37_DFD_Level2_Occupancy.drawio', 'DFD Level 2 - Live Occupancy', function (DrawioBuilder $d) {
    $p1 = $d->box('5.1 Aggregate\nbookings', 100, 100, 130, 60, 'process');
    $p2 = $d->box('5.2 Live API\npoll', 300, 100, 120, 60, 'process');
    $p3 = $d->box('5.3 Staff\noverride', 500, 100, 120, 60, 'process');
    $d3 = $d->box('D3 Reservations', 200, 260, 130, 50, 'datastore');
    $d7 = $d->box('D7 Occupancy\ncache', 400, 260, 120, 50, 'datastore');
    $d->edge($p1, $d3, 'read');
    $d->edge($p2, $d7, 'R/W');
    $d->edge($p3, $d7, 'override');
});

saveDiagram('38_DFD_Level2_Announcements.drawio', 'DFD Level 2 - Announcements', function (DrawioBuilder $d) {
    $p1 = $d->box('7.1 Staff manual\npost', 80, 100, 130, 60, 'process');
    $p2 = $d->box('7.2 CIMM auto\n(Gemini)', 260, 100, 130, 60, 'process');
    $p3 = $d->box('7.3 Blackout auto\n(Gemini)', 440, 100, 140, 60, 'process');
    $d6 = $d->box('D6 Public\nnotifications', 280, 260, 140, 50, 'datastore');
    $gem = $d->box('Gemini', 440, 380, 100, 50, 'integration');
    $d->edge($p1, $d6, 'insert');
    $d->edge($gem, $p2);
    $d->edge($gem, $p3);
    $d->edge($p2, $d6);
    $d->edge($p3, $d6);
});

saveDiagram('39_DFD_Level2_Calendar.drawio', 'DFD Level 2 - Calendar', function (DrawioBuilder $d) {
    $p1 = $d->box('11.1 Month/week\nday views', 120, 100, 140, 60, 'process');
    $p2 = $d->box('11.2 iCal export', 320, 100, 120, 60, 'process');
    $d3 = $d->box('D3 Reservations', 200, 260, 130, 50, 'datastore');
    $d5 = $d->box('D5 Blackouts', 380, 260, 120, 50, 'datastore');
    $d->edge($p1, $d3, 'read');
    $d->edge($p1, $d5, 'read');
    $d->edge($p2, $d3, 'export');
});

saveDiagram('40_DFD_Level2_Documents.drawio', 'DFD Level 2 - Document Management', function (DrawioBuilder $d) {
    $p1 = $d->box('12.1 Secure\nupload', 100, 100, 120, 60, 'process');
    $p2 = $d->box('12.2 Archival\ncron', 280, 100, 120, 60, 'process');
    $p3 = $d->box('12.3 Admin\nrestore', 460, 100, 120, 60, 'process');
    $d2 = $d->box('D2 Documents', 280, 260, 130, 50, 'datastore');
    $d7 = $d->box('D7 Audit', 460, 260, 100, 50, 'datastore');
    $d->edge($p1, $d2, 'write');
    $d->edge($p2, $d2, 'archive');
    $d->edge($p3, $d2, 'restore');
    $d->edge($p2, $d7, 'log');
});

// --- Additional BPA Level 2 ---
$extraBpa = [
    '41_BPA_Level2_Communications.drawio' => ['Communications', [
        ['Compose announcement', 100, 120],
        ['Gemini or manual copy', 280, 120],
        ['Publish to portal', 460, 120],
        ['Email/SMS notify', 640, 120],
    ]],
    '42_BPA_Level2_Integrations.drawio' => ['Integrations Hub', [
        ['Poll CIMM API', 100, 120],
        ['Receive infra report', 280, 120],
        ['Sync UMAN assets', 460, 120],
        ['Update dashboards', 640, 120],
    ]],
    '43_BPA_Level2_Occupancy.drawio' => ['Occupancy Monitor', [
        ['Load today bookings', 100, 120],
        ['Apply check-in state', 280, 120],
        ['Compute live status', 460, 120],
        ['Staff dashboard view', 640, 120],
    ]],
    '44_BPA_Level2_Blackout.drawio' => ['Blackout Management', [
        ['Select facility/dates', 100, 120],
        ['Save blackout rows', 280, 120],
        ['Postpone reservations', 460, 120],
        ['Optional auto announce', 640, 120],
    ]],
    '45_BPA_Level2_ID_Verification.drawio' => ['ID Verification', [
        ['Open ID queue tab', 100, 120],
        ['View uploaded ID', 280, 120],
        ['Verify or request redo', 460, 120],
        ['Update user flag', 640, 120],
    ]],
];
foreach ($extraBpa as $file => [$title, $steps]) {
    saveDiagram($file, "BPA Level 2 - $title", function (DrawioBuilder $d) use ($steps) {
        $prev = $d->box('Start', 40, 130, 40, 40, 'bpmn_start');
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

// --- Additional DFD Level 2 (master doc gaps) ---
saveDiagram('46_DFD_Level2_User_Management.drawio', 'DFD Level 2 - User Management', function (DrawioBuilder $d) {
    $stf = $d->box('Staff/Admin', 40, 120, 110, 50, 'external');
    $p21 = $d->box('2.1 Register\nreview', 200, 60, 120, 60, 'process');
    $p22 = $d->box('2.2 ID\nverification', 200, 160, 120, 60, 'process');
    $p23 = $d->box('2.3 Account\nactions', 380, 110, 120, 60, 'process');
    $p7 = $d->box('7.0 Notify', 560, 110, 100, 50, 'process');
    $d1 = $d->box('D1 Users', 320, 280, 110, 50, 'datastore');
    $d2 = $d->box('D2 Documents', 200, 280, 120, 50, 'datastore');
    $d7 = $d->box('D7 Audit', 440, 280, 100, 50, 'datastore');
    $d->edge($stf, $p21);
    $d->edge($stf, $p22);
    $d->edge($stf, $p23);
    $d->edge($p21, $d1, 'R/W');
    $d->edge($p22, $d2, 'read');
    $d->edge($p23, $d1, 'update');
    $d->edge($p23, $d7, 'log');
    $d->edge($p23, $p7, 'notify');
});

saveDiagram('47_DFD_Level2_System_Settings.drawio', 'DFD Level 2 - System Settings', function (DrawioBuilder $d) {
    $adm = $d->box('Admin', 80, 120, 100, 50, 'external');
    $p131 = $d->box('13.1 Integration\nhealth', 260, 110, 140, 60, 'process');
    $d8 = $d->box('D8 Sync state', 260, 260, 120, 50, 'datastore');
    $cimm = $d->box('CIMM API', 460, 200, 110, 50, 'integration');
    $uman = $d->box('UMAN API', 460, 300, 110, 50, 'integration');
    $d->edge($adm, $p131);
    $d->edge($p131, $d8, 'read');
    $d->edge($p131, $cimm, 'ping');
    $d->edge($p131, $uman, 'ping');
});

// --- Additional WFD (master doc §4.3–4.5) ---
saveDiagram('48_WFD_CIMM_Maintenance_Sync.drawio', 'WFD - CIMM Maintenance Sync', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('Cron or staff\npage', 120, 190, 120, 60, 'bpmn_task');
    $t2 = $d->box('Fetch CIMM\nschedules', 280, 190, 120, 60, 'bpmn_task');
    $g1 = $d->box('API OK?', 440, 200, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Log error', 430, 320, 100, 50, 'bpmn_task');
    $t4 = $d->box('Map to CPRF\nfacilities', 560, 190, 130, 60, 'bpmn_task');
    $t5 = $d->box('Update status\nif active', 730, 190, 130, 60, 'bpmn_task');
    $t6 = $d->box('Sync blackout\ndates', 900, 190, 120, 60, 'bpmn_task');
    $g2 = $d->box('New\nschedule?', 1060, 200, 70, 70, 'bpmn_gateway');
    $t7 = $d->box('Gemini auto-\nannouncement', 1180, 120, 140, 60, 'bpmn_task');
    $t8 = $d->box('Skip announce', 1180, 280, 110, 50, 'bpmn_task');
    $end = $d->box('End', 1360, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $t3, 'No');
    $d->edge($g1, $t4, 'Yes');
    $d->edge($t4, $t5);
    $d->edge($t5, $t6);
    $d->edge($t6, $g2);
    $d->edge($g2, $t7, 'Yes');
    $d->edge($g2, $t8, 'No');
    $d->edge($t7, $end);
    $d->edge($t8, $end);
    $d->edge($t3, $end);
});

saveDiagram('49_WFD_CPRF_Blackout_Announcement.drawio', 'WFD - CPRF Blackout + Auto Announcement', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('Staff adds\nblackout', 120, 190, 120, 60, 'bpmn_task');
    $t2 = $d->box('Validate dates\nand reason', 280, 190, 130, 60, 'bpmn_task');
    $t3 = $d->box('Insert blackout\nrows', 450, 190, 120, 60, 'bpmn_task');
    $t4 = $d->box('Postpone\nconflicting bookings', 600, 190, 150, 60, 'bpmn_task');
    $g1 = $d->box('CPRF\nmanual?', 790, 200, 70, 70, 'bpmn_gateway');
    $t5 = $d->box('Gemini\nannouncement', 900, 120, 120, 60, 'bpmn_task');
    $t6 = $d->box('Skip — CIMM\nhandles', 900, 280, 120, 60, 'bpmn_task');
    $t7 = $d->box('Publish to\npublic portal', 1060, 120, 130, 60, 'bpmn_task');
    $end = $d->box('End', 1240, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $t3);
    $d->edge($t3, $t4);
    $d->edge($t4, $g1);
    $d->edge($g1, $t5, 'Yes');
    $d->edge($g1, $t6, 'CIMM');
    $d->edge($t5, $t7);
    $d->edge($t7, $end);
    $d->edge($t6, $end);
});

saveDiagram('50_WFD_Facility_QR_Checkin.drawio', 'WFD - Facility QR Check-In', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('User scans\nfacility QR', 120, 190, 120, 60, 'bpmn_task');
    $t2 = $d->box('Open check-in\ngate', 280, 190, 120, 60, 'bpmn_task');
    $g1 = $d->box('Logged\nin?', 440, 200, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Redirect\nlogin', 430, 320, 100, 50, 'bpmn_task');
    $g2 = $d->box('Approved\nbooking today?', 580, 200, 80, 80, 'bpmn_gateway');
    $t4 = $d->box('Show error', 570, 320, 100, 50, 'bpmn_task');
    $g3 = $d->box('Already\nchecked in?', 740, 200, 80, 80, 'bpmn_gateway');
    $t5 = $d->box('Check out', 900, 120, 100, 50, 'bpmn_task');
    $t6 = $d->box('Check in +\ntimestamp', 900, 280, 120, 60, 'bpmn_task');
    $end = $d->box('End', 1080, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $t3, 'No');
    $d->edge($g1, $g2, 'Yes');
    $d->edge($g2, $t4, 'No');
    $d->edge($g2, $g3, 'Yes');
    $d->edge($g3, $t5, 'Yes');
    $d->edge($g3, $t6, 'No');
    $d->edge($t3, $end);
    $d->edge($t4, $end);
    $d->edge($t5, $end);
    $d->edge($t6, $end);
});

// --- Additional BPMN (master doc §6.3, 6.7–6.8, 6.10, 6.12) ---
saveDiagram('51_BPMN_Staff_Approval.drawio', 'BPMN - Staff Reservation Approval', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('Open pending\ntab', 120, 190, 120, 60, 'bpmn_task');
    $t2 = $d->box('Review\nreservation', 280, 190, 120, 60, 'bpmn_task');
    $g1 = $d->box('Decision', 440, 200, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Update\napproved', 560, 100, 110, 50, 'bpmn_task');
    $t4 = $d->box('Update\ndenied', 560, 190, 110, 50, 'bpmn_task');
    $t5 = $d->box('Modify fields\n+ history', 560, 280, 120, 60, 'bpmn_task');
    $t6 = $d->box('Audit log', 720, 190, 100, 50, 'bpmn_task');
    $t7 = $d->box('Notify\nresident', 860, 190, 110, 50, 'bpmn_task');
    $end = $d->box('End', 1020, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $t3, 'Approve');
    $d->edge($g1, $t4, 'Deny');
    $d->edge($g1, $t5, 'Modify');
    $d->edge($t3, $t6);
    $d->edge($t4, $t6);
    $d->edge($t5, $t6);
    $d->edge($t6, $t7);
    $d->edge($t7, $end);
});

saveDiagram('52_BPMN_Attendance_NoShow.drawio', 'BPMN - Attendance and No-Show', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('Send event-day\nreminders', 120, 190, 130, 60, 'bpmn_task');
    $t2 = $d->box('Wait grace\nperiod', 290, 190, 110, 60, 'bpmn_task');
    $g1 = $d->box('Checked\nin?', 440, 200, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Mark no-show\nrisk', 560, 280, 120, 60, 'bpmn_task');
    $t4 = $d->box('Record violation\n(optional)', 720, 280, 130, 60, 'bpmn_task');
    $end = $d->box('End', 900, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $end, 'Yes');
    $d->edge($g1, $t3, 'No');
    $d->edge($t3, $t4);
    $d->edge($t4, $end);
});

saveDiagram('53_BPMN_Announcement_Publishing.drawio', 'BPMN - Announcement Publishing', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $g1 = $d->box('Source', 140, 200, 70, 70, 'bpmn_gateway');
    $t1 = $d->box('Staff manual\nform', 280, 80, 120, 60, 'bpmn_task');
    $t2 = $d->box('CIMM sync +\nGemini', 280, 190, 120, 60, 'bpmn_task');
    $t3 = $d->box('CPRF blackout +\nGemini', 280, 300, 130, 60, 'bpmn_task');
    $t4 = $d->box('Insert public\nnotification', 480, 190, 140, 60, 'bpmn_task');
    $t5 = $d->box('Show on home +\n/announcements', 680, 190, 150, 60, 'bpmn_task');
    $end = $d->box('End', 880, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $g1);
    $d->edge($g1, $t1, 'Manual');
    $d->edge($g1, $t2, 'CIMM');
    $d->edge($g1, $t3, 'Blackout');
    $d->edge($t1, $t4);
    $d->edge($t2, $t4);
    $d->edge($t3, $t4);
    $d->edge($t4, $t5);
    $d->edge($t5, $end);
});

saveDiagram('54_BPMN_Document_Archival.drawio', 'BPMN - Document Archival', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('Daily cron\nruns', 120, 190, 110, 60, 'bpmn_task');
    $t2 = $d->box('Find expired\ndocuments', 270, 190, 120, 60, 'bpmn_task');
    $g1 = $d->box('Any\nfound?', 430, 200, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Move to\narchive', 560, 120, 110, 50, 'bpmn_task');
    $t4 = $d->box('Log audit\nentry', 720, 120, 110, 50, 'bpmn_task');
    $end = $d->box('End', 880, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $end, 'No');
    $d->edge($g1, $t3, 'Yes');
    $d->edge($t3, $t4);
    $d->edge($t4, $end);
});

saveDiagram('55_BPMN_Reports_Export.drawio', 'BPMN - Reports Export', function (DrawioBuilder $d) {
    $start = $d->box('Start', 40, 200, 50, 50, 'bpmn_start');
    $t1 = $d->box('Staff applies\nfilters', 120, 190, 120, 60, 'bpmn_task');
    $t2 = $d->box('Render\ncharts', 280, 190, 100, 60, 'bpmn_task');
    $g1 = $d->box('Export\nformat', 420, 200, 70, 70, 'bpmn_gateway');
    $t3 = $d->box('Download\nCSV', 560, 120, 100, 50, 'bpmn_task');
    $t4 = $d->box('Print / PDF\nview', 560, 280, 110, 50, 'bpmn_task');
    $end = $d->box('End', 720, 200, 50, 50, 'bpmn_end');
    $d->edge($start, $t1);
    $d->edge($t1, $t2);
    $d->edge($t2, $g1);
    $d->edge($g1, $t3, 'CSV');
    $d->edge($g1, $t4, 'PDF');
    $d->edge($t3, $end);
    $d->edge($t4, $end);
});

// --- BPA Level 3 (master doc §5.3) ---
saveDiagram('56_BPA_Level3_Auto_Approval.drawio', 'BPA Level 3 - Auto-Approval Rules', function (DrawioBuilder $d) {
    $labels = [
        '3.4.1 auto_approve flag',
        '3.4.2 blackout / CIMM',
        '3.4.3 duration & capacity',
        '3.4.4 commercial check',
        '3.4.5 AI conflict',
        '3.4.6 user violations',
        '3.4.7 advance window',
        '3.4.8 approved or pending',
        '3.4.9 notify all',
    ];
    $positions = [
        [100, 120], [260, 120], [420, 120], [580, 120], [740, 120],
        [100, 250], [260, 250], [420, 250], [580, 250],
    ];
    $prev = $d->box('Start', 40, 140, 40, 40, 'bpmn_start');
    foreach ($labels as $i => $label) {
        [$x, $y] = $positions[$i];
        $id = $d->box($label, $x, $y, 130, 55, 'bpmn_task');
        $d->edge($prev, $id);
        $prev = $id;
    }
    $end = $d->box('End', 740, 250, 40, 40, 'bpmn_end');
    $d->edge($prev, $end);
});

// --- PNG export ---
$exportDir = $outDir . '/export';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0775, true);
}
$drawioFiles = glob($outDir . '/*.drawio') ?: [];
$pngOk = 0;
$pngFail = 0;
foreach ($drawioFiles as $drawioPath) {
    $base = basename($drawioPath, '.drawio');
    $pngPath = $exportDir . '/' . $base . '.png';
    $cmd = 'npx --yes draw.io-export ' . escapeshellarg($drawioPath) . ' -o ' . escapeshellarg($pngPath) . ' -F png 2>&1';
    exec($cmd, $execOut, $code);
    if ($code === 0 && is_file($pngPath)) {
        echo "PNG: docs/diagrams/export/$base.png\n";
        $pngOk++;
    } else {
        echo "PNG failed: $base (code $code)\n";
        $pngFail++;
    }
}
echo "PNG export: $pngOk ok, $pngFail failed\n";

// README
$readme = <<<'MD'
# Thesis Diagrams (draw.io + PNG)

Generated from the **Barangay Culiat CPRF** codebase. Regenerate everything (`.drawio` + `export/*.png`):

```bash
php scripts/generate_thesis_diagrams.php
```

## Complete file list (56 diagrams)

### BPMN (01–03, 25–30, 51–55)
| File | Topic |
|------|--------|
| `01_BPMN_Reservation_Approval` | Booking, auto-approve, staff queue |
| `02_BPMN_CIMM_Maintenance_Sync` | CIMM pull, blackouts, Gemini announcement |
| `03_BPMN_PayMongo_Payment` | Optional payment flow |
| `25_BPMN_Registration` | Resident onboarding |
| `26_BPMN_Blackout_Gemini_Announcement` | CPRF blackout + auto public post |
| `27_BPMN_Facility_QR_Checkin` | QR scan check-in/out |
| `28_BPMN_AI_Chatbot` | Gemini + fallback + prefill |
| `29_BPMN_Reschedule` | Resident reschedule rules |
| `30_BPMN_Infrastructure_Ingest` | QC Infrastructure → Brgy Culiat |
| `51_BPMN_Staff_Approval` | Staff pending-tab decisions |
| `52_BPMN_Attendance_NoShow` | Reminders → no-show → violations |
| `53_BPMN_Announcement_Publishing` | Manual, CIMM, and blackout sources |
| `54_BPMN_Document_Archival` | Cron archival job |
| `55_BPMN_Reports_Export` | CSV / PDF export |

### DFD (04–09, 17–20, 35–40, 46–47)
| File | Level |
|------|--------|
| `04_DFD_Level0_Context` | Level 0 — all external entities |
| `05_DFD_Level1_System` | Level 1 — system processes |
| `06–09` | Level 2 — Auth, Booking, Facility/CIMM, Payments |
| `17–20` | Level 2 — Notifications, AI, Admin, Check-in |
| `35–40` | Level 2 — Public, Integrations, Occupancy, Announcements, Calendar, Documents |
| `46–47` | Level 2 — User Management, System Settings |

### BPA (10–16, 21–24, 41–45, 56)
| File | Level |
|------|--------|
| `10_BPA_Level0_Integration` | Level 0 — E2E + integrations |
| `11_BPA_Level1_System` | Level 1 — 13 business processes |
| `12–16, 21–24` | Level 2 — Onboarding, Reservation, Maintenance, etc. |
| `41–45` | Level 2 — Communications, Integrations, Occupancy, Blackout, ID Verification |
| `56_BPA_Level3_Auto_Approval` | Level 3 — nine auto-approval rule steps |

### WFD (31–34, 48–50)
| File | Workflow |
|------|----------|
| `31_WFD_Registration_to_Booking` | Register → first booking |
| `32_WFD_Staff_Approval_Tabs` | Pending / Approved tabs |
| `33_WFD_Attendance_NoShow` | Reminders → no-show |
| `34_WFD_Document_Archival` | Cron archival |
| `48_WFD_CIMM_Maintenance_Sync` | CIMM cron/staff sync flow |
| `49_WFD_CPRF_Blackout_Announcement` | Manual blackout + Gemini |
| `50_WFD_Facility_QR_Checkin` | QR gate check-in/out |

### PNG exports
All matching PNGs are in **`export/`** (same base name, `.png`).

## How to open / re-export

1. [diagrams.net](https://app.diagrams.net) → Open `.drawio` file  
2. Export PNG at 300 DPI for Word  
3. Or run `php scripts/generate_thesis_diagrams.php` to refresh PNGs automatically

## Color legend
- **Blue** — External entity (resident, staff, visitor)
- **Yellow** — Process / BPMN task
- **Green** — System / start event
- **Red** — Data store / end event
- **Purple dashed** — External integration (CIMM, Infrastructure, Gemini)

MD;

file_put_contents($outDir . '/README.md', $readme);
echo "Created: docs/diagrams/README.md\n";
echo "Done. " . count($drawioFiles) . " draw.io files; PNGs in docs/diagrams/export/\n";
