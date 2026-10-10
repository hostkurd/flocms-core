<?php
declare(strict_types=1);

namespace FloCMS\Core;

/**
 * Role-based permission checks for the logged-in user.
 *
 * The role → permission map is read from Config::get('permissions'):
 *
 *     Config::set('permissions', [
 *         0 => [],
 *         1 => ['content.*'],
 *         2 => ['content.*', 'users.manage', 'users.assign_role'],
 *         3 => ['*'],
 *     ]);
 *
 * Entries may be an exact permission ('users.manage'), a prefix wildcard
 * ('users.*') or the global wildcard ('*').
 *
 * When no map is configured, every check falls back to the legacy
 * 'admin_access' session flag, so sites that have not opted in keep
 * their existing behaviour.
 *
 * Session freshness (2.2+): with a user loader configured, App::run() calls
 * refresh() on every admin request and reloads role/status from the database:
 *
 *     Config::set('auth.user_loader', fn (int $id) => (new UsersModel())->getByID($id));
 *
 * The loader returns the user row (array or object with 'role' and 'status')
 * or null/false when the user no longer exists.
 */
final class Auth
{
    /**
     * The current user's role, or null when not logged in / no role set.
     */
    public static function role(): ?int
    {
        $role = Session::get('role');

        if ($role === null || $role === '' || !is_numeric($role)) {
            return null;
        }

        return (int) $role;
    }

    public static function can(string $permission): bool
    {
        $map = Config::get('permissions');

        if (!is_array($map)) {
            return (bool) Session::get('admin_access');
        }

        $role = self::role();

        if ($role === null || !isset($map[$role]) || !is_array($map[$role])) {
            return false;
        }

        foreach ($map[$role] as $granted) {
            if (self::matches((string) $granted, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws HttpException 403 when the current user lacks the permission.
     */
    public static function authorize(string $permission): void
    {
        if (!self::can($permission)) {
            throw new HttpException(403);
        }
    }

    /**
     * Whether the current user may give $role to a user (never above their own).
     */
    public static function canAssignRole(int $role): bool
    {
        $own = self::role();

        return $own !== null
            && self::can('users.assign_role')
            && $role <= $own;
    }

    /**
     * Whether the current user may edit, delete or suspend a user holding $targetRole.
     *
     * Only users of a strictly lower role can be managed, unless the current
     * user holds 'users.manage_any'.
     */
    public static function canManageUser(int $targetRole): bool
    {
        $own = self::role();

        if ($own === null || !self::can('users.manage')) {
            return false;
        }

        return $targetRole < $own || self::can('users.manage_any');
    }

    /**
     * Reload the logged-in user's role and status (see the class comment).
     *
     * Returns false when the session was ended because the user no longer
     * exists, is not active, or the session has no 'user_id' (logged in before
     * the loader was configured). Returns true when the session is still valid,
     * nobody is logged in, or no loader is configured.
     *
     * Config:
     *  - 'auth.user_loader'      callable(int $id): array|object|null|false
     *  - 'auth.active_status'    status value of active users (default 1)
     *  - 'auth.refresh_interval' seconds between reloads (default 0 = every call)
     *  - 'admin_access_roles'    roles that keep 'admin_access' (when set)
     */
    public static function refresh(): bool
    {
        $loader = Config::get('auth.user_loader');

        if (!is_callable($loader) || !Session::get('isloggedin')) {
            return true;
        }

        $id = Session::get('user_id');

        if ($id === null || $id === '' || !is_numeric($id)) {
            self::endSession();
            return false;
        }

        $interval = max(0, (int) Config::get('auth.refresh_interval', 0));
        $checkedAt = (int) Session::get('auth_checked_at');

        if ($interval > 0 && $checkedAt > 0 && time() - $checkedAt < $interval) {
            return true;
        }

        $user = $loader((int) $id);
        $user = is_object($user) ? (array) $user : $user;

        if (!is_array($user)) {
            self::endSession();
            return false;
        }

        $activeStatus = Config::get('auth.active_status', 1);

        if ((string) ($user['status'] ?? '') !== (string) $activeStatus) {
            self::endSession();
            return false;
        }

        $role = $user['role'] ?? null;
        Session::set('role', $role);

        $adminRoles = Config::get('admin_access_roles');

        if (is_array($adminRoles)) {
            Session::set('admin_access', in_array(
                (string) $role,
                array_map('strval', $adminRoles),
                true
            ));
        }

        Session::set('auth_checked_at', time());

        return true;
    }

    /**
     * Log the current user out: clear the session data and issue a new
     * session id, so a flash message can still be shown afterwards.
     */
    public static function endSession(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    private static function matches(string $granted, string $permission): bool
    {
        if ($granted === '*' || $granted === $permission) {
            return true;
        }

        if (str_ends_with($granted, '.*')) {
            return str_starts_with($permission, substr($granted, 0, -1));
        }

        return false;
    }
}
