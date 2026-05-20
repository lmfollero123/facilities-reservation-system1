<?php
/**
 * Reservation supporting documents (event permits, letters, etc.)
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

define('RESERVATION_DOCUMENT_STORAGE', 'storage/private/reservation_documents/');

function frs_reservation_document_storage_path(int $reservationId): string
{
    $dir = app_root_path() . '/' . RESERVATION_DOCUMENT_STORAGE . $reservationId;
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function frs_ensure_reservation_documents_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $t = $pdo->query("SHOW TABLES LIKE 'reservation_documents'");
        if ($t && $t->rowCount() === 0) {
            $sql = @file_get_contents(__DIR__ . '/../database/migration_add_reservation_documents.sql');
            if (is_string($sql) && $sql !== '') {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('frs_ensure_reservation_documents_schema: ' . $e->getMessage());
    }
}

/**
 * @return array{ok: bool, error?: string, id?: int}
 */
function frs_store_reservation_document(int $reservationId, array $file, int $uploadedBy, string $documentType = 'event_permit'): array
{
    frs_ensure_reservation_documents_schema();
    if ($reservationId <= 0) {
        return ['ok' => false, 'error' => 'Invalid reservation.'];
    }
    $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    $errors = validateFileUpload($file, $allowed, 8 * 1024 * 1024);
    if (!empty($errors)) {
        return ['ok' => false, 'error' => implode(' ', $errors)];
    }
    $allowedTypes = ['event_permit', 'barangay_resolution', 'letter_request', 'other'];
    if (!in_array($documentType, $allowedTypes, true)) {
        $documentType = 'event_permit';
    }
    $orig = basename((string)($file['name'] ?? 'document'));
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig) ?: 'document';
    $ext = pathinfo($safe, PATHINFO_EXTENSION);
    $stored = $documentType . '_' . time() . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
    $destDir = frs_reservation_document_storage_path($reservationId);
    $dest = $destDir . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save uploaded file.'];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO reservation_documents (reservation_id, document_type, file_path, file_name, file_size, uploaded_by)
             VALUES (:rid, :type, :path, :name, :size, :uid)'
        );
        $stmt->execute([
            'rid' => $reservationId,
            'type' => $documentType,
            'path' => $dest,
            'name' => $safe,
            'size' => (int)($file['size'] ?? 0),
            'uid' => $uploadedBy > 0 ? $uploadedBy : null,
        ]);
        $docId = (int)$pdo->lastInsertId();
        logAudit('Uploaded reservation document', 'Reservations', 'RES-' . $reservationId . ' doc #' . $docId, $uploadedBy);
        return ['ok' => true, 'id' => $docId];
    } catch (Throwable $e) {
        @unlink($dest);
        error_log('frs_store_reservation_document: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error saving document.'];
    }
}

function frs_reservation_document_type_label(string $type): string
{
    $labels = [
        'event_permit' => 'Event / activity permit',
        'barangay_resolution' => 'Barangay resolution',
        'letter_request' => 'Letter of request',
        'other' => 'Other',
    ];
    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}

/**
 * @return list<array<string, mixed>>
 */
function frs_list_reservation_documents(int $reservationId): array
{
    if ($reservationId <= 0) {
        return [];
    }
    frs_ensure_reservation_documents_schema();
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT rd.id, rd.reservation_id, rd.document_type, rd.file_name, rd.file_size, rd.created_at,
                    u.name AS uploaded_by_name
             FROM reservation_documents rd
             LEFT JOIN users u ON rd.uploaded_by = u.id
             WHERE rd.reservation_id = :rid
             ORDER BY rd.created_at ASC'
        );
        $stmt->execute(['rid' => $reservationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('frs_list_reservation_documents: ' . $e->getMessage());
        return [];
    }
}

/**
 * @param list<int> $reservationIds
 * @return array<int, list<array<string, mixed>>>
 */
function frs_list_reservation_documents_for_ids(array $reservationIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $reservationIds), static fn ($id) => $id > 0)));
    if ($ids === []) {
        return [];
    }
    frs_ensure_reservation_documents_schema();
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = [];
    }
    try {
        $pdo = db();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT rd.id, rd.reservation_id, rd.document_type, rd.file_name, rd.file_size, rd.created_at,
                    u.name AS uploaded_by_name
             FROM reservation_documents rd
             LEFT JOIN users u ON rd.uploaded_by = u.id
             WHERE rd.reservation_id IN ($placeholders)
             ORDER BY rd.reservation_id, rd.created_at ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)($row['reservation_id'] ?? 0);
            if ($rid > 0) {
                $out[$rid][] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('frs_list_reservation_documents_for_ids: ' . $e->getMessage());
    }
    return $out;
}

function frs_reservation_document_download_url(int $documentId, string $accessType = 'view'): string
{
    return base_path() . '/dashboard/download-reservation-document?id=' .
        urlencode((string)$documentId) . '&type=' . urlencode($accessType);
}

/**
 * @return array{allowed: bool, reason: string}
 */
function frs_can_access_reservation_document(int $documentId, int $userId, string $role): array
{
    if ($documentId <= 0 || $userId <= 0) {
        return ['allowed' => false, 'reason' => 'Invalid request.'];
    }
    if (in_array($role, ['Admin', 'Staff'], true)) {
        return ['allowed' => true, 'reason' => 'Staff access'];
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT r.user_id FROM reservation_documents rd
             JOIN reservations r ON r.id = rd.reservation_id
             WHERE rd.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $documentId]);
        $ownerId = (int)$stmt->fetchColumn();
        if ($ownerId <= 0) {
            return ['allowed' => false, 'reason' => 'Document not found.'];
        }
        if ($ownerId === $userId) {
            return ['allowed' => true, 'reason' => 'Reservation owner'];
        }
        return ['allowed' => false, 'reason' => 'You do not have permission to view this document.'];
    } catch (Throwable $e) {
        error_log('frs_can_access_reservation_document: ' . $e->getMessage());
        return ['allowed' => false, 'reason' => 'Unable to verify access.'];
    }
}

function frs_get_reservation_document_file_path(int $documentId): ?string
{
    if ($documentId <= 0) {
        return null;
    }
    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT file_path FROM reservation_documents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $documentId]);
        $path = $stmt->fetchColumn();
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }
        $root = realpath(app_root_path());
        $real = realpath($path);
        if ($root === false || $real === false || strpos($real, $root) !== 0) {
            return null;
        }
        return $real;
    } catch (Throwable $e) {
        error_log('frs_get_reservation_document_file_path: ' . $e->getMessage());
        return null;
    }
}
