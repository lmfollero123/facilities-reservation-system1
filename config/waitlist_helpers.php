<?php
/**
 * Facility waitlist: join a full slot, get offered the slot when it frees up.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/time_helpers.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

const FRS_WAITLIST_OFFER_WINDOW_HOURS = 24;

function frs_ensure_waitlist_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        $t = $pdo->query("SHOW TABLES LIKE 'waitlist_entries'");
        if ($t && $t->rowCount() === 0) {
            $sql = @file_get_contents(__DIR__ . '/../database/migration_add_waitlist.sql');
            if (is_string($sql) && $sql !== '') {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        error_log('frs_ensure_waitlist_schema: ' . $e->getMessage());
    }
}

/**
 * @return int|false New waitlist entry id, or false if already waiting/offered for this exact slot.
 */
function frs_waitlist_add_entry(PDO $pdo, int $facilityId, int $userId, string $date, string $timeSlot, ?string $purpose = null): int|false
{
    frs_ensure_waitlist_schema();

    $dupe = $pdo->prepare(
        "SELECT id FROM waitlist_entries
         WHERE facility_id = ? AND user_id = ? AND reservation_date = ? AND time_slot = ?
         AND status IN ('waiting', 'offered') LIMIT 1"
    );
    $dupe->execute([$facilityId, $userId, $date, $timeSlot]);
    if ($dupe->fetch()) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO waitlist_entries (facility_id, user_id, reservation_date, time_slot, purpose)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$facilityId, $userId, $date, $timeSlot, $purpose]);
    $id = (int)$pdo->lastInsertId();

    logAudit('Joined facility waitlist', 'Waitlist', "Facility #{$facilityId} - {$date} ({$timeSlot})", $userId);

    return $id;
}

/**
 * @return array<int, array<string, mixed>>
 */
function frs_waitlist_list_for_user(PDO $pdo, int $userId): array
{
    frs_ensure_waitlist_schema();

    $stmt = $pdo->prepare(
        'SELECT w.*, f.name AS facility_name
         FROM waitlist_entries w
         JOIN facilities f ON f.id = w.facility_id
         WHERE w.user_id = ? AND w.status IN (\'waiting\', \'offered\')
         ORDER BY w.created_at DESC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Offers a freed facility/date/time slot to the oldest matching waitlist entry, if any.
 * Called right after a reservation is cancelled/denied/expired.
 */
function frs_waitlist_offer_next_if_any(PDO $pdo, int $facilityId, string $date, string $timeSlot): void
{
    frs_ensure_waitlist_schema();

    $stmt = $pdo->prepare(
        "SELECT * FROM waitlist_entries
         WHERE facility_id = ? AND reservation_date = ? AND status = 'waiting'
         ORDER BY created_at ASC"
    );
    $stmt->execute([$facilityId, $date]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as $entry) {
        if (!timeSlotsOverlap($timeSlot, (string)$entry['time_slot'])) {
            continue;
        }

        $upd = $pdo->prepare(
            "UPDATE waitlist_entries SET status = 'offered', offer_expires_at = ? WHERE id = ? AND status = 'waiting'"
        );
        $offerExpires = date('Y-m-d H:i:s', time() + FRS_WAITLIST_OFFER_WINDOW_HOURS * 3600);
        $upd->execute([$offerExpires, $entry['id']]);

        if ($upd->rowCount() > 0) {
            $facStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
            $facStmt->execute([$facilityId]);
            $facilityName = (string)($facStmt->fetchColumn() ?: 'the facility');

            $link = base_path() . '/dashboard/book-facility?book_fac=' . $facilityId
                . '&year=' . date('Y', strtotime($date)) . '&month=' . date('n', strtotime($date));

            createNotification(
                (int)$entry['user_id'],
                'booking',
                'A slot opened up!',
                "A slot for {$facilityName} on " . date('F j, Y', strtotime($date)) . " ({$entry['time_slot']}) is now available. "
                    . "You have until " . date('M j, g:i A', strtotime($offerExpires)) . " to book it before it's offered to the next person on the waitlist.",
                $link
            );

            // This offer has a hard deadline, so also reach the resident by
            // email/SMS (respecting their notification preferences, same as
            // every other reservation-status message) - an in-app-only
            // notification is too easy to miss within the claim window.
            $userStmt = $pdo->prepare('SELECT name, email, mobile FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([(int)$entry['user_id']]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            require_once __DIR__ . '/notification_preferences.php';
            frs_ensure_notification_preferences_schema();

            if (!empty($userRow['email']) && !empty($userRow['name'])
                && frs_user_wants_notification((int)$entry['user_id'], 'booking', 'email')) {
                require_once __DIR__ . '/mail_helper.php';
                require_once __DIR__ . '/email_templates.php';
                $emailBody = getWaitlistOfferEmailTemplate(
                    $userRow['name'],
                    $facilityName,
                    $date,
                    (string)$entry['time_slot'],
                    $offerExpires
                );
                sendEmail($userRow['email'], $userRow['name'], 'A Slot Opened Up! - ' . $facilityName, $emailBody);
            }

            require_once __DIR__ . '/sms_helper.php';
            sendReservationStatusSms([
                'user_id' => (int)$entry['user_id'],
                'facility_name' => $facilityName,
                'reservation_date' => $date,
                'time_slot' => $entry['time_slot'],
                'offer_expires_at' => $offerExpires,
                'requester_mobile' => $userRow['mobile'] ?? null,
            ], 'waitlist_offered');

            logAudit('Offered waitlist slot', 'Waitlist', "Entry #{$entry['id']} - Facility #{$facilityId} - {$date} ({$timeSlot})");

            // Only the oldest matching entry gets the offer at a time.
            return;
        }
    }
}

/**
 * Expires stale offers past their claim window and rolls the slot to the next waitlisted entry.
 * @return int Number of offers expired.
 */
function frs_waitlist_expire_stale_offers(PDO $pdo): int
{
    frs_ensure_waitlist_schema();

    $stmt = $pdo->query(
        "SELECT * FROM waitlist_entries WHERE status = 'offered' AND offer_expires_at < NOW()"
    );
    $stale = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($stale as $entry) {
        $upd = $pdo->prepare("UPDATE waitlist_entries SET status = 'expired' WHERE id = ? AND status = 'offered'");
        $upd->execute([$entry['id']]);
        if ($upd->rowCount() > 0) {
            frs_waitlist_offer_next_if_any($pdo, (int)$entry['facility_id'], (string)$entry['reservation_date'], (string)$entry['time_slot']);
        }
    }

    return count($stale);
}

function frs_waitlist_cancel(PDO $pdo, int $entryId, int $userId): bool
{
    frs_ensure_waitlist_schema();

    $stmt = $pdo->prepare(
        "UPDATE waitlist_entries SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status IN ('waiting', 'offered')"
    );
    $stmt->execute([$entryId, $userId]);

    return $stmt->rowCount() > 0;
}
