<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/permissions.php';

$role = $_SESSION['role'] ?? 'Resident';
if (!($_SESSION['user_authenticated'] ?? false) || !frs_can_read($role, 'facilities')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit.php';
require_once __DIR__ . '/../../../../config/maintenance_helper.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/occupancy_monitoring.php';
require_once __DIR__ . '/../../../../config/lookups.php';
require_once __DIR__ . '/../../../../services/uman_api.php';
require_once __DIR__ . '/../../../../services/ipms_api.php';
$pdo = db();
$hasUmanEquipment = frs_uman_tables_exist($pdo);
$umanAssetsCatalog = [];
$umanAssetsIndexed = [];
if ($hasUmanEquipment) {
    $umanFetch = fetchUMANAssets(true);
    $umanAssetsCatalog = $umanFetch['data'] ?? [];
    $umanAssetsIndexed = frs_index_uman_assets($umanAssetsCatalog);
}
$pageTitle = 'Facility Management | LGU Facilities Reservation';
$facilityStatusOptions = frs_lookup_values($pdo, 'facility_status');

// Permission checks
$canUpdateFacilities = frs_can_update($role, 'facilities');
$canDeleteFacilities = frs_can_delete($role, 'facilities');

$message = '';
$messageType = '';
$hasFacilityQr = frs_facility_qr_column_exists($pdo);
$isAjaxPost = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'FRSAjaxForm';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX geocoding request
    if (isset($_POST['geocode_address']) && $_POST['geocode_address'] === '1') {
        header('Content-Type: application/json');

        $address = trim($_POST['address'] ?? '');
        if (empty($address)) {
            echo json_encode(['ok' => false, 'message' => 'Address is required']);
            exit;
        }

        require_once __DIR__ . '/../../../../config/geocoding.php';
        $coords = geocodeAddress($address);

        if ($coords) {
            echo json_encode([
                'ok' => true,
                'lat' => $coords['lat'],
                'lng' => $coords['lng']
            ]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Could not find coordinates for this address']);
        }
        exit;
    }

    // Handle AJAX reverse geocoding request
    if (isset($_POST['reverse_geocode']) && $_POST['reverse_geocode'] === '1') {
        header('Content-Type: application/json');

        $lat = trim($_POST['lat'] ?? '');
        $lng = trim($_POST['lng'] ?? '');

        if (empty($lat) || empty($lng)) {
            echo json_encode(['ok' => false, 'message' => 'Coordinates are required']);
            exit;
        }

        require_once __DIR__ . '/../../../../config/geocoding.php';
        $address = reverseGeocodeCoordinates($lat, $lng);

        if ($address) {
            echo json_encode([
                'ok' => true,
                'address' => $address
            ]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Could not find address for these coordinates']);
        }
        exit;
    }

    if (!isset($_POST[CSRF_TOKEN_NAME]) || !verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } elseif (($_POST['action'] ?? '') === 'request_uman_return' && $hasUmanEquipment) {
        $retFacilityId = (int)($_POST['facility_id'] ?? 0);
        $retAssetId    = (int)($_POST['uman_asset_id'] ?? 0);
        $retType       = trim((string)($_POST['return_type'] ?? 'RETURN_ONLY'));
        $retCondition  = trim((string)($_POST['return_condition'] ?? ''));
        $retReason     = trim((string)($_POST['return_reason'] ?? ''));
        $byUserId      = (int)($_SESSION['user_id'] ?? 0);
        $decomConfirm  = isset($_POST['decommission_confirm']) && $_POST['decommission_confirm'] === '1';

        if ($retFacilityId <= 0 || $retAssetId <= 0) {
            $message = 'Invalid facility or asset for return.';
            $messageType = 'error';
        } elseif (!in_array($retType, ['RETURN_ONLY', 'RETURN_AND_REPLACE', 'RETURN_DECOMMISSION'], true)) {
            $message = 'Invalid return type selected.';
            $messageType = 'error';
        } elseif ($retCondition === '') {
            $message = 'Please describe the current condition of the equipment.';
            $messageType = 'error';
        } elseif ($retReason === '') {
            $message = 'Please provide a reason for the return.';
            $messageType = 'error';
        } elseif ($retType === 'RETURN_DECOMMISSION' && !$decomConfirm) {
            $message = 'For decommissioning, you must confirm that the equipment is beyond repair and will not be returned to UMAN inventory.';
            $messageType = 'error';
        } else {
            $nameStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
            $nameStmt->execute([$retFacilityId]);
            $facName = (string)($nameStmt->fetchColumn() ?: ('Facility #' . $retFacilityId));

            $result = frs_uman_request_return(
                $pdo, $retFacilityId, $retAssetId, $retType, $retCondition, $retReason, $byUserId
            );
            $typeLabel = [
                'RETURN_ONLY'            => 'Return Only',
                'RETURN_AND_REPLACE'     => 'Return + Replace',
                'RETURN_DECOMMISSION'    => 'Decommission (WMR)',
            ][$retType] ?? 'Return';

            $details = "{$typeLabel} requested for UMAN Asset #{$retAssetId} at {$facName}";
            if (!empty($result['event_ref'])) {
                $details .= " (event: {$result['event_ref']})";
            }
            logAudit('CPRF UMAN Return Requested', 'Facility Management', $details . ' - Reason: ' . $retReason);

            if (empty($result['ok'])) {
                $message = 'Return request failed: ' . ($result['error'] ?? 'Unknown error.');
                $messageType = 'error';
            } else {
                $message = "Return request ({$typeLabel}) submitted successfully. The equipment is now flagged as 'Return Pending'.";
                $messageType = 'success';
                if (!empty($result['replacement_asset_id'])) {
                    $message .= " UMAN has allocated replacement asset #{$result['replacement_asset_id']}.";
                }
                if (empty($result['webhook_ok'])) {
                    $message .= ' NOTE: UMAN system is offline; the request was saved locally and will sync when UMAN is reachable.';
                } elseif (!empty($result['pickup_instructions'])) {
                    $message .= ' Pickup: ' . $result['pickup_instructions'];
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'cancel_uman_return' && $hasUmanEquipment) {
        $canFacilityId = (int)($_POST['facility_id'] ?? 0);
        $canAssetId    = (int)($_POST['uman_asset_id'] ?? 0);
        $canReason    = trim((string)($_POST['cancel_reason'] ?? ''));
        $byUserId     = (int)($_SESSION['user_id'] ?? 0);
        if ($canFacilityId <= 0 || $canAssetId <= 0) {
            $message = 'Invalid facility or asset for cancel.';
            $messageType = 'error';
        } else {
            $result = frs_uman_cancel_return($pdo, $canFacilityId, $canAssetId, $canReason, $byUserId);
            if (empty($result['ok'])) {
                $message = 'Could not cancel return: ' . ($result['error'] ?? 'Unknown error.');
                $messageType = 'error';
            } else {
                $nameStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
                $nameStmt->execute([$canFacilityId]);
                $facName = (string)($nameStmt->fetchColumn() ?: ('Facility #' . $canFacilityId));
                logAudit('CPRF UMAN Return Cancelled', 'Facility Management',
                    "Cancelled return for UMAN Asset #{$canAssetId} at {$facName}" .
                    ($canReason !== '' ? " - {$canReason}" : ''));
                $message = 'Return request cancelled. Equipment remains in active custody.';
                $messageType = 'success';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'mark_replacement_received' && $hasUmanEquipment) {
        $recFacilityId     = (int)($_POST['facility_id'] ?? 0);
        $recAssetId        = (int)($_POST['replacement_asset_id'] ?? 0);
        $recCondition      = trim((string)($_POST['received_condition'] ?? ''));
        $recNotes          = trim((string)($_POST['received_notes'] ?? ''));
        $byUserId          = (int)($_SESSION['user_id'] ?? 0);
        if ($recFacilityId <= 0 || $recAssetId <= 0) {
            $message = 'Invalid facility or replacement asset.';
            $messageType = 'error';
        } elseif ($recCondition === '') {
            $message = 'Please describe the received condition of the replacement.';
            $messageType = 'error';
        } else {
            $nameStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
            $nameStmt->execute([$recFacilityId]);
            $facName = (string)($nameStmt->fetchColumn() ?: ('Facility #' . $recFacilityId));
            $result = frs_uman_mark_replacement_received(
                $pdo, $recFacilityId, $recAssetId, $recCondition, $recNotes, $byUserId
            );
            if (empty($result['ok'])) {
                $message = 'Unable to mark as received: ' . ($result['error'] ?? 'Unknown error.');
                $messageType = 'error';
            } else {
                logAudit(
                    'CPRF UMAN Replacement Received',
                    'Facility Management',
                    "Replacement UMAN Asset #{$recAssetId} at {$facName}" .
                    (!empty($result['event_ref']) ? " (event: {$result['event_ref']})" : '') .
                    ($recNotes !== '' ? " - {$recNotes}" : '')
                );
                $message = "Replacement asset #{$recAssetId} is now marked as 'Active' for {$facName}.";
                $messageType = 'success';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'regenerate_facility_qr') {
        $regenId = (int)($_POST['facility_id'] ?? 0);
        if (!$hasFacilityQr) {
            $message = 'Facility QR is not enabled yet. Run database/migration_add_facility_checkin_qr.sql first.';
            $messageType = 'error';
        } elseif ($regenId <= 0) {
            $message = 'Invalid facility selected.';
            $messageType = 'error';
        } else {
            $nameStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
            $nameStmt->execute([$regenId]);
            $facName = (string)($nameStmt->fetchColumn() ?: 'Facility');
            $newToken = frs_regenerate_facility_checkin_token($pdo, $regenId);
            if ($newToken) {
                logAudit('Regenerated facility check-in QR', 'Facility Management', $facName . ' (ID ' . $regenId . ')');
                $message = 'A new QR code was generated for ' . $facName . '. Reprint and replace the poster at the facility.';
                $messageType = 'success';
            } else {
                $message = 'Unable to regenerate QR code. Please try again.';
                $messageType = 'error';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete_facility') {
        $delFacilityId = (int)($_POST['facility_id'] ?? 0);
        if (!$canDeleteFacilities) {
            $message = 'You do not have permission to delete facilities.';
            $messageType = 'error';
        } elseif ($delFacilityId <= 0) {
            $message = 'Invalid facility selected.';
            $messageType = 'error';
        } else {
            $delStmt = $pdo->prepare('SELECT name, status FROM facilities WHERE id = ? LIMIT 1');
            $delStmt->execute([$delFacilityId]);
            $delFacility = $delStmt->fetch(PDO::FETCH_ASSOC);
            if (!$delFacility) {
                $message = 'Facility not found.';
                $messageType = 'error';
            } elseif ($delFacility['status'] === 'deleted') {
                $message = 'This facility is already deleted.';
                $messageType = 'error';
            } else {
                $deleteReason = trim($_POST['delete_reason'] ?? '');

                // Same consequence as going into maintenance: any pending or
                // approved future reservation must not silently proceed
                // against a facility that no longer accepts bookings.
                $affectedResult = handleFacilityMaintenanceStatusChange($delFacilityId, $delFacility['name']);

                $updStmt = $pdo->prepare(
                    'UPDATE facilities
                     SET status = "deleted", deleted_at = NOW(), deleted_by = ?, delete_reason = ?
                     WHERE id = ?'
                );
                $updStmt->execute([$_SESSION['user_id'] ?? null, $deleteReason !== '' ? $deleteReason : null, $delFacilityId]);

                $affectedCount = $affectedResult['pending_cancelled'] + $affectedResult['approved_postponed'];
                $details = $delFacility['name'] . ($deleteReason !== '' ? " – Reason: {$deleteReason}" : '');
                if ($affectedCount > 0) {
                    $details .= ". Affected reservations: {$affectedCount}";
                }
                logAudit('Deleted facility', 'Facility Management', $details);

                $message = 'Facility deleted. It is now hidden from public listings and booking.';
                if ($affectedCount > 0) {
                    $message .= " {$affectedCount} affected reservation(s) were cancelled/postponed and the resident(s) notified.";
                }
                $message .= ' You can restore it later from this page.';
                $messageType = 'success';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'restore_facility') {
        $restoreId = (int)($_POST['facility_id'] ?? 0);
        if (!$canDeleteFacilities) {
            $message = 'You do not have permission to restore facilities.';
            $messageType = 'error';
        } elseif ($restoreId <= 0) {
            $message = 'Invalid facility selected.';
            $messageType = 'error';
        } else {
            $restoreStmt = $pdo->prepare('SELECT name FROM facilities WHERE id = ? LIMIT 1');
            $restoreStmt->execute([$restoreId]);
            $restoreName = (string)($restoreStmt->fetchColumn() ?: 'Facility');

            $updStmt = $pdo->prepare(
                'UPDATE facilities
                 SET status = "available", deleted_at = NULL, deleted_by = NULL, delete_reason = NULL
                 WHERE id = ?'
            );
            $updStmt->execute([$restoreId]);

            logAudit('Restored facility', 'Facility Management', $restoreName);
            $message = 'Facility restored and set to Available.';
            $messageType = 'success';
        }
    } else {
        // Get facility ID from POST data first
        $facilityId = (int)($_POST['facility_id'] ?? 0);
        
        // Check permissions for create/update/delete
        $isUpdate = $facilityId > 0;
        $action = $isUpdate ? 'update' : 'create';
        
        if ($isUpdate && !frs_can_update($role, 'facilities')) {
            $message = 'You do not have permission to update facilities.';
            $messageType = 'error';
        } elseif (!$isUpdate && !frs_can_create($role, 'facilities')) {
            $message = 'You do not have permission to create facilities.';
            $messageType = 'error';
        }
        
        if ($messageType !== 'error') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rateInput = trim((string)($_POST['base_rate'] ?? ''));
    $rate = null;
    $isFree = isset($_POST['is_free']) && $_POST['is_free'] === '1';
    
    // Calculate extension fee from base rate (base rate is typically for 4 hours, so hourly rate = base_rate / 4)
    if (!$isFree && $rateInput !== '') {
        $rate = (int)str_replace([' ', ','], '', $rateInput);
        $extensionFeePerHour = $rate > 0 ? round($rate / 4, 2) : 10.00;
    } else {
        $extensionFeePerHour = 0.00; // Free facilities have no extension fee
    }
    $location = trim($_POST['location'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');
    $amenities = trim($_POST['amenities'] ?? '');
    $rules = trim($_POST['rules'] ?? '');
    $imageCitation = trim($_POST['image_citation'] ?? '');
    $latitude = !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $status = $_POST['status'] ?? 'available';
    $autoApprove = isset($_POST['auto_approve']) && $_POST['auto_approve'] === '1';
    $capacityThreshold = !empty($_POST['capacity_threshold']) ? (int)$_POST['capacity_threshold'] : null;
    $maxDurationHours = !empty($_POST['max_duration_hours']) ? (float)$_POST['max_duration_hours'] : null;
    $operatingHours = trim($_POST['operating_hours'] ?? '');
    $extensionAutoApproveMaxHours = !empty($_POST['extension_auto_approve_max_hours']) ? (float)$_POST['extension_auto_approve_max_hours'] : null;
    $allowSameDayExtension = isset($_POST['allow_same_day_extension']) && $_POST['allow_same_day_extension'] === '1';
    $requiresDocument = isset($_POST['requires_document']) && $_POST['requires_document'] === '1';
    $documentRequirementNote = $requiresDocument ? trim((string)($_POST['document_requirement_note'] ?? '')) : null;

    // Handle image upload (optional) with enhanced security
    $imagePath = null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        require_once __DIR__ . '/../../../../config/security.php';
        require_once __DIR__ . '/../../../../config/upload_helper.php';
        $uploadErrors = validateFileUpload($_FILES['image'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 5 * 1024 * 1024);
        
        if (!empty($uploadErrors)) {
            $message = implode(' ', $uploadErrors);
            $messageType = 'error';
        } else {
            $uploadDir = __DIR__ . '/../../../../public/img/facilities';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($name));
            $fileName = $safeName . '-' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $fileName;
            [$ok, $err] = saveOptimizedImage($_FILES['image']['tmp_name'], $targetPath, 1600, 82);
            if (!$ok) {
                // Fallback to original move for GIFs/unsupported types
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $message = $err ?: 'Failed to upload image. Please try again.';
                    $messageType = 'error';
                } else {
                    @chmod($targetPath, 0644);
                    $imagePath = '/public/img/facilities/' . $fileName;
                }
            } else {
                $imagePath = '/public/img/facilities/' . $fileName;
            }
        }
    }

    if (!$name) {
        $message = 'Facility name is required.';
        $messageType = 'error';
    } elseif ($requiresDocument && $documentRequirementNote === '') {
        $message = 'Describe what document is required (e.g. "Requires approval from the school principal").';
        $messageType = 'error';
    } elseif ($rateInput !== '') {
        // Accept formatted input like "2,500" but store as whole-number pesos only.
        $normalizedRate = str_replace([',', ' ', '₱'], '', $rateInput);
        if (!preg_match('/^\d+$/', $normalizedRate)) {
            $message = 'Invalid rate format. Use whole numbers only (e.g., 2500 or 2,500). Do not use decimals or extra text.';
            $messageType = 'error';
        } else {
            $rate = (string)((int)$normalizedRate);
        }
    } else {
        $rate = null;
    }

    if ($messageType !== 'error') {
        try {
            if ($facilityId) {
                // Get old facility data for audit log
                $oldStmt = $pdo->prepare('SELECT name, status, image_path, image_citation FROM facilities WHERE id = ?');
                $oldStmt->execute([$facilityId]);
                $oldFacility = $oldStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($imagePath === null) {
                    $imagePath = $oldFacility['image_path'] ?? null;
                }
                
                // Preserve existing citation if not provided
                if ($imageCitation === '' && isset($oldFacility['image_citation'])) {
                    $imageCitation = $oldFacility['image_citation'];
                }
                
                // Geocode location if coordinates not provided but location is
                if (($latitude === null || $longitude === null) && !empty($location)) {
                    require_once __DIR__ . '/../../../../config/geocoding.php';
                    $coords = geocodeAddress($location);
                    if ($coords) {
                        $latitude = $coords['lat'];
                        $longitude = $coords['lng'];
                    }
                }

                $stmt = $pdo->prepare('UPDATE facilities SET name = ?, description = ?, base_rate = ?, is_free = ?, image_path = ?, image_citation = ?, location = ?, latitude = ?, longitude = ?, capacity = ?, amenities = ?, rules = ?, status = ?, auto_approve = ?, capacity_threshold = ?, max_duration_hours = ?, operating_hours = ?, extension_fee_per_hour = ?, extension_auto_approve_max_hours = ?, allow_same_day_extension = ?, requires_document = ?, document_requirement_note = ? WHERE id = ?');
                $stmt->execute([$name, $description, $rate, $isFree ? 1 : 0, $imagePath, $imageCitation ?: null, $location, $latitude, $longitude, $capacity, $amenities, $rules, $status, $autoApprove ? 1 : 0, $capacityThreshold, $maxDurationHours, $operatingHours ?: null, $extensionFeePerHour, $extensionAutoApproveMaxHours, $allowSameDayExtension ? 1 : 0, $requiresDocument ? 1 : 0, $documentRequirementNote ?: null, $facilityId]);
                
                // Log audit event
                $details = $name;
                if ($oldFacility && $oldFacility['status'] !== $status) {
                    $details .= ' – Status changed from ' . $oldFacility['status'] . ' to ' . $status;
                    
                    // Handle reservation status updates when facility status changes
                    if ($status === 'maintenance') {
                        // Facility went to maintenance - update reservations
                        $maintenanceResult = handleFacilityMaintenanceStatusChange($facilityId, $name);
                        $details .= '. Updated reservations: ' . ($maintenanceResult['pending_cancelled'] + $maintenanceResult['approved_postponed']) . ' affected';
                        
                        if ($maintenanceResult['pending_cancelled'] > 0 || $maintenanceResult['approved_postponed'] > 0) {
                            $message = 'Facility updated successfully. ';
                            if ($maintenanceResult['pending_cancelled'] > 0) {
                                $message .= "Cancelled {$maintenanceResult['pending_cancelled']} pending reservation(s). ";
                            }
                            if ($maintenanceResult['approved_postponed'] > 0) {
                                $message .= "Postponed {$maintenanceResult['approved_postponed']} approved reservation(s) with priority. ";
                                $message .= "Email notifications have been sent to affected users.";
                            }
                        } else {
                            $message = 'Facility updated successfully.';
                        }
                    } elseif ($oldFacility['status'] === 'maintenance' && $status === 'available') {
                        // Facility became available again - notify users with postponed reservations
                        $availableResult = handleFacilityAvailableStatusChange($facilityId, $name);
                        if ($availableResult['notified'] > 0) {
                            $message = 'Facility updated successfully. Notified ' . $availableResult['notified'] . ' user(s) with priority reservations that the facility is available again.';
                        } else {
                            $message = 'Facility updated successfully.';
                        }
                    } else {
                        $message = 'Facility updated successfully.';
                    }
                } else {
                    $message = 'Facility updated successfully.';
                }
                
                logAudit('Updated facility', 'Facility Management', $details);

                if ($hasUmanEquipment) {
                    $selectedEquipment = isset($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])
                        ? $_POST['equipment_ids']
                        : [];
                    frs_save_facility_equipment($pdo, $facilityId, $selectedEquipment, $umanAssetsIndexed);
                }
            } else {
                // Geocode location if coordinates not provided but location is
                if (($latitude === null || $longitude === null) && !empty($location)) {
                    require_once __DIR__ . '/../../../../config/geocoding.php';
                    $coords = geocodeAddress($location);
                    if ($coords) {
                        $latitude = $coords['lat'];
                        $longitude = $coords['lng'];
                    }
                }
                
                $stmt = $pdo->prepare('INSERT INTO facilities (name, description, base_rate, is_free, image_path, image_citation, location, latitude, longitude, capacity, amenities, rules, status, auto_approve, capacity_threshold, max_duration_hours, operating_hours, extension_fee_per_hour, extension_auto_approve_max_hours, allow_same_day_extension, requires_document, document_requirement_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description, $rate, $isFree ? 1 : 0, $imagePath, $imageCitation ?: null, $location, $latitude, $longitude, $capacity, $amenities, $rules, $status, $autoApprove ? 1 : 0, $capacityThreshold, $maxDurationHours, $operatingHours ?: null, $extensionFeePerHour, $extensionAutoApproveMaxHours, $allowSameDayExtension ? 1 : 0, $requiresDocument ? 1 : 0, $documentRequirementNote ?: null]);
                
                // Log audit event
                logAudit('Created facility', 'Facility Management', $name . ' (' . $status . ')');

                $newFacilityId = (int)$pdo->lastInsertId();
                if ($newFacilityId > 0 && $hasFacilityQr) {
                    frs_ensure_facility_checkin_token($pdo, $newFacilityId);
                }

                if ($newFacilityId > 0 && $hasUmanEquipment) {
                    $selectedEquipment = isset($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])
                        ? $_POST['equipment_ids']
                        : [];
                    frs_save_facility_equipment($pdo, $newFacilityId, $selectedEquipment, $umanAssetsIndexed);
                }

                $ipmsProjectKey = trim((string)($_POST['ipms_project_key'] ?? ''));
                if ($newFacilityId > 0 && $ipmsProjectKey !== '') {
                    $pins = frs_ipms_load_schedule_pins();
                    $pins[$ipmsProjectKey] = $newFacilityId;
                    frs_ipms_save_schedule_pins($pins);
                }

                $message = 'Facility added successfully.';
            }
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = 'Unable to save facility. Please try again.';
            $messageType = 'error';
        }
    }
    }
    }

    if ($isAjaxPost && $message !== '') {
        header('X-FRS-Toast: ' . rawurlencode(json_encode(['message' => $message, 'type' => $messageType === 'error' ? 'error' : 'success'])));
        $message = '';
    }
}

$facilityTab = ($_GET['tab'] ?? 'active') === 'deleted' ? 'deleted' : 'active';

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$activeFacilityCount = (int)$pdo->query("SELECT COUNT(*) FROM facilities WHERE status != 'deleted'")->fetchColumn();
$deletedFacilityCount = (int)$pdo->query("SELECT COUNT(*) FROM facilities WHERE status = 'deleted'")->fetchColumn();

$totalFacilities = $facilityTab === 'deleted' ? $deletedFacilityCount : $activeFacilityCount;
$totalPages = max(1, (int)ceil($totalFacilities / $perPage));

$facilitiesStmt = $pdo->prepare(
    "SELECT *, latitude, longitude, operating_hours FROM facilities
     WHERE status " . ($facilityTab === 'deleted' ? "= 'deleted'" : "!= 'deleted'") . "
     ORDER BY updated_at DESC LIMIT :limit OFFSET :offset"
);
$facilitiesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$facilitiesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$facilitiesStmt->execute();
$facilities = $facilitiesStmt->fetchAll(PDO::FETCH_ASSOC);

$equipmentByFacility = [];
$allowedEquipmentByFacility = [];
if ($hasUmanEquipment && $facilities !== []) {
    $equipmentByFacility = frs_get_facility_equipment_map(
        $pdo,
        array_map(static fn($f) => (int)$f['id'], $facilities)
    );
    foreach ($facilities as &$facRow) {
        $fid = (int)$facRow['id'];
        $facRow['equipment'] = $equipmentByFacility[$fid] ?? [];
        $facRow['equipment_ids'] = array_map(static fn($e) => (int)$e['uman_asset_id'], $facRow['equipment']);

        $allowed = frs_uman_allowed_assets_for_facility($pdo, $fid, $umanAssetsIndexed);
        $allowedEquipmentByFacility[$fid] = $allowed;
        // Convert to a plain zero-indexed array for the JSON payload that
        // editFacility() receives. Using the array_keys/values structure
        // guarantees zero-indexed encoding on the wire.
        $facRow['allowed_equipment'] = array_values($allowed);
    }
    unset($facRow);
}

$facilityQrById = [];
if ($hasFacilityQr) {
    foreach ($facilities as $facRow) {
        $fid = (int)$facRow['id'];
        $token = frs_ensure_facility_checkin_token($pdo, $fid);
        if (!$token) {
            continue;
        }
        $checkinUrl = frs_facility_checkin_url($token);
        $facilityQrById[$fid] = [
            'url' => $checkinUrl,
            'qr' => frs_facility_qr_image_url($checkinUrl, 240),
            'print_url' => base_path() . '/dashboard/facility-qr-print?id=' . $fid,
        ];
    }
}

// Get recent audit log entries for Facility Management module with pagination
$auditPerPage = 5;
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));
$auditOffset = ($auditPage - 1) * $auditPerPage;

$auditCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM audit_log WHERE module = "Facility Management"'
);
$auditCountStmt->execute();
$auditTotal = (int)$auditCountStmt->fetchColumn();
$auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));

$auditStmt = $pdo->prepare(
    'SELECT a.id, a.action, a.module, a.details, a.created_at, u.name AS user_name
     FROM audit_log a
     LEFT JOIN users u ON a.user_id = u.id
     WHERE a.module = "Facility Management"
     ORDER BY a.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$auditStmt->bindValue(':limit', $auditPerPage, PDO::PARAM_INT);
$auditStmt->bindValue(':offset', $auditOffset, PDO::PARAM_INT);
$auditStmt->execute();
$auditTrail = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="page-header">
    <div class="breadcrumb">
        <span>Administration</span><span class="sep">/</span><span>Facility Management</span>
    </div>
    <?= frs_page_title('Facility Management', 'Add or edit venues, capacity, rates, and whether bookings can be auto-approved.'); ?>
</div>

<?php if ($message): ?>
    <div class="message <?= $messageType; ?>" style="padding:0.85rem 1rem;border-radius:8px;margin-bottom:1.25rem;background:<?= $messageType === 'success' ? '#e3f8ef' : '#fdecee'; ?>;color:<?= $messageType === 'success' ? '#0d7a43' : '#b23030'; ?>;">
        <?= htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="facility-admin">
    <div style="margin-bottom: 1.5rem;">
        <?php if (frs_can_create($role, 'facilities')): ?>
        <button class="btn-primary" type="button" onclick="openFacilityModal()" style="display: inline-flex; align-items: center; gap: 0.75rem; padding: 1rem 1.75rem; font-size: 1rem; font-weight: 600;">
            <span style="font-size: 1.2rem;">➕</span>
            <span>Add Facility</span>
        </button>
        <?php endif; ?>
    </div>

    <div data-frs-partial-id="facility-list" data-frs-partial-root>
    <nav class="booking-hub-tabs" aria-label="Facility list sections" style="margin-bottom: 1rem;">
        <a class="booking-hub-tab <?= $facilityTab === 'active' ? 'is-active' : ''; ?>" href="?tab=active" data-frs-partial="facility-list">
            Active Facilities (<?= $activeFacilityCount; ?>)
        </a>
        <a class="booking-hub-tab <?= $facilityTab === 'deleted' ? 'is-active' : ''; ?>" href="?tab=deleted" data-frs-partial="facility-list">
            Deleted Facilities (<?= $deletedFacilityCount; ?>)
        </a>
    </nav>

    <section class="collapsible-card">
        <button type="button" class="collapsible-header" data-collapse-target="facilities-list">
            <span><?= $facilityTab === 'deleted' ? 'Deleted Facilities' : 'Facilities'; ?></span>
            <span class="chevron">▼</span>
        </button>
        <div class="collapsible-body" id="facilities-list">
            <?php if (empty($facilities)): ?>
                <article class="facility-card-admin">
                    <p><?= $facilityTab === 'deleted' ? 'No deleted facilities.' : 'No facilities added yet. Click "Add Facility" to add your first facility.'; ?></p>
                </article>
            <?php else: ?>
                <?php foreach ($facilities as $facility): ?>
                    <article class="facility-card-admin">
                        <header>
                            <div>
                                <h3><?= htmlspecialchars($facility['name']); ?></h3>
                                <?php if ($facility['base_rate'] !== null && $facility['base_rate'] !== ''): ?>
                                    <small>₱<?= number_format((int)$facility['base_rate']); ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge <?= htmlspecialchars(frs_facility_status_badge_class($pdo, (string)$facility['status'])); ?>">
                                <?= htmlspecialchars(frs_lookup_label($pdo, 'facility_status', (string)$facility['status'])); ?>
                            </span>
                        </header>
                        <?php if ($facility['description']): ?>
                            <p style="margin:0.5rem 0 1rem;color:#4c5b7c;"><?= nl2br(htmlspecialchars($facility['description'])); ?></p>
                        <?php endif; ?>
                        <?php if ($hasUmanEquipment && !empty($equipmentByFacility[(int)$facility['id']])): ?>
                            <p style="margin:0 0 0.75rem; font-size:0.85rem; color:#0066cc;">
                                <strong>UMAN equipment:</strong>
                                <?= htmlspecialchars(implode(', ', array_map(static fn($e) => $e['asset_name'], $equipmentByFacility[(int)$facility['id']]))); ?>
                            </p>
                        <?php endif; ?>
                        <div class="availability-toggle" style="display:flex; align-items:flex-start; gap:0.5rem;">
                            <input type="checkbox" <?= $facility['status'] === 'available' ? 'checked' : ''; ?> disabled style="width:18px; height:18px; min-width:18px; flex-shrink:0; margin-top:0.125rem;">
                            <span style="line-height:1.5;"><?php
                                $fsLabel = frs_lookup_label($pdo, 'facility_status', (string)$facility['status']);
                                echo frs_facility_status_blocks_booking($pdo, (string)$facility['status'])
                                    ? htmlspecialchars($fsLabel) . ' — booking blocked'
                                    : 'Available for booking';
                            ?></span>
                        </div>
                        <?php $payload = htmlspecialchars(json_encode($facility), ENT_QUOTES, 'UTF-8'); ?>
                        <div class="facility-card-actions">
                            <?php if ($hasFacilityQr && !empty($facilityQrById[(int)$facility['id']])): ?>
                                <?php $qr = $facilityQrById[(int)$facility['id']]; ?>
                                <button
                                    type="button"
                                    class="btn btn-primary js-open-qr-modal"
                                    data-facility-id="<?= (int)$facility['id']; ?>"
                                    data-facility-name="<?= htmlspecialchars($facility['name'], ENT_QUOTES); ?>"
                                    data-facility-location="<?= htmlspecialchars($facility['location'] ?? '', ENT_QUOTES); ?>"
                                    data-qr-url="<?= htmlspecialchars($qr['url'], ENT_QUOTES); ?>"
                                    data-qr-image="<?= htmlspecialchars($qr['qr'], ENT_QUOTES); ?>"
                                    data-print-url="<?= htmlspecialchars($qr['print_url'], ENT_QUOTES); ?>"
                                >Check-In QR</button>
                            <?php elseif (!$hasFacilityQr): ?>
                                <span class="fm-qr-hint">Run <code>migration_add_facility_checkin_qr.sql</code> to enable facility QR posters.</span>
                            <?php endif; ?>
                            <?php if ($canUpdateFacilities): ?>
                            <button class="btn btn-outline confirm-action" data-message="Load facility data for editing?" type="button" data-facility='<?= $payload; ?>'>Edit Details</button>
                            <?php endif; ?>
                            <?php if ($canDeleteFacilities): ?>
                                <?php if ($facility['status'] === 'deleted'): ?>
                                    <form method="POST" style="display:inline;" data-frs-ajax>
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="facility_id" value="<?= (int)$facility['id']; ?>">
                                        <button type="submit" name="action" value="restore_facility" class="btn btn-outline confirm-action" data-message="Restore &quot;<?= htmlspecialchars($facility['name'], ENT_QUOTES); ?>&quot; and set it to Available?">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;" data-frs-ajax>
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="facility_id" value="<?= (int)$facility['id']; ?>">
                                        <button type="submit" name="action" value="delete_facility" class="btn btn-outline btn-danger confirm-action" data-message="Delete &quot;<?= htmlspecialchars($facility['name'], ENT_QUOTES); ?>&quot;? It will be hidden from public listings and booking. Any pending or approved future reservations will be cancelled/postponed and residents notified. You can restore it later.">Delete</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?tab=<?= $facilityTab; ?>&page=<?= $page - 1; ?>" data-frs-partial="facility-list">&larr; Prev</a>
                        <?php endif; ?>
                        <span class="current">Page <?= $page; ?> of <?= $totalPages; ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?tab=<?= $facilityTab; ?>&page=<?= $page + 1; ?>" data-frs-partial="facility-list">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
    </div>
</div>

<style>
/* Facility modal: fixed to viewport (not page) - ensures full visibility when scrolled */
#facilityModal.facility-modal {
    position: fixed !important;
    top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
    width: 100vw !important; height: 100vh !important;
}
.facility-card-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-top: 0.75rem; }
.fm-qr-hint { font-size: 0.82rem; color: #64748b; }
.fm-qr-modal {
    position: fixed; inset: 0; z-index: 1300; display: none; align-items: center; justify-content: center; padding: 1rem;
}
.fm-qr-modal.open { display: flex; }
.fm-qr-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.5); }
.fm-qr-panel {
    position: relative; width: min(100%, 760px); max-height: calc(100vh - 2rem); overflow: auto;
    background: #fff; border-radius: 16px; box-shadow: 0 24px 48px rgba(15, 23, 42, 0.2);
}
.fm-qr-header {
    display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;
    padding: 1.1rem 1.25rem; border-bottom: 1px solid #e2e8f0;
}
.fm-qr-header h3 { margin: 0; color: #0f172a; font-size: 1.15rem; }
.fm-qr-sub { margin: 0.25rem 0 0; color: #64748b; font-size: 0.88rem; }
.fm-qr-close { border: 0; background: transparent; font-size: 1.6rem; line-height: 1; color: #64748b; cursor: pointer; }
.fm-qr-body { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 1.25rem; padding: 1.25rem; }
.fm-qr-preview {
    display: flex; align-items: center; justify-content: center; padding: 0.75rem;
    border-radius: 12px; background: #f8fafc; border: 1px solid #e2e8f0;
}
.fm-qr-preview img { border-radius: 8px; }
.fm-qr-lead { margin: 0 0 0.85rem; color: #475569; line-height: 1.5; font-size: 0.92rem; }
.fm-qr-url-label { display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.82rem; color: #475569; margin-bottom: 0.85rem; }
.fm-qr-url-label input {
    width: 100%; padding: 0.55rem 0.65rem; border: 1px solid #d7deed; border-radius: 8px; font-size: 0.82rem; color: #334155;
}
.fm-qr-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.85rem; }
.fm-qr-regen-form { margin: 0; }
.fm-qr-regen-btn { border-color: #f87171 !important; color: #b91c1c !important; }
.fm-qr-note { margin: 0.65rem 0 0; font-size: 0.78rem; color: #94a3b8; line-height: 1.45; }
@media (max-width: 720px) {
    .fm-qr-body { grid-template-columns: 1fr; }
}
</style>
<!-- Facility Modal -->
<div id="facilityModal" class="facility-modal">
    <div class="facility-modal-backdrop" onclick="closeFacilityModal()"></div>
    <div class="facility-modal-dialog">
        <div class="facility-modal-content">
            <div class="facility-modal-header">
                <h2 id="form-title">Add Facility</h2>
                <button type="button" class="facility-modal-close" onclick="closeFacilityModal()" aria-label="Close">×</button>
            </div>
            <div class="facility-modal-body">
                <form class="facility-form" method="POST" enctype="multipart/form-data" id="facilityForm" data-frs-ajax data-frs-ajax-target="facility-list" data-frs-ajax-close="#facilityModal">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="facility_id" id="facility_id">
                    <input type="hidden" name="ipms_project_key" id="form-ipms-project-key" value="">
                    <label>
                        Facility Name
                        <div class="input-wrapper">
                            <span class="input-icon">🏛️</span>
                            <input type="text" name="name" id="form-name" placeholder="e.g., Barangay Function Room" required>
                        </div>
                    </label>
                    <label>
                        <span style="font-weight: 500; color: #1b1b1f; display: block; margin-bottom: 0.5rem;">Standard Rate</span>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div class="input-wrapper" style="flex: 1; max-width: 200px;">
                                <span class="input-icon">₱</span>
                                <input type="text" name="base_rate" id="form-rate" placeholder="e.g., 2,500" inputmode="numeric" autocomplete="off">
                            </div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin: 0; white-space: nowrap;">
                                <input type="checkbox" name="is_free" id="form-is-free" value="1" checked style="width: 18px; height: 18px; cursor: pointer; margin: 0;">
                                <span style="font-weight: 500; color: #334155;">Free Facility</span>
                            </label>
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                            Whole pesos only. Comma is added automatically (e.g., 2,500). Decimals are not allowed.
                        </small>
                    </label>
                    <label>
                        Description
                        <textarea name="description" id="form-description" placeholder="Key features, inclusions, restrictions"></textarea>
                    </label>
                    <label>
                        Location
                        <div class="input-wrapper">
                            <span class="input-icon">📍</span>
                            <input type="text" name="location" id="form-location" placeholder="e.g., Barangay Culiat, Quezon City">
                        </div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Full address for location-based recommendations. Enter address to auto-fill coordinates.</small>
                        <div id="facility-geocode-status" style="margin-top:0.25rem; display:none; font-size:0.85rem;"></div>
                    </label>
                    
                    <!-- Map Section -->
                    <div style="margin-top: 1rem;">
                        <label style="display:block; margin-bottom:0.5rem; font-weight:600; color:#1b1b1f;">
                            Facility Location Map
                        </label>
                        <div id="facility-map" style="height: 300px; width: 100%; border-radius: 8px; border: 1px solid #e2e8f0; background: #f8fafc;"></div>
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                            Click on the map to set the exact location, or enter an address above to auto-locate.
                        </small>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem; display: none;">
                        <label>
                            Latitude (Optional)
                            <div class="input-wrapper">
                                <span class="input-icon">🌐</span>
                                <input type="number" step="any" name="latitude" id="form-latitude" placeholder="14.6760">
                            </div>
                        </label>
                        <label>
                            Longitude (Optional)
                            <div class="input-wrapper">
                                <span class="input-icon">🌐</span>
                                <input type="number" step="any" name="longitude" id="form-longitude" placeholder="121.0437">
                            </div>
                        </label>
                    </div>
                    <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">Coordinates will be auto-filled when you enter an address or click on the map</small>
                    <label>
                        Capacity
                        <div class="input-wrapper">
                            <span class="input-icon">👥</span>
                            <input type="text" name="capacity" id="form-capacity" placeholder="e.g., 200 persons">
                        </div>
                    </label>
                    <label>
                        Amenities <small style="color:#8b95b5; font-weight:400;">(general notes — not tracked in UMAN)</small>
                        <textarea name="amenities" id="form-amenities" placeholder="e.g., Restrooms, parking area, wheelchair access"></textarea>
                    </label>
                    <?php if ($hasUmanEquipment): ?>
                    <input type="hidden" name="facility_equipment_context_id" id="form-facility-equipment-context-id" value="0">
                    <div style="margin-top:1rem; padding:1rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <label style="display:block; font-weight:600; margin-bottom:0.5rem;">
                            UMAN Equipment / Utility Assets
                            <?= frs_field_tip('Equipment shown here is GATED by the Request → UMAN Approve/Fulfill workflow. Only assets UMAN has explicitly approved or fulfilled for this specific facility appear in this list.'); ?>
                        </label>
                        <?php if (empty($umanAssetsCatalog) && uman_api_key() === ''): ?>
                            <p style="color:#8b95b5; font-size:0.9rem; margin:0;">
                                No assets loaded from UMAN. Configure <code>UMAN_API_KEY</code> or submit requests in
                                <a href="<?= base_path(); ?>/dashboard/utilities-integration">UMAN Integration</a>.
                            </p>
                        <?php else: ?>
                            <div id="equipment-checklist" style="max-height:220px; overflow-y:auto; display:grid; gap:0.5rem;">
                                <div id="equipment-checklist-slot-empty" style="padding:1rem; text-align:center; color:#8b95b5; font-size:0.9rem; border:1px dashed #cbd5e1; border-radius:6px; background:#fff;">
                                    <div style="margin-bottom:0.35rem;">Equipment options appear after UMAN approves or fulfills a request for this facility.</div>
                                    <a href="<?= base_path(); ?>/dashboard/utilities-integration" style="color:#0066cc; font-weight:600; text-decoration:underline;">Submit a UMAN request &rarr;</a>
                                </div>
                            </div>
                            <p style="margin:0.6rem 0 0; font-size:0.8rem; color:#64748b;">
                                💡 <strong>Flow:</strong> 1) <a href="<?= base_path(); ?>/dashboard/utilities-integration" style="color:#059669;">UMAN Integration</a> → request asset for this facility.
                                2) UMAN staff fulfills the request (sets status + assigns a specific asset).
                                3) Click <em>"Sync Request Status from UMAN"</em> (or revisit this page) — the asset auto-appears above.
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <label>
                        Rules / Guidelines
                        <textarea name="rules" id="form-rules" placeholder="Key house rules to show on the public page"></textarea>
                    </label>
                    <label>
                        Facility Image
                        <input type="file" name="image" id="form-image" accept="image/*">
                    </label>
                    <label>
                        Status
                        <div class="input-wrapper">
                            <span class="input-icon">📊</span>
                            <select name="status" id="form-status">
                                <?php foreach ($facilityStatusOptions as $statusOpt): ?>
                                    <option value="<?= htmlspecialchars((string)$statusOpt['slug'], ENT_QUOTES); ?>">
                                        <?= htmlspecialchars((string)$statusOpt['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </label>
                    <label>
                        Operating Hours
                        <div style="display:flex; align-items:center; gap:0.5rem;">
                            <div class="input-wrapper" style="flex:1;">
                                <span class="input-icon">🕐</span>
                                <input type="time" id="form-operating-hours-start" onchange="syncOperatingHours()">
                            </div>
                            <span style="color:#8b95b5;">to</span>
                            <div class="input-wrapper" style="flex:1;">
                                <span class="input-icon">🕐</span>
                                <input type="time" id="form-operating-hours-end" onchange="syncOperatingHours()">
                            </div>
                        </div>
                        <input type="hidden" name="operating_hours" id="form-operating-hours">
                        <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                            Pick a start and end time. Leave both blank for default (8:00 AM - 9:00 PM).
                        </small>
                    </label>

                    <!-- Auto-Approval Settings as Collapsible Section -->
                    <div class="collapsible-card" style="margin-top: 1.5rem;">
                        <button type="button" class="collapsible-header" id="auto-approval-header" onclick="toggleAutoApprovalSection(event);" style="cursor: pointer;">
                            <span>Auto-Approval Settings</span>
                            <span class="chevron" id="auto-approval-chevron">▼</span>
                        </button>
                        <div class="collapsible-body is-collapsed" id="auto-approval-settings">
                            <p style="margin:0 0 1rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
                                <span style="font-weight:600;">Auto-approval</span>
                                <?= frs_field_tip('When enabled, reservations that meet capacity, duration, and verification rules can be approved without staff review.'); ?>
                            </p>
                            <label style="display:flex; align-items:flex-start; gap:0.5rem; margin-bottom:1rem; cursor:pointer;">
                                <input type="checkbox" name="auto_approve" value="1" id="form-auto-approve" style="width:18px; height:18px; min-width:18px; flex-shrink:0; margin-top:0.125rem;">
                                <span style="flex:1; line-height:1.5;">Enable auto-approval for this facility</span>
                            </label>

                            <label>
                                <span class="bcf-label-row">Capacity Threshold (Optional) <?= frs_field_tip('Max expected attendees for auto-approval. Leave blank for no limit.'); ?></span>
                                <div class="input-wrapper">
                                    <span class="input-icon">👥</span>
                                    <input type="number" name="capacity_threshold" id="form-capacity-threshold" min="1" placeholder="e.g., 100">
                                </div>
                            </label>

                            <label>
                                <span class="bcf-label-row">Maximum Duration (hours, Optional) <?= frs_field_tip('Longest booking length (hours) eligible for auto-approval. Leave blank for no limit.'); ?></span>
                                <div class="input-wrapper">
                                    <span class="input-icon">⏰</span>
                                    <input type="number" step="0.5" name="max_duration_hours" id="form-max-duration" min="0.5" placeholder="e.g., 4.0">
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Document Requirement -->
                    <div class="collapsible-card" style="margin-top: 1.5rem;">
                        <button type="button" class="collapsible-header" id="document-requirement-header" onclick="toggleDocumentRequirementSection(event);" style="cursor: pointer;">
                            <span>Document Requirement</span>
                            <span class="chevron" id="document-requirement-chevron">▼</span>
                        </button>
                        <div class="collapsible-body is-collapsed" id="document-requirement-settings">
                            <p style="margin:0 0 1rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
                                <span style="font-weight:600;">Supporting document</span>
                                <?= frs_field_tip('Use this when the facility itself needs a specific approval before it can be booked - e.g. a school gym needs the principal\'s sign-off because it is inside school premises. The resident must attach a document to submit the booking, and this facility is never auto-approved.'); ?>
                            </p>
                            <label style="display:flex; align-items:flex-start; gap:0.5rem; margin-bottom:1rem; cursor:pointer;">
                                <input type="checkbox" name="requires_document" value="1" id="form-requires-document" style="width:18px; height:18px; min-width:18px; flex-shrink:0; margin-top:0.125rem;" onchange="toggleDocumentRequirementNote();">
                                <span style="flex:1; line-height:1.5;">This facility requires a supporting document to book</span>
                            </label>

                            <label id="form-document-requirement-note-wrap" style="display:none;">
                                <span class="bcf-label-row">Requirement description <?= frs_field_tip('Shown to residents on the booking form and to staff on the review page.'); ?></span>
                                <textarea name="document_requirement_note" id="form-document-requirement-note" rows="2" maxlength="255" placeholder="e.g. Requires approval from the school principal (facility is inside school premises)."></textarea>
                            </label>
                        </div>
                    </div>

                    <!-- Extension Settings as Collapsible Section -->
                    <div class="collapsible-card" style="margin-top: 1.5rem;">
                        <button type="button" class="collapsible-header" id="extension-header" onclick="toggleExtensionSection(event);" style="cursor: pointer;">
                            <span>Extension Settings</span>
                            <span class="chevron" id="extension-chevron">▼</span>
                        </button>
                        <div class="collapsible-body is-collapsed" id="extension-settings">
                            <p style="margin:0 0 1rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
                                <span style="font-weight:600;">Extensions</span>
                                <?= frs_field_tip('Same-day extension requests, hourly fee, and whether extensions can be auto-approved.'); ?>
                            </p>
                            <label>
                                Extension Fee per Hour (₱)
                                <div class="input-wrapper">
                                    <span class="input-icon">₱</span>
                                    <input type="number" step="0.01" name="extension_fee_per_hour" id="form-extension-fee" min="0" placeholder="e.g., 10.00" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                                </div>
                                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                                    Automatically calculated from the facility's standard rate. Not editable.
                                </small>
                            </label>

                            <label>
                                Auto-Approve Max Hours (Optional)
                                <div class="input-wrapper">
                                    <span class="input-icon">⏰</span>
                                    <input type="number" step="0.5" name="extension_auto_approve_max_hours" id="form-extension-auto-approve" min="0" placeholder="e.g., 1.0">
                                </div>
                                <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:0.25rem;">
                                    Maximum extension hours for auto-approval. If extension is within this limit and payment is made, it will be auto-approved. Leave blank to disable auto-approval for extensions.
                                </small>
                            </label>

                            <label style="display:flex; align-items:flex-start; gap:0.5rem; margin-bottom:1rem; cursor:pointer;">
                                <input type="checkbox" name="allow_same_day_extension" value="1" id="form-allow-same-day" style="width:18px; height:18px; min-width:18px; flex-shrink:0; margin-top:0.125rem;">
                                <span style="flex:1; line-height:1.5;">Allow same-day extensions</span>
                            </label>
                            <small style="color:#8b95b5; font-size:0.85rem; display:block; margin-top:-0.5rem; margin-bottom:1rem;">
                                When enabled, users can extend their reservation on the same day if no conflicts exist and within operating hours.
                            </small>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                        <button class="btn-primary" type="submit">Save Facility</button>
                        <button class="btn-outline" type="button" onclick="cancelFacilityForm()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="facilityQrModal" class="fm-qr-modal" aria-hidden="true">
    <div class="fm-qr-backdrop js-close-qr-modal"></div>
    <div class="fm-qr-panel" role="dialog" aria-labelledby="facilityQrTitle" aria-modal="true">
        <div class="fm-qr-header">
            <div>
                <h3 id="facilityQrTitle">Facility Check-In QR</h3>
                <p id="facilityQrSubtitle" class="fm-qr-sub"></p>
            </div>
            <button type="button" class="fm-qr-close js-close-qr-modal" aria-label="Close">&times;</button>
        </div>
        <div class="fm-qr-body">
            <div class="fm-qr-preview">
                <img id="facilityQrImage" src="" alt="Facility Check-In QR code" width="220" height="220">
            </div>
            <div class="fm-qr-info">
                <p class="fm-qr-lead">Post this QR at the facility entrance. Residents scan it to Check In when they arrive and Check Out when their slot ends.</p>
                <label class="fm-qr-url-label">
                    Scan URL
                    <input id="facilityQrUrl" type="text" readonly onclick="this.select()">
                </label>
                <div class="fm-qr-actions">
                    <a id="facilityQrPrintLink" href="#" target="_blank" rel="noopener" class="btn-primary">Open print poster</a>
                    <button type="button" class="btn-outline" id="facilityQrCopyBtn">Copy URL</button>
                </div>
                <form method="POST" class="fm-qr-regen-form" id="facilityQrRegenForm" data-frs-ajax data-frs-ajax-target="facility-list">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="regenerate_facility_qr">
                    <input type="hidden" name="facility_id" id="facilityQrRegenId" value="">
                    <button type="submit" class="btn-outline fm-qr-regen-btn confirm-action" data-message="Generate a new QR code? Old printed posters will stop working.">Regenerate QR</button>
                </form>
                <p class="fm-qr-note">Regenerate only if a poster is lost or compromised. Reprint using “Open print poster”.</p>
            </div>
        </div>
    </div>
</div>

<!-- ── Phase 3: UMAN Return Request Modal (Return / Replace / Decommission) ── -->
<div id="umanReturnModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:1400; align-items:center; justify-content:center; padding:1rem;">
    <div class="modal" style="width:100%; max-width:560px; background:#fff; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="padding:1rem 1.25rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:linear-gradient(90deg,#fef3c7,#fef9c3);">
            <h3 id="umanReturnModalTitle" style="margin:0; font-size:1.05rem; color:#92400e;">Request Equipment Return</h3>
            <button type="button" onclick="closeUmanReturnModal()" style="background:transparent; border:none; font-size:1.4rem; color:#92400e; cursor:pointer; padding:0 0.25rem;">&times;</button>
        </div>
        <form method="POST" id="umanReturnForm" style="padding:1.25rem;">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="request_uman_return">
            <input type="hidden" name="facility_id" id="umanReturnFacilityId" value="0">
            <input type="hidden" name="uman_asset_id" id="umanReturnAssetId" value="0">
            <input type="hidden" name="return_type" id="umanReturnReturnType" value="RETURN_ONLY">

            <div style="margin-bottom:0.75rem;">
                <div id="umanReturnAssetName" style="font-weight:600; color:#0f172a; margin-bottom:0.2rem;"></div>
                <div id="umanReturnAssetCode" style="font-size:0.82rem; color:#64748b;"></div>
            </div>

            <label style="display:block; font-size:0.88rem; font-weight:600; color:#334155; margin:0.6rem 0 0.3rem;">Current Condition</label>
            <select name="return_condition" id="umanReturnCondition" required style="width:100%; padding:0.55rem 0.65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:0.92rem; box-sizing:border-box;">
                <option value="">-- Select condition --</option>
                <option value="Good (working)">Good — fully functional</option>
                <option value="Fair (minor issues)">Fair — minor cosmetic wear, works</option>
                <option value="Poor (needs repair)">Poor — functional but needs repair</option>
                <option value="Broken (inoperable)">Broken — inoperable, needs service</option>
                <option value="Damaged beyond repair">Destroyed / beyond economical repair</option>
                <option value="Lost or missing">Lost / missing</option>
            </select>

            <label style="display:block; font-size:0.88rem; font-weight:600; color:#334155; margin:0.6rem 0 0.3rem;">Reason / Explanation <span style="color:#dc2626;">*</span></label>
            <textarea name="return_reason" id="umanReturnReason" required rows="3" placeholder="Describe why you are returning this equipment (fault, surplus, recall notice, etc.)"
                style="width:100%; padding:0.55rem 0.65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:0.92rem; font-family:inherit; resize:vertical; box-sizing:border-box;"></textarea>

            <div id="umanDecommissionConfirm" style="display:none; margin-top:0.75rem; padding:0.7rem 0.8rem; background:#fef2f2; border:1px solid #fecaca; border-radius:8px;">
                <label style="display:flex; align-items:flex-start; gap:0.5rem; font-size:0.88rem; color:#991b1b; cursor:pointer;">
                    <input type="checkbox" name="decommission_confirm" value="1" style="margin-top:0.15rem;">
                    <span><strong>CONFIRM:</strong> This equipment is being written off (WMR/COA). It will <em>not</em> be returned to UMAN inventory, and this action cannot be undone.</span>
                </label>
            </div>

            <div style="margin-top:1.1rem; display:flex; gap:0.6rem; justify-content:flex-end;">
                <button type="button" onclick="closeUmanReturnModal()" style="padding:0.55rem 1rem; border:1px solid #cbd5e1; background:#fff; border-radius:8px; cursor:pointer; font-size:0.9rem; color:#334155;">Cancel</button>
                <button type="submit" id="umanReturnSubmitBtn" style="padding:0.55rem 1.15rem; background:#059669; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:0.9rem; font-weight:600;">Submit Return</button>
            </div>
        </form>
    </div>
</div>

<!-- Phase 3: UMAN Cancel Return form (inline with hidden inputs, confirms on submit) -->
<form method="POST" id="umanCancelReturnForm" style="display:none;">
    <?= csrf_field(); ?>
    <input type="hidden" name="action" value="cancel_uman_return">
    <input type="hidden" name="facility_id" id="umanCancelFacilityId" value="0">
    <input type="hidden" name="uman_asset_id" id="umanCancelAssetId" value="0">
    <input type="hidden" name="cancel_reason" id="umanCancelReason" value="">
</form>

<!-- ── Phase 3c: Mark Replacement as Received modal ──────────────────────── -->
<div id="umanReceivedModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:1400; align-items:center; justify-content:center; padding:1rem;">
    <div class="modal" style="width:100%; max-width:560px; background:#fff; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,0.15); overflow:hidden;">
        <div style="padding:1rem 1.25rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:linear-gradient(90deg,#ede9fe,#c7d2fe);">
            <h3 id="umanReceivedModalTitle" style="margin:0; font-size:1.05rem; color:#4c1d95;">Mark Replacement as Received</h3>
            <button type="button" onclick="closeUmanReceivedModal()" style="background:transparent; border:none; font-size:1.4rem; color:#4c1d95; cursor:pointer; padding:0 0.25rem;">&times;</button>
        </div>
        <form method="POST" id="umanReceivedForm" style="padding:1.25rem;">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="mark_replacement_received">
            <input type="hidden" name="facility_id" id="umanReceivedFacilityId" value="0">
            <input type="hidden" name="replacement_asset_id" id="umanReceivedReplacementId" value="0">

            <div style="margin-bottom:0.75rem;">
                <div id="umanReceivedAssetName" style="font-weight:600; color:#0f172a; margin-bottom:0.2rem;"></div>
                <div id="umanReceivedAssetCode" style="font-size:0.82rem; color:#64748b;"></div>
            </div>

            <label style="display:block; font-size:0.88rem; font-weight:600; color:#334155; margin:0.6rem 0 0.3rem;">Condition on Receipt <span style="color:#dc2626;">*</span></label>
            <select name="received_condition" id="umanReceivedCondition" required style="width:100%; padding:0.55rem 0.65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:0.92rem; box-sizing:border-box;">
                <option value="">-- Select condition --</option>
                <option value="Good (working)">Good — brand new / fully functional</option>
                <option value="Fair (minor issues)">Fair — minor shelf wear, works</option>
                <option value="Poor (needs repair)">Poor — arrived damaged but repairable</option>
                <option value="Damaged beyond repair">DOA / damaged in transit</option>
            </select>

            <label style="display:block; font-size:0.88rem; font-weight:600; color:#334155; margin:0.6rem 0 0.3rem;">Delivery / receipt notes (optional)</label>
            <textarea name="received_notes" id="umanReceivedNotes" rows="2" placeholder="e.g. courier tracking, signatures, any visible damage remarks"
                style="width:100%; padding:0.55rem 0.65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:0.92rem; font-family:inherit; resize:vertical; box-sizing:border-box;"></textarea>

            <div style="margin-top:1.1rem; display:flex; gap:0.6rem; justify-content:flex-end;">
                <button type="button" onclick="closeUmanReceivedModal()" style="padding:0.55rem 1rem; border:1px solid #cbd5e1; background:#fff; border-radius:8px; cursor:pointer; font-size:0.9rem; color:#334155;">Cancel</button>
                <button type="submit" style="padding:0.55rem 1.15rem; background:#6d28d9; color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:0.9rem; font-weight:600;">✓ Confirm Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
// Server-rendered config used by equipment-checklist reset() so UMAN
// Integration URLs are always correct relative to base_path().
window.FACILITY_MANAGEMENT_CONFIG = {
    utilitiesUrl: <?= json_encode(base_path() . '/dashboard/utilities-integration', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
};

// ── Phase 3: Return modal helpers ──────────────────────────────────────────
function openUmanReturnModal(facilityId, assetId, returnType, assetName, assetCode) {
    const titleMap = {
        RETURN_ONLY:            'Return Equipment to UMAN',
        RETURN_AND_REPLACE:     'Request Return + Replacement',
        RETURN_DECOMMISSION:    'Decommission / Write-Off (WMR)',
    };
    const btnMap = {
        RETURN_ONLY:            { bg:'#0284c7', label:'Submit Return Request' },
        RETURN_AND_REPLACE:     { bg:'#d97706', label:'Submit Replace Request' },
        RETURN_DECOMMISSION:    { bg:'#dc2626', label:'Confirm Decommission' },
    };
    const style = btnMap[returnType] || btnMap.RETURN_ONLY;
    document.getElementById('umanReturnModalTitle').textContent = titleMap[returnType] || 'Request Equipment Return';
    document.getElementById('umanReturnFacilityId').value = String(facilityId || 0);
    document.getElementById('umanReturnAssetId').value    = String(assetId || 0);
    document.getElementById('umanReturnReturnType').value = returnType;
    document.getElementById('umanReturnAssetName').textContent = assetName || ('UMAN Asset #' + assetId);
    document.getElementById('umanReturnAssetCode').textContent = assetCode ? ('Asset Code: ' + assetCode) : '';
    document.getElementById('umanDecommissionConfirm').style.display = returnType === 'RETURN_DECOMMISSION' ? 'block' : 'none';
    const btn = document.getElementById('umanReturnSubmitBtn');
    btn.style.backgroundColor = style.bg;
    btn.textContent = style.label;
    document.getElementById('umanReturnCondition').value = '';
    document.getElementById('umanReturnReason').value = '';
    const decommCheck = document.querySelector('#umanReturnForm input[name="decommission_confirm"]');
    if (decommCheck) decommCheck.checked = false;
    const m = document.getElementById('umanReturnModal');
    if (m) {
        if (m.parentNode !== document.body) document.body.appendChild(m);
        m.style.display = 'flex';
    }
}
function closeUmanReturnModal() {
    const m = document.getElementById('umanReturnModal');
    if (m) { m.style.display = 'none'; }
}
document.addEventListener('click', (e) => {
    const m = document.getElementById('umanReturnModal');
    if (m && e.target === m) closeUmanReturnModal();
});
document.getElementById('umanReturnForm')?.addEventListener('submit', (e) => {
    const rt = document.getElementById('umanReturnReturnType').value;
    if (rt === 'RETURN_DECOMMISSION') {
        const cb = document.querySelector('#umanReturnForm input[name="decommission_confirm"]');
        if (!cb || !cb.checked) {
            e.preventDefault();
            alert('Please tick the decommission confirmation checkbox before proceeding.');
            return;
        }
        e.preventDefault();
        const form = e.target;
        frsConfirm('WARNING: Decommissioning (WMR) writes off this asset permanently. This cannot be undone. Continue?', {title: 'Decommission asset', danger: true, confirmText: 'Decommission'}).then((ok) => {
            if (ok) { form.submit(); }
        });
    }
});
async function submitUmanCancelReturn(facilityId, assetId, reason) {
    const r = (reason && typeof reason === 'string' && reason.trim() !== '')
        ? reason.trim()
        : ('Cancelled by ' + (document.body.dataset.userName || 'CPRF staff') + ' via Facility Management UI');
    const ok = await frsConfirm('Cancel the pending return request for this asset?', {title: 'Cancel return request', danger: true, confirmText: 'Cancel request'});
    if (!ok) return;
    const form = document.getElementById('umanCancelReturnForm');
    if (!form) return;
    document.getElementById('umanCancelFacilityId').value = String(facilityId || 0);
    document.getElementById('umanCancelAssetId').value    = String(assetId || 0);
    document.getElementById('umanCancelReason').value     = r;
    form.submit();
}
// ── Phase 3c: Mark Replacement as Received modal helpers ──────────────────
function openUmanReceivedModal(facilityId, replacementAssetId, assetName, assetCode) {
    document.getElementById('umanReceivedFacilityId').value    = String(facilityId || 0);
    document.getElementById('umanReceivedReplacementId').value   = String(replacementAssetId || 0);
    document.getElementById('umanReceivedAssetName').textContent  = assetName || ('Replacement Asset #' + replacementAssetId);
    document.getElementById('umanReceivedAssetCode').textContent = assetCode ? ('Asset Code: ' + assetCode) : '';
    document.getElementById('umanReceivedCondition').value = '';
    document.getElementById('umanReceivedNotes').value     = '';
    const m = document.getElementById('umanReceivedModal');
    if (m) {
        if (m.parentNode !== document.body) document.body.appendChild(m);
        m.style.display = 'flex';
    }
}
function closeUmanReceivedModal() {
    const m = document.getElementById('umanReceivedModal');
    if (m) m.style.display = 'none';
}
document.addEventListener('click', (e) => {
    const m = document.getElementById('umanReceivedModal');
    if (m && e.target === m) closeUmanReceivedModal();
});
// ─────────────────────────────────────────────────────────────────────────────

// Move facility modal to body so position:fixed works (parent transforms break it)
(function() {
    const modal = document.getElementById('facilityModal');
    if (modal && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
})();

// Combine the two <input type="time"> pickers into the "HH:MM-HH:MM" string
// the backend expects (see config/occupancy_monitoring.php / extension_helpers.php
// parseOperatingHours()). Leaves the hidden field blank (= facility default
// hours) unless both start and end are set.
function syncOperatingHours() {
    const start = document.getElementById('form-operating-hours-start');
    const end = document.getElementById('form-operating-hours-end');
    const hidden = document.getElementById('form-operating-hours');
    if (!start || !end || !hidden) return;
    hidden.value = (start.value && end.value) ? (start.value + '-' + end.value) : '';
}

// Parse a stored operating_hours string (24-hour "HH:MM-HH:MM" or 12-hour
// "h:MM AM/PM - h:MM AM/PM") back into the two <input type="time"> pickers,
// which only accept 24-hour "HH:MM" values.
function setOperatingHoursFields(stored) {
    const start = document.getElementById('form-operating-hours-start');
    const end = document.getElementById('form-operating-hours-end');
    const hidden = document.getElementById('form-operating-hours');
    if (!start || !end || !hidden) return;

    const raw = String(stored || '').trim();
    let startVal = '';
    let endVal = '';

    let m = raw.match(/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/);
    if (m) {
        startVal = m[1].padStart(5, '0');
        endVal = m[2].padStart(5, '0');
    } else {
        m = raw.match(/^(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i);
        if (m) {
            const to24 = function (t) {
                const parts = t.trim().toUpperCase().match(/^(\d{1,2}):(\d{2})\s*([AP]M)$/);
                if (!parts) return '';
                let h = parseInt(parts[1], 10);
                const min = parts[2];
                const ampm = parts[3];
                if (ampm === 'AM') { if (h === 12) h = 0; } else { if (h !== 12) h += 12; }
                return String(h).padStart(2, '0') + ':' + min;
            };
            startVal = to24(m[1]);
            endVal = to24(m[2]);
        }
    }

    start.value = startVal;
    end.value = endVal;
    hidden.value = raw;
}

function openFacilityModal(resetForm = true) {
    const modal = document.getElementById('facilityModal');
    if (!modal) return;
    modal.style.display = '';
    if (modal.parentNode !== document.body) document.body.appendChild(modal);
    if (resetForm) {
        resetFacilityForm();
    } else {
        // When editing, ensure auto-approval section chevron is initialized correctly
        const autoApprovalSection = document.getElementById('auto-approval-settings');
        const autoApprovalChevron = document.getElementById('auto-approval-chevron');
        if (autoApprovalSection && autoApprovalChevron) {
            if (autoApprovalSection.classList.contains('is-collapsed')) {
                autoApprovalChevron.style.transform = 'rotate(-90deg)';
            } else {
                autoApprovalChevron.style.transform = 'rotate(0deg)';
            }
        }
        // Also initialize extension section chevron
        const extensionSection = document.getElementById('extension-settings');
        const extensionChevron = document.getElementById('extension-chevron');
        if (extensionSection && extensionChevron) {
            if (extensionSection.classList.contains('is-collapsed')) {
                extensionChevron.style.transform = 'rotate(-90deg)';
            } else {
                extensionChevron.style.transform = 'rotate(0deg)';
            }
        }
    }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
        // Initialize map after modal is visible
        initFacilityMap();
        
        const nameField = document.getElementById('form-name');
        if (nameField) {
            nameField.focus();
        }
    }, 100);
}

function closeFacilityModal() {
    const modal = document.getElementById('facilityModal');
    if (!modal) return;
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

// The AJAX layer's generic close-on-success (data-frs-ajax-close) only sets
// style.display='none', but .facility-modal.open forces display:flex via
// !important in CSS — the inline style alone can't win. Watch for it and
// finish the close (drop the 'open' class) whenever that happens.
(function() {
    const modal = document.getElementById('facilityModal');
    if (!modal) return;
    const observer = new MutationObserver(function() {
        if (modal.style.display === 'none' && modal.classList.contains('open')) {
            modal.classList.remove('open');
            modal.style.display = '';
            document.body.style.overflow = '';
        }
    });
    observer.observe(modal, { attributes: true, attributeFilter: ['style'] });
})();

function cancelFacilityForm() {
    closeFacilityModal();
    resetFacilityForm();
}

function toggleAutoApprovalSection(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const section = document.getElementById('auto-approval-settings');
    const chevron = document.getElementById('auto-approval-chevron');
    const isCollapsed = section.classList.contains('is-collapsed');

    if (isCollapsed) {
        section.classList.remove('is-collapsed');
        chevron.style.transform = 'rotate(0deg)';
    } else {
        section.classList.add('is-collapsed');
        chevron.style.transform = 'rotate(-90deg)';
    }
}

function toggleDocumentRequirementSection(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const section = document.getElementById('document-requirement-settings');
    const chevron = document.getElementById('document-requirement-chevron');
    const isCollapsed = section.classList.contains('is-collapsed');

    if (isCollapsed) {
        section.classList.remove('is-collapsed');
        chevron.style.transform = 'rotate(0deg)';
    } else {
        section.classList.add('is-collapsed');
        chevron.style.transform = 'rotate(-90deg)';
    }
}

function toggleDocumentRequirementNote() {
    const checked = document.getElementById('form-requires-document').checked;
    const wrap = document.getElementById('form-document-requirement-note-wrap');
    if (wrap) wrap.style.display = checked ? 'block' : 'none';
}

function toggleExtensionSection(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const section = document.getElementById('extension-settings');
    const chevron = document.getElementById('extension-chevron');
    const isCollapsed = section.classList.contains('is-collapsed');

    if (isCollapsed) {
        section.classList.remove('is-collapsed');
        chevron.style.transform = 'rotate(0deg)';
    } else {
        section.classList.add('is-collapsed');
        chevron.style.transform = 'rotate(-90deg)';
    }
}

function editFacility(payload) {
    const facility = typeof payload === 'string' ? JSON.parse(payload) : payload;
    
    // Set form title first
    document.getElementById('form-title').textContent = 'Update Facility';
    
    // Populate all form fields
    document.getElementById('facility_id').value = facility.id || '';
    document.getElementById('form-name').value = facility.name || '';
    document.getElementById('form-rate').value = formatRateInput(facility.base_rate || '');
    document.getElementById('form-is-free').checked = (facility.is_free == 1 || facility.is_free === true || facility.is_free === null);
    document.getElementById('form-description').value = facility.description || '';
    document.getElementById('form-location').value = facility.location || '';
    document.getElementById('form-latitude').value = facility.latitude || '';
    document.getElementById('form-longitude').value = facility.longitude || '';
    document.getElementById('form-capacity').value = facility.capacity || '';
    document.getElementById('form-amenities').value = facility.amenities || '';
    document.getElementById('form-rules').value = facility.rules || '';

    // Render the equipment checklist dynamically for this facility:
    // show ONLY items that are (a) already auto-assigned to this facility
    // from fulfilled UMAN requests, or (b) approved requests with a
    // fulfilled_asset_id for this specific facility. Full-catalog checkboxes
    // were gated off in the v2 integration fix.
    //
    // Phase 3 enhancement: each row also carries a 3-button Return /
    // Replace / Decommission action column (Return Slip annex + WMR) that
    // opens the return modal with the right flavor preselected. Assets with
    // status=return_pending render a cyan "Return Pending" chip and offer a
    // "Cancel return request" link instead of the three buttons.
    //
    // Phase 3c enhancement (COA lifecycle close):
    //   - replacement_in_transit rows render violet "In Transit" badge +
    //     "Mark as Received" button that opens the received-condition modal.
    //   - archived / decommissioned rows are appended at the bottom in a
    //     History section (faded, no checkbox) with archived_at timestamp,
    //     acceptance ref + disposal_ref if any so COA §6.2 filter queries
    //     can match them 7 years later without extra joins.
    (function () {
        const ctxId = document.getElementById('form-facility-equipment-context-id');
        if (ctxId) ctxId.value = String(facility.id || 0);
        const slot = document.getElementById('equipment-checklist');
        const emptyTpl = document.getElementById('equipment-checklist-slot-empty');
        if (!slot) return;

        const allowed = Array.isArray(facility.allowed_equipment) ? facility.allowed_equipment : [];
        const checked = Array.isArray(facility.equipment_ids) ? facility.equipment_ids.map(Number) : [];

        if (allowed.length === 0) {
            slot.innerHTML = '';
            if (emptyTpl) slot.appendChild(emptyTpl.content ? emptyTpl.content.cloneNode(true) : (() => {
                const d = document.createElement('div');
                d.innerHTML = emptyTpl.outerHTML;
                return d.firstChild;
            })());
            return;
        }

        slot.innerHTML = '';
        const facilityIdForJs = Number(facility.id) || 0;

        // Categorize by status so we can bucket render
        const active = [];
        const pendingReturn = [];
        const inTransit = [];
        const history = [];
        allowed.forEach((row) => {
            const aid = Number(row.uman_asset_id) || 0;
            if (aid <= 0) return;
            const s = String(row.status || 'active');
            if (s === 'return_pending')              pendingReturn.push(row);
            else if (s === 'replacement_in_transit') inTransit.push(row);
            else if (s === 'archived' || s === 'decommissioned') history.push(row);
            else                                      active.push(row);
        });
        const orderedMain = [].concat(active, inTransit, pendingReturn);

        function badgeForSource(src) {
            return src === 'fulfilled_req' || src === 'UMAN_REQUEST_FULFILLED'
                ? '<span style="margin-left:0.3rem;padding:0.08rem 0.35rem;border-radius:3px;background:#dcfce7;color:#166534;font-size:0.72rem;">Fulfilled</span>'
                : '';
        }
        function returnTypeLabel(rt) {
            return rt ? ({RETURN_ONLY:'Return',RETURN_AND_REPLACE:'Replace',RETURN_DECOMMISSION:'Decomm'}[rt] || String(rt).replace('RETURN_','')) : '';
        }

        // ── Phase 1+2 main rows (active / inTransit / pendingReturn) ──────
        orderedMain.forEach((row) => {
            const aid = Number(row.uman_asset_id) || 0;
            if (aid <= 0) return;
            const code = row.asset_code || '';
            const type = row.asset_type || '';
            const cond = row.condition_status || '';
            const name = row.asset_name || ('UMAN Asset #' + aid);
            const isChecked = checked.includes(aid);
            const status  = String(row.status || 'active');
            const retType = String(row.return_type || '');
            const retBy   = String(row.return_requested_by || '');
            const retAt   = String(row.return_requested_at || '');
            const retReason = String(row.return_reason || '');
            const acceptedBy = String(row.accepted_return_by || '');
            const linkedRep  = Number(row.linked_replacement_asset_id) || 0;
            const disposalRef = String(row.disposal_ref || '');
            const assignedEvt = String(row.assigned_event_ref || '');

            const srcTag  = badgeForSource(row.source);
            let   retBadge = '';
            let   actionsHtml = '';
            let   rowStyle = '';
            let   checkboxDisabled = false;
            let   checkboxDisabledTitle = '';

            if (status === 'return_pending') {
                const label = returnTypeLabel(retType);
                retBadge = '<span style="margin-left:0.3rem;padding:0.08rem 0.35rem;border-radius:3px;background:#cffafe;color:#0e7490;font-size:0.72rem; font-weight:600;">↻ Return Pending'
                    + (label ? (' · ' + label) : '') + '</span>';
                const summaryBits = [];
                if (retBy)   summaryBits.push('by ' + escapeHtml(retBy));
                if (retAt)   summaryBits.push(retAt.substring(0, 16).replace('T', ' '));
                if (retReason.length > 50) summaryBits.push('reason: ' + escapeHtml(retReason.substring(0, 50)) + '…');
                actionsHtml =
                    '<div style="margin-left:auto; display:flex; flex-direction:column; align-items:flex-end; gap:0.2rem; min-width:200px;">' +
                        (summaryBits.length ? '<small style="color:#0e7490; font-size:0.7rem;">' + summaryBits.join(' · ') + '</small>' : '') +
                        '<button type="button" class="uman-ret-cancel" data-fid="' + facilityIdForJs + '" data-aid="' + aid + '" data-reason="' + escapeHtml(retReason) + '"' +
                            'style="padding:0.25rem 0.55rem; background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc; border-radius:6px; font-size:0.75rem; cursor:pointer; font-weight:600;">' +
                            '✕ Cancel return request' +
                        '</button>' +
                    '</div>';
                rowStyle = 'display:flex; align-items:flex-start; gap:0.5rem; padding:0.5rem 0.55rem; border:1px solid #a5f3fc; border-radius:8px; background:#ecfeff;';
                checkboxDisabled = true;
                checkboxDisabledTitle = 'Checkbox disabled while return is pending.';
            } else if (status === 'replacement_in_transit') {
                retBadge = '<span style="margin-left:0.3rem;padding:0.08rem 0.35rem;border-radius:3px;background:#ede9fe;color:#5b21b6;font-size:0.72rem; font-weight:600;">🚚 In Transit'
                    + (linkedRep ? (' · replaces #' + linkedRep) : '') + '</span>';
                actionsHtml =
                    '<div style="margin-left:auto; display:flex; flex-direction:column; align-items:flex-end; gap:0.2rem; min-width:200px;">' +
                        (assignedEvt ? '<small style="color:#6d28d9; font-size:0.7rem;">shipment ref: ' + escapeHtml(assignedEvt) + '</small>' : '') +
                        '<button type="button" class="uman-recv-btn" data-fid="' + facilityIdForJs + '" data-aid="' + aid + '" data-name="' + escapeHtml(name) + '" data-code="' + escapeHtml(code) + '"' +
                            'title="Mark replacement as delivered and activate it for this facility"' +
                            'style="padding:0.25rem 0.55rem; background:#6d28d9; color:#fff; border:1px solid #5b21b6; border-radius:6px; font-size:0.75rem; cursor:pointer; font-weight:600;">' +
                            '✓ Mark Received' +
                        '</button>' +
                    '</div>';
                rowStyle = 'display:flex; align-items:flex-start; gap:0.5rem; padding:0.5rem 0.55rem; border:1px solid #ddd6fe; border-radius:8px; background:#f5f3ff;';
                checkboxDisabled = true;
                checkboxDisabledTitle = 'Checkbox disabled until replacement is marked as received.';
            } else {
                // active — standard 3 return buttons
                actionsHtml =
                    '<div style="margin-left:auto; display:flex; gap:0.25rem; align-items:center; flex-shrink:0;">' +
                        '<button type="button" class="uman-ret-btn" data-ret="RETURN_ONLY" data-fid="' + facilityIdForJs + '" data-aid="' + aid + '" data-name="' + escapeHtml(name) + '" data-code="' + escapeHtml(code) + '"' +
                            'title="Return this equipment to UMAN warehouse" ' +
                            'style="padding:0.25rem 0.5rem; background:#f0f9ff; color:#0369a1; border:1px solid #bae6fd; border-radius:6px; font-size:0.75rem; cursor:pointer; font-weight:600; white-space:nowrap;">' +
                            '↩ Return' +
                        '</button>' +
                        '<button type="button" class="uman-ret-btn" data-ret="RETURN_AND_REPLACE" data-fid="' + facilityIdForJs + '" data-aid="' + aid + '" data-name="' + escapeHtml(name) + '" data-code="' + escapeHtml(code) + '"' +
                            'title="Request return + replacement from UMAN" ' +
                            'style="padding:0.25rem 0.5rem; background:#fffbeb; color:#b45309; border:1px solid #fcd34d; border-radius:6px; font-size:0.75rem; cursor:pointer; font-weight:600; white-space:nowrap;">' +
                            '🔄 Replace' +
                        '</button>' +
                        '<button type="button" class="uman-ret-btn" data-ret="RETURN_DECOMMISSION" data-fid="' + facilityIdForJs + '" data-aid="' + aid + '" data-name="' + escapeHtml(name) + '" data-code="' + escapeHtml(code) + '"' +
                            'title="Decommission / write off (WMR) — cannot be undone" ' +
                            'style="padding:0.25rem 0.5rem; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; border-radius:6px; font-size:0.75rem; cursor:pointer; font-weight:600; white-space:nowrap;">' +
                            '🗑 Decomm' +
                        '</button>' +
                    '</div>';
                rowStyle = 'display:flex; align-items:flex-start; gap:0.5rem; padding:0.35rem 0.45rem; border-radius:6px;';
            }

            const rowWrap = document.createElement('div');
            rowWrap.style.cssText = rowStyle;

            const checkboxHtml =
                '<label style="display:flex; align-items:flex-start; gap:0.5rem; font-size:0.9rem; cursor:pointer; flex:1; min-width:0;">' +
                    '<input type="checkbox" name="equipment_ids[]" value="' + aid + '" class="equipment-checkbox" style="margin-top:0.2rem;"' + (isChecked ? ' checked' : '') +
                    (checkboxDisabled ? (' disabled title="' + (checkboxDisabledTitle || 'Checkbox disabled.') + '"') : '') + '>' +
                    '<span style="flex:1; min-width:0;">' +
                        '<strong>' + escapeHtml(name) + '</strong>' + srcTag + retBadge +
                        '<small style="color:#64748b; display:block;">' + escapeHtml(code) + (code ? ' · ' : '') + escapeHtml(type) + (type ? ' · ' : '') + escapeHtml(cond) +
                        (status === 'return_pending' && retReason ? ' <span style="color:#0e7490;">· ' + escapeHtml(retReason) + '</span>' : '') +
                        (status === 'replacement_in_transit' && linkedRep ? ' <span style="color:#6d28d9;">· replaces asset #' + linkedRep + '</span>' : '') +
                        (status === 'active' && disposalRef ? ' <span style="color:#991b1b;">· disposal ref ' + escapeHtml(disposalRef) + '</span>' : '') +
                        '</small>' +
                    '</span>' +
                '</label>';

            rowWrap.innerHTML = checkboxHtml + actionsHtml;
            slot.appendChild(rowWrap);
        });

        // ── Phase 3c History block (archived / decommissioned rows) ──────
        if (history.length > 0) {
            const head = document.createElement('div');
            head.style.cssText = 'margin:0.8rem 0 0.2rem; padding:0.35rem 0.5rem; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:6px; font-size:0.75rem; color:#475569; font-weight:600; letter-spacing:0.02em;';
            head.textContent = '📦 Custody history (archived / decommissioned — for COA audit, these rows are kept 7 years per DILG standards)';
            slot.appendChild(head);

            history.forEach((row) => {
                const aid = Number(row.uman_asset_id) || 0;
                if (aid <= 0) return;
                const code  = row.asset_code || '';
                const type  = row.asset_type || '';
                const name  = row.asset_name || ('UMAN Asset #' + aid);
                const s     = String(row.status || 'archived');
                const retType = String(row.return_type || '');
                const rtLabel = returnTypeLabel(retType);
                const acceptedBy = String(row.accepted_return_by || '');
                const acceptedAt = String(row.archived_at || '');
                const disposalRef = String(row.disposal_ref || '');
                const linkedRep   = Number(row.linked_replacement_asset_id) || 0;
                const eventRef    = String(row.accepted_return_ref || '');

                const badgeClass = s === 'decommissioned'
                    ? 'background:#fee2e2;color:#991b1b;'
                    : 'background:#e2e8f0;color:#475569;';
                const badgeLabel = s === 'decommissioned' ? '🗑 Decommissioned' : '📦 Archived';
                const subBits = [];
                if (rtLabel)      subBits.push('type: ' + rtLabel);
                if (acceptedBy)   subBits.push('accepted by ' + acceptedBy);
                if (acceptedAt)   subBits.push(acceptedAt.substring(0, 16).replace('T', ' '));
                if (eventRef)     subBits.push('ref: ' + eventRef);
                if (disposalRef)  subBits.push('WMR/disposal: ' + disposalRef);
                if (linkedRep)    subBits.push('replaced by #' + linkedRep);

                const rowWrap = document.createElement('div');
                rowWrap.style.cssText = 'display:flex; align-items:flex-start; gap:0.5rem; padding:0.35rem 0.45rem; opacity:0.68; filter:grayscale(0.35); border-radius:6px;';
                rowWrap.innerHTML =
                    '<span style="font-size:0.85rem; flex:1; min-width:0;">' +
                        '<strong style="color:#334155;">' + escapeHtml(name) + '</strong>' +
                        '<span style="margin-left:0.3rem;padding:0.08rem 0.35rem;border-radius:3px;font-size:0.72rem;font-weight:600;' + badgeClass + '">' + badgeLabel + '</span>' +
                        '<small style="color:#64748b; display:block;">' + escapeHtml(code) + (code ? ' · ' : '') + escapeHtml(type) +
                        (subBits.length ? (' · ' + subBits.join(' · ')) : '') +
                        '</small>' +
                    '</span>';
                slot.appendChild(rowWrap);
            });
        }

        // Wire all the buttons we just rendered (return buttons + cancel + received).
        // Event delegation via `slot` because rows are innerHTML-replaced on
        // every editFacility() call.
        slot.addEventListener('click', (e) => {
            const retBtn = e.target && e.target.closest ? e.target.closest('button.uman-ret-btn') : null;
            if (retBtn) {
                const fid = Number(retBtn.dataset.fid) || 0;
                const aid = Number(retBtn.dataset.aid) || 0;
                const rt  = String(retBtn.dataset.ret || 'RETURN_ONLY');
                const nm  = String(retBtn.dataset.name || '');
                const cd  = String(retBtn.dataset.code || '');
                openUmanReturnModal(fid, aid, rt, nm, cd);
                return;
            }
            const cancelBtn = e.target && e.target.closest ? e.target.closest('button.uman-ret-cancel') : null;
            if (cancelBtn) {
                const fid = Number(cancelBtn.dataset.fid) || 0;
                const aid = Number(cancelBtn.dataset.aid) || 0;
                const rs  = String(cancelBtn.dataset.reason || '');
                submitUmanCancelReturn(fid, aid, rs);
                return;
            }
            const recvBtn = e.target && e.target.closest ? e.target.closest('button.uman-recv-btn') : null;
            if (recvBtn) {
                const fid = Number(recvBtn.dataset.fid) || 0;
                const aid = Number(recvBtn.dataset.aid) || 0;
                const nm  = String(recvBtn.dataset.name || '');
                const cd  = String(recvBtn.dataset.code || '');
                openUmanReceivedModal(fid, aid, nm, cd);
            }
        });

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        }
    })();

    document.getElementById('form-status').value = facility.status || 'available';
    setOperatingHoursFields(facility.operating_hours || '');
    document.getElementById('form-auto-approve').checked = (facility.auto_approve == 1 || facility.auto_approve === true);
    document.getElementById('form-capacity-threshold').value = facility.capacity_threshold || '';
    document.getElementById('form-max-duration').value = facility.max_duration_hours || '';
    document.getElementById('form-extension-fee').value = facility.extension_fee_per_hour || '';
    updateExtensionFeeFromRate();
    document.getElementById('form-extension-auto-approve').value = facility.extension_auto_approve_max_hours || '';
    document.getElementById('form-allow-same-day').checked = (facility.allow_same_day_extension == 1 || facility.allow_same_day_extension === true);
    document.getElementById('form-requires-document').checked = (facility.requires_document == 1 || facility.requires_document === true);
    document.getElementById('form-document-requirement-note').value = facility.document_requirement_note || '';
    toggleDocumentRequirementNote();

    // Update map if coordinates exist
    if (facility.latitude && facility.longitude) {
        setTimeout(() => {
            updateMapFromCoordinates(parseFloat(facility.latitude), parseFloat(facility.longitude));
        }, 300);
    }

    // Trigger rate input toggle based on checkbox state
    const isFreeCheckbox = document.getElementById('form-is-free');
    const rateEl = document.getElementById('form-rate');
    if (isFreeCheckbox && rateEl) {
        if (isFreeCheckbox.checked) {
            rateEl.disabled = true;
            rateEl.style.backgroundColor = '#f1f5f9';
            rateEl.style.color = '#94a3b8';
            rateEl.style.cursor = 'not-allowed';
            if (!rateEl.value) {
                rateEl.placeholder = 'Free - no rate required';
            }
        } else {
            rateEl.disabled = false;
            rateEl.style.backgroundColor = '';
            rateEl.style.color = '';
            rateEl.style.cursor = '';
            rateEl.placeholder = 'e.g., 2,500';
        }
    }

    // Open modal WITHOUT resetting the form (pass false)
    openFacilityModal(false);
}

function resetFacilityForm() {
    const setVal = function (id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val;
    };
    const setChecked = function (id, on) {
        const el = document.getElementById(id);
        if (el) el.checked = on;
    };
    document.getElementById('form-title').textContent = 'Add Facility';
    setVal('facility_id', '');
    setVal('form-name', '');
    setVal('form-rate', '');
    setChecked('form-is-free', true);
    setVal('form-description', '');
    setVal('form-location', '');
    setVal('form-latitude', '');
    setVal('form-longitude', '');
    setVal('form-capacity', '');
    setVal('form-amenities', '');
    setVal('form-rules', '');

    // Reset equipment checklist to the "new facility" empty state (no
    // allow-list items yet because the facility doesn't exist in the DB —
    // requests can only be attached after the row is saved with an ID).
    (function () {
        const ctxId = document.getElementById('form-facility-equipment-context-id');
        if (ctxId) ctxId.value = '0';
        const slot = document.getElementById('equipment-checklist');
        if (!slot) return;
        slot.innerHTML = '';
        const placeholder = document.createElement('div');
        placeholder.setAttribute('style', 'padding:1rem; text-align:center; color:#8b95b5; font-size:0.9rem; border:1px dashed #cbd5e1; border-radius:6px; background:#fff;');
        placeholder.innerHTML =
            '<div style="margin-bottom:0.35rem;">Equipment options appear after this facility is created and a UMAN request is approved/fulfilled for it.</div>' +
            '<a href="' + window.FACILITY_MANAGEMENT_CONFIG.utilitiesUrl + '" style="color:#0066cc; font-weight:600; text-decoration:underline;">Submit a UMAN request &rarr;</a>';
        slot.appendChild(placeholder);
    })();

    setVal('form-ipms-project-key', '');
    setVal('form-status', 'available');
    setVal('form-operating-hours-start', '');
    setVal('form-operating-hours-end', '');
    setVal('form-operating-hours', '');
    setChecked('form-auto-approve', false);
    setChecked('form-requires-document', false);
    setVal('form-document-requirement-note', '');
    toggleDocumentRequirementNote();
    setVal('form-capacity-threshold', '');
    setVal('form-max-duration', '');
    setVal('form-extension-fee', '');
    updateExtensionFeeFromRate();
    setVal('form-extension-auto-approve', '');
    setChecked('form-allow-same-day', false);
    setVal('form-image', '');

    // Trigger rate input toggle based on checkbox state
    const isFreeCheckbox = document.getElementById('form-is-free');
    const rateEl = document.getElementById('form-rate');
    if (isFreeCheckbox && rateEl) {
        if (isFreeCheckbox.checked) {
            rateEl.disabled = true;
            rateEl.style.backgroundColor = '#f1f5f9';
            rateEl.style.color = '#94a3b8';
            rateEl.style.cursor = 'not-allowed';
            rateEl.placeholder = 'Free - no rate required';
        } else {
            rateEl.disabled = false;
            rateEl.style.backgroundColor = '';
            rateEl.style.color = '';
            rateEl.style.cursor = '';
            rateEl.placeholder = 'e.g., 2,500';
        }
    }

    // Reset auto-approval section to collapsed state
    const autoApprovalSection = document.getElementById('auto-approval-settings');
    const autoApprovalChevron = document.getElementById('auto-approval-chevron');
    if (autoApprovalSection && autoApprovalChevron) {
        autoApprovalSection.classList.add('is-collapsed');
        autoApprovalChevron.style.transform = 'rotate(-90deg)';
    }

    const extensionSection = document.getElementById('extension-settings');
    const extensionChevron = document.getElementById('extension-chevron');
    if (extensionSection && extensionChevron) {
        extensionSection.classList.add('is-collapsed');
        extensionChevron.style.transform = 'rotate(-90deg)';
    }
    
    // Reset map
    if (typeof facilityMap !== 'undefined' && facilityMap) {
        facilityMap.setView([14.6760, 121.0437], 13);
        if (facilityMarker) {
            facilityMarker.setLatLng([14.6760, 121.0437]);
        }
    }
}

function prefillFromIpmsCandidate() {
    const params = new URLSearchParams(window.location.search);
    const name = params.get('prefill_name');
    const location = params.get('prefill_location');
    const lat = params.get('prefill_lat');
    const lng = params.get('prefill_lng');
    const ipmsKey = params.get('prefill_ipms_key');
    if (!name && !location && !ipmsKey) return;

    openFacilityModal(true);
    if (name) document.getElementById('form-name').value = name;
    if (location) document.getElementById('form-location').value = location;
    if (lat) document.getElementById('form-latitude').value = lat;
    if (lng) document.getElementById('form-longitude').value = lng;
    if (ipmsKey) document.getElementById('form-ipms-project-key').value = ipmsKey;
}

document.addEventListener('DOMContentLoaded', prefillFromIpmsCandidate);

// Map functionality
let facilityMap = null;
let facilityMarker = null;

function initFacilityMap() {
    if (typeof L === 'undefined') {
        console.error('Leaflet is not loaded');
        return;
    }
    
    const mapContainer = document.getElementById('facility-map');
    if (!mapContainer) return;
    
    // Default to Quezon City coordinates
    const defaultLat = 14.6760;
    const defaultLng = 121.0437;
    
    // Initialize map
    facilityMap = L.map('facility-map').setView([defaultLat, defaultLng], 13);
    
    // Add OpenStreetMap tiles (free, no API key required)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(facilityMap);
    
    // Add marker
    facilityMarker = L.marker([defaultLat, defaultLng], {
        draggable: true
    }).addTo(facilityMap);
    
    // Handle marker drag
    facilityMarker.on('dragend', function(e) {
        const position = e.target.getLatLng();
        document.getElementById('form-latitude').value = position.lat.toFixed(6);
        document.getElementById('form-longitude').value = position.lng.toFixed(6);
        reverseGeocodeFacilityLocation(position.lat, position.lng);
    });

    // Handle map click
    facilityMap.on('click', function(e) {
        const lat = e.latlng.lat;
        const lng = e.latlng.lng;

        facilityMarker.setLatLng([lat, lng]);
        document.getElementById('form-latitude').value = lat.toFixed(6);
        document.getElementById('form-longitude').value = lng.toFixed(6);
        reverseGeocodeFacilityLocation(lat, lng);
    });
}

function updateMapFromCoordinates(lat, lng) {
    if (!facilityMap || !facilityMarker) return;
    
    if (lat && lng) {
        facilityMap.setView([lat, lng], 15);
        facilityMarker.setLatLng([lat, lng]);
    }
}

// Geocode address using existing API
async function geocodeFacilityAddress() {
    const address = document.getElementById('form-location').value;
    const statusEl = document.getElementById('facility-geocode-status');

    if (!address || address.length < 5) {
        if (statusEl) {
            statusEl.style.display = 'none';
        }
        return;
    }

    if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.style.color = '#64748b';
        statusEl.textContent = 'Looking up coordinates...';
    }

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                'geocode_address': '1',
                'address': address
            })
        });

        const data = await response.json();

        if (data.ok && data.lat && data.lng) {
            document.getElementById('form-latitude').value = data.lat;
            document.getElementById('form-longitude').value = data.lng;
            updateMapFromCoordinates(parseFloat(data.lat), parseFloat(data.lng));

            if (statusEl) {
                statusEl.style.color = '#0d7a43';
                statusEl.textContent = '✓ Coordinates found and map updated';
                setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
            }
        } else {
            if (statusEl) {
                statusEl.style.color = '#b23030';
                statusEl.textContent = data.message || 'Could not find coordinates for this address';
                setTimeout(() => { statusEl.style.display = 'none'; }, 5000);
            }
        }
    } catch (error) {
        console.error('Geocoding error:', error);
        if (statusEl) {
            statusEl.style.color = '#b23030';
            statusEl.textContent = 'Geocoding unavailable. Enter coordinates manually or click on the map.';
            setTimeout(() => { statusEl.style.display = 'none'; }, 5000);
        }
    }
}

// Reverse geocode coordinates to address
async function reverseGeocodeFacilityLocation(lat, lng) {
    const statusEl = document.getElementById('facility-geocode-status');

    if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.style.color = '#64748b';
        statusEl.textContent = 'Looking up address...';
    }

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                'reverse_geocode': '1',
                'lat': lat,
                'lng': lng
            })
        });

        const data = await response.json();

        if (data.ok && data.address) {
            document.getElementById('form-location').value = data.address;

            if (statusEl) {
                statusEl.style.color = '#0d7a43';
                statusEl.textContent = '✓ Address updated from map location';
                setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
            }
        } else {
            if (statusEl) {
                statusEl.style.color = '#b23030';
                statusEl.textContent = data.message || 'Could not find address for this location';
                setTimeout(() => { statusEl.style.display = 'none'; }, 5000);
            }
        }
    } catch (error) {
        console.error('Reverse geocoding error:', error);
        if (statusEl) {
            statusEl.style.color = '#b23030';
            statusEl.textContent = 'Address lookup unavailable. Coordinates saved.';
            setTimeout(() => { statusEl.style.display = 'none'; }, 5000);
        }
    }
}

function updateExtensionFeeFromRate() {
    const rateInput = document.getElementById('form-rate');
    const isFreeCheckbox = document.getElementById('form-is-free');
    const extensionFeeInput = document.getElementById('form-extension-fee');
    
    if (!rateInput || !isFreeCheckbox || !extensionFeeInput) return;
    
    if (isFreeCheckbox.checked) {
        extensionFeeInput.value = '0.00';
        return;
    }
    
    const rateValue = rateInput.value.replace(/[^0-9]/g, '');
    const rate = parseFloat(rateValue) || 0;
    
    if (rate > 0) {
        // Base rate is typically for 4 hours, so hourly rate = base_rate / 4
        const hourlyRate = Math.round((rate / 4) * 100) / 100;
        extensionFeeInput.value = hourlyRate.toFixed(2);
    } else {
        extensionFeeInput.value = '10.00'; // Default fallback
    }
}

// Add event listeners for auto-calculation (works after soft nav too)
(function initFacilityRateListeners() {
    const rateInput = document.getElementById('form-rate');
    const isFreeCheckbox = document.getElementById('form-is-free');

    if (rateInput && rateInput.dataset.frsBound !== '1') {
        rateInput.dataset.frsBound = '1';
        rateInput.addEventListener('input', updateExtensionFeeFromRate);
        rateInput.addEventListener('change', updateExtensionFeeFromRate);
    }

    if (isFreeCheckbox && isFreeCheckbox.dataset.frsBound !== '1') {
        isFreeCheckbox.dataset.frsBound = '1';
        isFreeCheckbox.addEventListener('change', updateExtensionFeeFromRate);
    }
})();

window.openFacilityModal = openFacilityModal;
window.closeFacilityModal = closeFacilityModal;

// Collapsible helper with localStorage persistence
(function() {
    const STORAGE_KEY = 'collapse-state-facility-management';
    let state = {};
    let initialized = false;
    
    try {
        state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    } catch (e) {
        state = {};
    }

    function saveState() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function initCollapsibles() {
        if (initialized) return; // Prevent duplicate initialization
        
        document.querySelectorAll('.collapsible-card .collapsible-header').forEach(header => {
            const targetId = header.getAttribute('data-collapse-target');
            if (!targetId) return;
            
            const body = document.getElementById(targetId);
            if (!body) return;
            const chevron = header.querySelector('.chevron');

            // Remove any existing listeners by cloning
            const newHeader = header.cloneNode(true);
            header.parentNode.replaceChild(newHeader, header);
            const freshHeader = document.querySelector(`[data-collapse-target="${targetId}"]`);
            const freshBody = document.getElementById(targetId);
            const freshChevron = freshHeader.querySelector('.chevron');

            // Apply saved state
            if (state[targetId]) {
                freshBody.classList.add('is-collapsed');
                if (freshChevron) freshChevron.style.transform = 'rotate(-90deg)';
            } else {
                freshBody.classList.remove('is-collapsed');
                if (freshChevron) freshChevron.style.transform = 'rotate(0deg)';
            }

            // Add click handler
            freshHeader.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const currentCollapsed = freshBody.classList.contains('is-collapsed');
                const newCollapsed = !currentCollapsed;
                
                if (newCollapsed) {
                    freshBody.classList.add('is-collapsed');
                } else {
                    freshBody.classList.remove('is-collapsed');
                }
                
                if (freshChevron) {
                    freshChevron.style.transform = newCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
                }
                
                state[targetId] = newCollapsed;
                saveState();
            });
        });
        
        initialized = true;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCollapsibles);
    } else {
        initCollapsibles();
    }
    
    // Fallback initialization
    setTimeout(initCollapsibles, 300);
})();

// Add event listener for location input geocoding
(function() {
    const locationInput = document.getElementById('form-location');
    if (locationInput) {
        let geocodeTimer = null;
        
        locationInput.addEventListener('blur', function() {
            geocodeFacilityAddress();
        });
        
        locationInput.addEventListener('input', function() {
            if (geocodeTimer) clearTimeout(geocodeTimer);
            geocodeTimer = setTimeout(geocodeFacilityAddress, 800);
        });
    }
})();

// Price input UX: integer-only pesos + auto comma formatting.
(function() {
    const form = document.getElementById('facilityForm');
    const rateEl = document.getElementById('form-rate');
    const isFreeCheckbox = document.getElementById('form-is-free');
    if (!form || !rateEl) return;

    window.formatRateInput = function(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (!digits) return '';
        return Number(digits).toLocaleString('en-US');
    };

    rateEl.addEventListener('input', function() {
        rateEl.value = window.formatRateInput(rateEl.value);
    });

    // Handle Free Facility checkbox - disable/enable rate input
    function toggleRateInput() {
        if (isFreeCheckbox && rateEl) {
            if (isFreeCheckbox.checked) {
                rateEl.disabled = true;
                rateEl.style.backgroundColor = '#f1f5f9';
                rateEl.style.color = '#94a3b8';
                rateEl.style.cursor = 'not-allowed';
                if (!rateEl.value) {
                    rateEl.placeholder = 'Free - no rate required';
                }
            } else {
                rateEl.disabled = false;
                rateEl.style.backgroundColor = '';
                rateEl.style.color = '';
                rateEl.style.cursor = '';
                rateEl.placeholder = 'e.g., 2,500';
            }
        }
    }

    // Initialize on load
    if (isFreeCheckbox) {
        isFreeCheckbox.addEventListener('change', toggleRateInput);
        toggleRateInput(); // Apply initial state
    }

    form.addEventListener('submit', function(e) {
        const digits = String(rateEl.value || '').replace(/\D/g, '');
        if (rateEl.value.trim() !== '' && digits === '') {
            e.preventDefault();
            alert('Invalid rate format. Use whole numbers only (e.g., 2500 or 2,500).');
            rateEl.focus();
            return;
        }
        rateEl.value = digits;
    });
})();

// Geocoding for facility address
(function() {
    const base = (typeof window !== 'undefined' && window.APP_BASE_PATH) ? window.APP_BASE_PATH : '';
    const addressEl = document.getElementById('form-location');
    const latEl = document.getElementById('form-latitude');
    const lngEl = document.getElementById('form-longitude');
    const statusEl = document.getElementById('facility-geocode-status');
    if (!addressEl || !latEl || !lngEl) return;

    let geocodeTimer = null;
    function showGeocodeStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.display = msg ? 'block' : 'none';
        statusEl.style.color = isError ? '#c00' : '#0d7a43';
    }

    function fetchGeocode() {
        const addr = (addressEl.value || '').trim();
        if (addr.length < 5) {
            showGeocodeStatus('', false);
            return;
        }
        showGeocodeStatus('Looking up coordinates…', false);
        const form = new URLSearchParams();
        form.append('address', addr);
        fetch(base + '/dashboard/geocode-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form
        })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.lat != null && data.lng != null) {
                    latEl.value = data.lat;
                    lngEl.value = data.lng;
                    showGeocodeStatus('✓ Coordinates updated from address', false);
                    setTimeout(function() { showGeocodeStatus('', false); }, 3000);
                } else {
                    showGeocodeStatus(data.error || 'Could not find coordinates for this address', true);
                }
            })
            .catch(function() {
                showGeocodeStatus('Geocoding unavailable. Enter coordinates manually.', true);
            });
    }

    addressEl.addEventListener('blur', fetchGeocode);
    addressEl.addEventListener('input', function() {
        if (geocodeTimer) clearTimeout(geocodeTimer);
        geocodeTimer = setTimeout(fetchGeocode, 800);
    });
})();

(function() {
    const modal = document.getElementById('facilityQrModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    const titleEl = document.getElementById('facilityQrTitle');
    const subtitleEl = document.getElementById('facilityQrSubtitle');
    const imageEl = document.getElementById('facilityQrImage');
    const urlEl = document.getElementById('facilityQrUrl');
    const printEl = document.getElementById('facilityQrPrintLink');
    const regenIdEl = document.getElementById('facilityQrRegenId');
    const copyBtn = document.getElementById('facilityQrCopyBtn');

    function closeQrModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function openQrModal(btn) {
        const name = btn.getAttribute('data-facility-name') || 'Facility';
        const location = btn.getAttribute('data-facility-location') || '';
        const url = btn.getAttribute('data-qr-url') || '';
        const image = btn.getAttribute('data-qr-image') || '';
        const printUrl = btn.getAttribute('data-print-url') || '#';
        const facilityId = btn.getAttribute('data-facility-id') || '';

        if (titleEl) titleEl.textContent = name + ' — Check-In QR';
        if (subtitleEl) subtitleEl.textContent = location;
        if (imageEl) imageEl.src = image;
        if (urlEl) urlEl.value = url;
        if (printEl) printEl.href = printUrl;
        if (regenIdEl) regenIdEl.value = facilityId;

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    // Delegated on document (not bound per-button) — the facility list is
    // re-rendered via AJAX partial reload on pagination/tab switch
    // (data-frs-partial="facility-list"), which would otherwise leave the
    // new page's buttons with no click handler at all.
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.js-open-qr-modal');
        if (btn) openQrModal(btn);
    });

    // After a QR regenerate (or any facility-list AJAX refresh), if this
    // modal is open, re-read the fresh data-qr-* attributes for the same
    // facility so the shown image/URL reflect the new token.
    document.addEventListener('frs:partial-loaded', function(e) {
        if (e.detail.id !== 'facility-list') return;
        if (modal.getAttribute('aria-hidden') === 'true') return;
        const fid = regenIdEl ? regenIdEl.value : '';
        if (!fid) return;
        const btn = document.querySelector('.js-open-qr-modal[data-facility-id="' + fid + '"]');
        if (btn) openQrModal(btn);
    });

    modal.querySelectorAll('.js-close-qr-modal').forEach(function(el) {
        el.addEventListener('click', closeQrModal);
    });

    if (copyBtn && urlEl) {
        copyBtn.addEventListener('click', function() {
            urlEl.select();
            urlEl.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(urlEl.value).then(function() {
                copyBtn.textContent = 'Copied!';
                setTimeout(function() { copyBtn.textContent = 'Copy URL'; }, 1800);
            }).catch(function() {
                document.execCommand('copy');
                copyBtn.textContent = 'Copied!';
                setTimeout(function() { copyBtn.textContent = 'Copy URL'; }, 1800);
            });
        });
    }
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../../layouts/dashboard_layout.php';
