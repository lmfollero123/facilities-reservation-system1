<?php
/**
 * Audit Trail Helper Functions
 * 
 * Logs all system actions for transparency and compliance.
 */

/**
 * Log an audit event
 * 
 * @param string $action Action description (e.g., "Approved reservation", "Updated facility")
 * @param string $module Module name (e.g., "Reservations", "Facility Management")
 * @param string|null $details Additional details (e.g., "RES-2025-0004 – Community Convention Hall")
 * @param int|null $userId User ID who performed the action (defaults to current session user)
 * @return bool Success status
 */
function logAudit($action, $module, $details = null, $userId = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get user ID from session if not provided
    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    // Get IP address and user agent
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    try {
        require_once __DIR__ . '/database.php';
        $pdo = db();
        
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, module, details, ip_address, user_agent) 
             VALUES (:user_id, :action, :module, :details, :ip_address, :user_agent)'
        );
        
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'details' => $details,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
        
        return true;
    } catch (Throwable $e) {
        // Silently fail to prevent breaking the main action
        // In production, you might want to log this error
        error_log('Audit logging failed: ' . $e->getMessage());
        return false;
    }
}

//ddddd