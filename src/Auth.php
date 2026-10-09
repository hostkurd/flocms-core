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
