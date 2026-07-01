<?php
/**
 * Admin/staff user provisioning helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/culiat_streets.php';

/**
 * Generate a temporary password that meets system rules.
 */
function frs_generate_temporary_password(): string
{
    return 'Frs' . bin2hex(random_bytes(4)) . '1';
}

/**
 * @return array{ok: bool, message: string, user_id?: int, plain_password?: string}
 */
function frs_admin_create_user(
    PDO $pdo,
    string $name,
    string $email,
    string $role,
    ?string $mobile = null,
    ?string $address = null,
    ?string $password = null,
    bool $markEmailVerified = true,
    bool $markIdVerified = false,
    int $createdByAdminId = 0,
    ?string $street = null,
    ?string $houseNumber = null
): array {
    $name = trim($name);
    $email = trim(strtolower($email));
    $mobile = $mobile !== null ? trim($mobile) : null;
    $address = $address !== null ? trim($address) : null;
    $street = $street !== null ? trim($street) : null;
    $houseNumber = $houseNumber !== null ? trim($houseNumber) : null;

    if ($name === '' || strlen($name) < 2) {
        return ['ok' => false, 'message' => 'Please enter a valid full name (at least 2 characters).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please enter a valid email address.'];
    }
    if (!in_array($role, ['Resident', 'Staff'], true)) {
        return ['ok' => false, 'message' => 'Invalid role selected. Only Resident or Staff accounts can be created here.'];
    }
    if ($role === 'Resident') {
        if ($street === '' || !frs_is_valid_culiat_street($street)) {
            return ['ok' => false, 'message' => 'Please select a valid street in Barangay Culiat.'];
        }
        if ($houseNumber === '') {
            return ['ok' => false, 'message' => 'Please enter a house number.'];
        }
        if ($address === '') {
            $address = frs_build_culiat_address($houseNumber, $street);
        }
    }

    $plainPassword = $password !== null && trim($password) !== '' ? trim($password) : frs_generate_temporary_password();
    $passwordErrors = validatePassword($plainPassword);
    if ($passwordErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $passwordErrors)];
    }

    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        return ['ok' => false, 'message' => 'This email is already registered.'];
    }

    $hasVerifiedColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'is_verified'")->fetchColumn();
    $hasEmailVerifiedColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'")->fetchColumn();
    $hasNameColumns = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'")->fetchColumn();
    $hasMobileColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'mobile'")->fetchColumn();
    $hasAddressColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'address'")->fetchColumn();
    $hasStreetColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'street'")->fetchColumn();
    $hasHouseNumberColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'house_number'")->fetchColumn();

    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $isVerified = ($role === 'Staff') ? 1 : ($markIdVerified ? 1 : 0);
    $emailVerified = $markEmailVerified ? 1 : 0;
    $verifiedAt = $isVerified ? date('Y-m-d H:i:s') : null;
    $emailVerifiedAt = $emailVerified ? date('Y-m-d H:i:s') : null;
    $verifiedBy = $isVerified && $createdByAdminId > 0 ? $createdByAdminId : null;

    try {
        if ($hasVerifiedColumn && $hasEmailVerifiedColumn && $hasNameColumns && $hasStreetColumn && $hasHouseNumberColumn) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, first_name, last_name, email, mobile, address, street, house_number, password_hash, role, status, is_verified, email_verified, email_verified_at, verified_at, verified_by)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $name,
                $email,
                $hasMobileColumn ? ($mobile ?: null) : null,
                $hasAddressColumn ? ($address ?: null) : null,
                $street ?: null,
                $houseNumber ?: null,
                $passwordHash,
                $role,
                $isVerified,
                $emailVerified,
                $emailVerifiedAt,
                $verifiedAt,
                $verifiedBy,
            ]);
        } elseif ($hasVerifiedColumn && $hasEmailVerifiedColumn && $hasNameColumns) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, first_name, last_name, email, mobile, address, password_hash, role, status, is_verified, email_verified, email_verified_at, verified_at, verified_by)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, "active", ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $name,
                $email,
                $hasMobileColumn ? ($mobile ?: null) : null,
                $hasAddressColumn ? ($address ?: null) : null,
                $passwordHash,
                $role,
                $isVerified,
                $emailVerified,
                $emailVerifiedAt,
                $verifiedAt,
                $verifiedBy,
            ]);
        } elseif ($hasVerifiedColumn && $hasEmailVerifiedColumn) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, mobile, address, password_hash, role, status, is_verified, email_verified, email_verified_at, verified_at, verified_by)
                 VALUES (?, ?, ?, ?, ?, ?, "active", ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $email,
                $hasMobileColumn ? ($mobile ?: null) : null,
                $hasAddressColumn ? ($address ?: null) : null,
                $passwordHash,
                $role,
                $isVerified,
                $emailVerified,
                $emailVerifiedAt,
                $verifiedAt,
                $verifiedBy,
            ]);
        } elseif ($hasVerifiedColumn) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, mobile, address, password_hash, role, status, is_verified, verified_at, verified_by)
                 VALUES (?, ?, ?, ?, ?, ?, "active", ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $email,
                $hasMobileColumn ? ($mobile ?: null) : null,
                $hasAddressColumn ? ($address ?: null) : null,
                $passwordHash,
                $role,
                $isVerified,
                $verifiedAt,
                $verifiedBy,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, mobile, address, password_hash, role, status)
                 VALUES (?, ?, ?, ?, ?, ?, "active")'
            );
            $stmt->execute([
                $name,
                $email,
                $hasMobileColumn ? ($mobile ?: null) : null,
                $hasAddressColumn ? ($address ?: null) : null,
                $passwordHash,
                $role,
            ]);
        }

        return [
            'ok' => true,
            'message' => 'Account created successfully.',
            'user_id' => (int)$pdo->lastInsertId(),
            'plain_password' => $plainPassword,
        ];
    } catch (Throwable $e) {
        error_log('frs_admin_create_user failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Unable to create account. Please try again.'];
    }
}
