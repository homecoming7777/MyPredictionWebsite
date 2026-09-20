<?php

/**
 * Central admin authentication helper.
 *
 * Admin is determined by users.role = 'admin' (or legacy super-admin values).
 * User id 1 is always treated as admin as a safe bootstrap fallback.
 */

if (!function_exists('adminBootstrapUserId')) {

    function adminBootstrapUserId(): int
    {
        return 1;
    }

    function adminRoleColumnExists(mysqli $conn): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");

        $cached = ($result && $result->num_rows > 0);

        if ($result) {
            $result->free();
        }

        return $cached;
    }

    function adminAllowedRoles(): array
    {
        return ['admin', 'super-admin', 'super_admin'];
    }

    function adminNormalizeRole(?string $role): string
    {
        $role = strtolower(trim((string)$role));

        if ($role === '') {
            return 'user';
        }

        return $role;
    }

    function adminIsAllowedRole(string $role): bool
    {
        return in_array(
            adminNormalizeRole($role),
            adminAllowedRoles(),
            true
        );
    }

    /**
     * Reads role from database. Returns 'admin' for bootstrap user id 1
     * even before the role column migration is applied.
     */
    function adminGetUserRole(mysqli $conn, int $userId): string
    {
        if ($userId === adminBootstrapUserId()) {
            return 'admin';
        }

        if (!adminRoleColumnExists($conn)) {
            return 'user';
        }

        $stmt = $conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');

        if (!$stmt) {
            return 'user';
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return adminNormalizeRole($row['role'] ?? 'user');
    }

    function adminIsAdmin(mysqli $conn, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($userId === adminBootstrapUserId()) {
            return true;
        }

        return adminIsAllowedRole(adminGetUserRole($conn, $userId));
    }

    /**
     * Keeps $_SESSION['role'] aligned with the database role.
     */
    function adminSyncSessionRole(mysqli $conn, int $userId): void
    {
        if ($userId <= 0) {
            unset($_SESSION['role']);
            return;
        }

        $_SESSION['role'] = adminGetUserRole($conn, $userId);
    }

    function adminRequireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php');
            exit();
        }
    }

    /**
     * Blocks non-admin users. Always validates against the database.
     */
    function adminRequireAdmin(mysqli $conn, bool $redirectOnDeny = true): void
    {
        adminRequireLogin();

        $userId = (int)$_SESSION['user_id'];

        if (adminIsAdmin($conn, $userId)) {
            adminSyncSessionRole($conn, $userId);
            return;
        }

        http_response_code(403);

        if ($redirectOnDeny) {
            header('Location: dashboard.php?error=access_denied');
            exit();
        }

        exit('Access denied.');
    }
}
