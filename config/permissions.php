<?php
/**
 * Role-based permission system
 * Provides granular CRUD permission control for each role per module
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/** @var array<string, array<string, array<string, bool>>>|null */
$GLOBALS['_frs_permissions_cache'] = null;

/**
 * Check if a role has a specific permission for a module
 *
 * @param string $role The role to check (e.g., 'Admin', 'Staff', 'Resident')
 * @param string $permissionKey The module/resource key (e.g., 'users', 'facilities')
 * @param string $action The action to check: 'create', 'read', 'update', 'delete'
 * @return bool True if the role has the permission, false otherwise
 */
function frs_has_permission(string $role, string $permissionKey, string $action): bool
{
    // Admin always has full access (fallback)
    if ($role === 'Admin') {
        return true;
    }

    // Load permissions if not cached
    if ($GLOBALS['_frs_permissions_cache'] === null) {
        frs_load_permissions();
    }

    // Check if permission exists
    $permissions = $GLOBALS['_frs_permissions_cache'][$role][$permissionKey] ?? null;
    if ($permissions === null) {
        return false;
    }

    // Check specific action
    return (bool)($permissions[$action] ?? false);
}

/**
 * Check if a role can create resources for a module
 */
function frs_can_create(string $role, string $permissionKey): bool
{
    return frs_has_permission($role, $permissionKey, 'create');
}

/**
 * Check if a role can read/view resources for a module
 */
function frs_can_read(string $role, string $permissionKey): bool
{
    return frs_has_permission($role, $permissionKey, 'read');
}

/**
 * Check if a role can update/edit resources for a module
 */
function frs_can_update(string $role, string $permissionKey): bool
{
    return frs_has_permission($role, $permissionKey, 'update');
}

/**
 * Check if a role can delete resources for a module
 */
function frs_can_delete(string $role, string $permissionKey): bool
{
    return frs_has_permission($role, $permissionKey, 'delete');
}

/**
 * Load all permissions from database into cache
 */
function frs_load_permissions(): void
{
    $pdo = db();
    
    try {
        $stmt = $pdo->query('SELECT role, permission_key, can_create, can_read, can_update, can_delete FROM role_permissions');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $cache = [];
        foreach ($rows as $row) {
            $role = (string)$row['role'];
            $key = (string)$row['permission_key'];
            
            if (!isset($cache[$role])) {
                $cache[$role] = [];
            }
            
            $cache[$role][$key] = [
                'create' => (bool)$row['can_create'],
                'read' => (bool)$row['can_read'],
                'update' => (bool)$row['can_update'],
                'delete' => (bool)$row['can_delete'],
            ];
        }
        
        $GLOBALS['_frs_permissions_cache'] = $cache;
    } catch (Throwable $e) {
        // If table doesn't exist yet, use default permissions
        $GLOBALS['_frs_permissions_cache'] = frs_get_default_permissions();
    }
}

/**
 * Get default permissions (fallback if table doesn't exist)
 */
function frs_get_default_permissions(): array
{
    return [
        'Admin' => [
            'users' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'facilities' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'reservations' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'reports' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'settings' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'announcements' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'blackout_dates' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            'audit_trail' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
        ],
        'Staff' => [
            'users' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
            'facilities' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
            'reservations' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
            'reports' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            'settings' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
            'announcements' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
            'blackout_dates' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
            'audit_trail' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
        ],
        'Resident' => [
            'users' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
            'facilities' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            'reservations' => ['create' => true, 'read' => true, 'update' => true, 'delete' => false],
            'reports' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
            'settings' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
            'announcements' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            'blackout_dates' => ['create' => false, 'read' => true, 'update' => false, 'delete' => false],
            'audit_trail' => ['create' => false, 'read' => false, 'update' => false, 'delete' => false],
        ],
    ];
}

/**
 * Clear permissions cache (call after updating permissions)
 */
function frs_clear_permissions_cache(): void
{
    $GLOBALS['_frs_permissions_cache'] = null;
}

/**
 * Update a role's permission for a module
 *
 * @param string $role The role to update
 * @param string $permissionKey The module/resource key
 * @param array<string, bool> $permissions Array of permissions: ['create' => bool, 'read' => bool, 'update' => bool, 'delete' => bool]
 * @return array{ok: bool, message: string}
 */
function frs_update_permission(string $role, string $permissionKey, array $permissions): array
{
    $pdo = db();
    
    // Check if table exists
    try {
        $pdo->query("SELECT 1 FROM role_permissions LIMIT 1");
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Role permissions table not installed. Run migration_add_role_permissions.sql.'];
    }
    
    $canCreate = (bool)($permissions['create'] ?? false);
    $canRead = (bool)($permissions['read'] ?? false);
    $canUpdate = (bool)($permissions['update'] ?? false);
    $canDelete = (bool)($permissions['delete'] ?? false);
    
    $stmt = $pdo->prepare(
        'INSERT INTO role_permissions (role, permission_key, can_create, can_read, can_update, can_delete)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
         can_create = VALUES(can_create),
         can_read = VALUES(can_read),
         can_update = VALUES(can_update),
         can_delete = VALUES(can_delete)'
    );
    
    $stmt->execute([$role, $permissionKey, $canCreate ? 1 : 0, $canRead ? 1 : 0, $canUpdate ? 1 : 0, $canDelete ? 1 : 0]);
    
    frs_clear_permissions_cache();
    
    return ['ok' => true, 'message' => 'Permissions updated successfully.'];
}

/**
 * Get all permissions for a role
 *
 * @param string $role The role to get permissions for
 * @return array<string, array<string, bool>>
 */
function frs_get_role_permissions(string $role): array
{
    if ($GLOBALS['_frs_permissions_cache'] === null) {
        frs_load_permissions();
    }
    
    return $GLOBALS['_frs_permissions_cache'][$role] ?? [];
}

/**
 * Get all available permission keys (modules)
 *
 * @return list<string>
 */
function frs_get_permission_keys(): array
{
    if ($GLOBALS['_frs_permissions_cache'] === null) {
        frs_load_permissions();
    }
    
    $keys = [];
    foreach ($GLOBALS['_frs_permissions_cache'] as $rolePermissions) {
        foreach (array_keys($rolePermissions) as $key) {
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
    }
    
    sort($keys);
    return $keys;
}

/**
 * Get all available roles
 *
 * @return list<string>
 */
function frs_get_roles(): array
{
    if ($GLOBALS['_frs_permissions_cache'] === null) {
        frs_load_permissions();
    }
    
    $roles = array_keys($GLOBALS['_frs_permissions_cache']);
    sort($roles);
    return $roles;
}
