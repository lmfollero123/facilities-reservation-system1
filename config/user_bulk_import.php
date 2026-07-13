<?php
declare(strict_types=1);

require_once __DIR__ . '/user_admin.php';
require_once __DIR__ . '/culiat_streets.php';

/**
 * Parse CSV bulk import for residents/staff.
 * Expected header (case-insensitive): name, email, mobile, street, house_number [, role]
 *
 * @return array{ok: bool, message: string, created: int, skipped: int, errors: list<string>}
 */
function frs_bulk_import_users_from_csv(PDO $pdo, string $csvPath, int $actorId, bool $actorIsAdmin): array
{
    $result = ['ok' => false, 'message' => '', 'created' => 0, 'skipped' => 0, 'errors' => []];

    if (!is_readable($csvPath)) {
        $result['message'] = 'Unable to read uploaded CSV file.';
        return $result;
    }

    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        $result['message'] = 'Unable to open CSV file.';
        return $result;
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        $result['message'] = 'CSV file is empty.';
        return $result;
    }

    $map = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim((string)$col));
        $key = str_replace([' ', '-'], '_', $key);
        $map[$key] = $i;
    }

    foreach (['name', 'email'] as $required) {
        if (!isset($map[$required])) {
            fclose($handle);
            $result['message'] = 'CSV must include columns: name, email (optional: mobile, street, house_number, role).';
            return $result;
        }
    }

    $lineNo = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $lineNo++;
        if ($row === [null] || $row === []) {
            continue;
        }

        $name = trim((string)($row[$map['name']] ?? ''));
        $email = trim((string)($row[$map['email']] ?? ''));
        $mobile = isset($map['mobile']) ? trim((string)($row[$map['mobile']] ?? '')) : '';
        $street = isset($map['street']) ? trim((string)($row[$map['street']] ?? '')) : '';
        $house = isset($map['house_number']) ? trim((string)($row[$map['house_number']] ?? '')) : '';
        if ($house === '' && isset($map['house'])) {
            $house = trim((string)($row[$map['house']] ?? ''));
        }
        $role = isset($map['role']) ? trim((string)($row[$map['role']] ?? 'Resident')) : 'Resident';
        if ($role === '') {
            $role = 'Resident';
        }
        if (!in_array($role, ['Resident', 'Staff'], true)) {
            $role = 'Resident';
        }
        if (!$actorIsAdmin) {
            $role = 'Resident';
        }

        if ($name === '' && $email === '') {
            continue;
        }

        $create = frs_admin_create_user(
            $pdo,
            $name,
            $email,
            $role,
            $mobile !== '' ? $mobile : null,
            null,
            null,
            true,
            false,
            $actorId,
            $street !== '' ? $street : null,
            $house !== '' ? $house : null
        );

        if ($create['ok']) {
            $result['created']++;
        } else {
            $result['skipped']++;
            $result['errors'][] = "Line {$lineNo} ({$email}): " . $create['message'];
        }
    }

    fclose($handle);
    $result['ok'] = true;
    $result['message'] = "Import finished: {$result['created']} created, {$result['skipped']} skipped.";
    return $result;
}
