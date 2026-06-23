<?php
/**
 * Admin Audit Logging System
 * Tracks all admin actions for security & compliance
 */
declare(strict_types=1);

/**
 * Log administrative action
 * 
 * @param PDO $pdo Database connection
 * @param string $action Action name (e.g., 'impersonate', 'delete_tenant')
 * @param mixed $details Details about the action
 * @param string $username Admin username (from session)
 * @return void
 */
function logAdminAction(
    PDO $pdo,
    string $action,
    mixed $details = [],
    string $username = 'unknown'
): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Ensure details is array
        if (!is_array($details)) {
            $details = ['value' => $details];
        }
        
        // Limit details to prevent huge logs
        $detailsJson = json_encode(array_slice($details, 0, 10), JSON_UNESCAPED_UNICODE);
        if (strlen($detailsJson) > 1000) {
            $detailsJson = substr($detailsJson, 0, 997) . '...';
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO admin_audit_logs 
            (admin_username, action, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $username,
            $action,
            $detailsJson,
            $ip,
            $userAgent
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to log admin action: " . $e->getMessage());
        // Don't fail the operation just because logging failed
    }
}

/**
 * Get admin audit log with filtering
 * 
 * @param PDO $pdo Database connection
 * @param array $filters Filter options
 * @return array Audit log entries
 */
function getAdminAuditLog(PDO $pdo, array $filters = []): array {
    try {
        $query = "SELECT * FROM admin_audit_logs WHERE 1=1";
        $params = [];
        
        // Filter by action
        if (!empty($filters['action'])) {
            $query .= " AND action = ?";
            $params[] = $filters['action'];
        }
        
        // Filter by username
        if (!empty($filters['username'])) {
            $query .= " AND admin_username = ?";
            $params[] = $filters['username'];
        }
        
        // Filter by date range
        if (!empty($filters['from_date'])) {
            $query .= " AND created_at >= ?";
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $query .= " AND created_at <= ?";
            $params[] = $filters['to_date'];
        }
        
        // Order and limit
        $query .= " ORDER BY created_at DESC LIMIT " . ((int)($filters['limit'] ?? 100));
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Failed to get audit log: " . $e->getMessage());
        return [];
    }
}

/**
 * Common admin actions to log
 */
const ADMIN_ACTIONS = [
    'impersonate_tenant' => 'Admin viewing as tenant',
    'exit_impersonate' => 'Admin exited impersonate mode',
    'delete_tenant' => 'Deleted tenant account',
    'update_tenant' => 'Updated tenant settings',
    'create_tenant' => 'Created new tenant',
    'approve_upgrade' => 'Approved plan upgrade',
    'reject_upgrade' => 'Rejected plan upgrade',
    'reset_password' => 'Reset user password',
    'toggle_tenant' => 'Toggled tenant active/inactive',
    'create_plan' => 'Created new pricing plan',
    'update_plan' => 'Updated pricing plan',
    'access_report' => 'Accessed admin report',
    'export_data' => 'Exported tenant data',
    'send_announcement' => 'Sent announcement to tenants',
    'edit_landing' => 'Edited landing page',
];

?>
