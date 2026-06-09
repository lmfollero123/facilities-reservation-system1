<?php
/**
 * Honest placeholder for documented inbound LGU integration webhooks.
 * CPRF currently pulls CIMM data outbound; inbound routes are planned, not deployed.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(501);

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
echo json_encode([
    'success' => false,
    'error' => 'not_implemented',
    'message' => 'Inbound integration API is not deployed on this server yet.',
    'path' => '/' . $path,
    'documentation' => 'See docs/LGU_INTEGRATIONS.md — CIMM sync uses outbound pull via scripts/sync_cimm_maintenance.php.',
    'planned_routes' => [
        'POST /api/integrations/maintenance/schedule',
        'POST /api/integrations/projects/timeline',
        'POST /api/integrations/utilities/outage',
        'GET /api/integrations/facilities/status',
        'GET /api/integrations/reservations/analytics',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
