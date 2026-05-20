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
