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
 *
 * Roles from somewhere else (2.4+): a package or the site can supply role
 * definitions at runtime, for example from a database table:
 *
 *     Auth::loadRolesUsing(fn (): ?array => [
 *         'roles'              => [0 => 'User', 1 => 'Editor', 4 => 'Author'],
 *         'permissions'        => [1 => ['content.*'], 4 => ['content.articles.*']],
 *         'admin_access_roles' => ['1', '4'],
 *         'levels'             => [4 => 1],
 *     ]);
 *
 * The loader runs once per request, on the first check that needs it. Each key
 * it returns replaces that Config value; keys it leaves out, or a null result,
 * keep the Config values, so sites without a loader behave exactly as before.
 *
 * Levels rank roles for canAssignRole() and canManageUser(). Without a
 * 'levels' entry a role's level is its number, as in earlier versions.
 */
final class Auth
{
    /** Keys a roles loader may return. */
    private const ROLE_KEYS = ['roles', 'permissions', 'admin_access_roles', 'levels'];

    /** @var callable|null */
    private static $rolesLoader = null;

    /** @var array<string, array<mixed>>|null definitions from the loader, once loaded */
    private static ?array $loadedRoles = null;

    private static bool $loadingRoles = false;

    /**
     * Supply role definitions at runtime (2.4+). See the class comment.
     *
     * @param callable(): (array<string, mixed>|null) $loader
     */
    public static function loadRolesUsing(callable $loader): void
    {
        self::$rolesLoader = $loader;
        self::$loadedRoles = null;
    }

    /** Remove the roles loader: Config is the only source again. */
    public static function clearRolesLoader(): void
    {
        self::$rolesLoader = null;
        self::$loadedRoles = null;
    }

    /** @var list<callable(array<string, mixed>): bool> */
    private static array $sessionChecks = [];

    /**
     * Add a check that refresh() runs on the freshly loaded user (2.5+), after
     * the status check. Returning false ends the session, as for a suspended
     * user; a check that throws is logged and ends the session too. Use it to
     * end other sessions after a password change, for example by keeping a
     * fingerprint of the password hash in the session.
     *
     * Runs only when Config 'auth.user_loader' is set, and with
     * 'auth.refresh_interval' above 0 only when the user is reloaded.
     *
     * @param callable(array<string, mixed> $user): bool $check
     */
    public static function checkSessionsUsing(callable $check): void
    {
        self::$sessionChecks[] = $check;
    }

    /** Remove every check added with checkSessionsUsing(). */
    public static function clearSessionChecks(): void
    {
        self::$sessionChecks = [];
    }

    /** Ask the loader again on the next check (after the roles were changed in this request). */
    public static function forgetLoadedRoles(): void
    {
        self::$loadedRoles = null;
    }

    /**
     * Role number => display name: the loader's 'roles', else Config 'roles'.
     *
     * @return array<int, string>
     */
    public static function roles(): array
    {
        $roles = self::setting('roles');
        if (!is_array($roles)) {
            return [];
        }

        $out = [];
        foreach ($roles as $role => $label) {
            if (is_numeric($role) && is_scalar($label)) {
                $out[(int) $role] = (string) $label;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Role number => permission patterns: the loader's 'permissions', else
     * Config 'permissions'. Null when neither is set (legacy admin_access mode).
     *
     * @return array<int, list<string>>|null
     */
    public static function permissionMap(): ?array
    {
        $map = self::setting('permissions');
        if (!is_array($map)) {
            return null;
        }

        $out = [];
        foreach ($map as $role => $patterns) {
            if (is_numeric($role) && is_array($patterns)) {
                $out[(int) $role] = array_values(array_filter($patterns, 'is_string'));
            }
        }

        return $out;
    }

    /**
     * The rank of a role: its 'levels' entry, else the role number itself.
     */
    public static function level(int $role): int
    {
        $levels = self::setting('levels');

        if (is_array($levels) && isset($levels[$role]) && is_numeric($levels[$role])) {
            return (int) $levels[$role];
        }

        return $role;
    }

    /**
     * The roles that may open the admin area, as strings: the loader's
     * 'admin_access_roles', else Config 'admin_access_roles'. Null when neither is set.
     *
     * @return list<string>|null
     */
    public static function adminAccessRoles(): ?array
    {
        $roles = self::setting('admin_access_roles');
        if (!is_array($roles)) {
            return null;
        }

        return array_values(array_map('strval', array_filter($roles, 'is_scalar')));
    }

    /**
     * Whether $role may open the admin area; null when no admin access roles are
     * configured (the caller decides, e.g. with its own list).
     */
    public static function hasAdminAccess(int|string|null $role): ?bool
    {
        $roles = self::adminAccessRoles();
        if ($roles === null) {
            return null;
        }

        return $role !== null && $role !== '' && in_array((string) $role, $roles, true);
    }
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
        $map = self::permissionMap();

        if ($map === null) {
            return (bool) Session::get('admin_access');
        }

        $role = self::role();

        if ($role === null || !isset($map[$role])) {
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
     * Whether the current user may give $role to a user (never above their own level).
     */
    public static function canAssignRole(int $role): bool
    {
        $own = self::role();

        return $own !== null
            && self::can('users.assign_role')
            && self::level($role) <= self::level($own);
    }

    /**
     * Whether the current user may edit, delete or suspend a user holding $targetRole.
     *
     * Only users of a strictly lower level can be managed, unless the current
     * user holds 'users.manage_any'.
     */
    public static function canManageUser(int $targetRole): bool
    {
        $own = self::role();

        if ($own === null || !self::can('users.manage')) {
            return false;
        }

        return self::level($targetRole) < self::level($own) || self::can('users.manage_any');
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
     *  - 'admin_access_roles'    roles that keep 'admin_access' (when set; a roles
     *                             loader's 'admin_access_roles' wins, 2.4+)
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

        foreach (self::$sessionChecks as $check) {
            try {
                $valid = $check($user) === true;
            } catch (\Throwable $error) {
                self::warn('A session check failed and ended the session: ' . $error->getMessage());
                $valid = false;
            }

            if (!$valid) {
                self::endSession();
                return false;
            }
        }

        $role = $user['role'] ?? null;
        Session::set('role', $role);

        $adminAccess = self::hasAdminAccess(is_int($role) || is_string($role) ? $role : (is_scalar($role) ? (string) $role : null));

        if ($adminAccess !== null) {
            Session::set('admin_access', $adminAccess);
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

    /** A role setting: the loader's value when it returned this key, else Config's. */
    private static function setting(string $key): mixed
    {
        $loaded = self::loaded();

        return array_key_exists($key, $loaded) ? $loaded[$key] : Config::get($key);
    }

    /**
     * The loader's definitions for this request (empty without a loader).
     * A loader that throws or returns something else than an array or null is
     * logged once and ignored, so a broken store can never lock everybody out
     * of the Config roles. Checks made by the loader itself see Config only.
     *
     * @return array<string, array<mixed>>
     */
    private static function loaded(): array
    {
        if (self::$loadedRoles !== null) {
            return self::$loadedRoles;
        }
        if (self::$rolesLoader === null || self::$loadingRoles) {
            return [];
        }

        self::$loadingRoles = true;
        $definitions = [];

        try {
            $result = (self::$rolesLoader)();

            if (is_array($result)) {
                foreach (self::ROLE_KEYS as $key) {
                    if (array_key_exists($key, $result) && is_array($result[$key])) {
                        $definitions[$key] = $result[$key];
                    }
                }
            } elseif ($result !== null) {
                self::warn('The roles loader returned ' . get_debug_type($result) . ' instead of an array or null; using the Config roles.');
            }
        } catch (\Throwable $e) {
            self::warn('The roles loader failed (' . $e->getMessage() . '); using the Config roles.');
        } finally {
            self::$loadingRoles = false;
        }

        return self::$loadedRoles = $definitions;
    }

    private static function warn(string $message): void
    {
        try {
            if (defined('ROOT')) {
                App::logger()->warning($message);
                return;
            }
        } catch (\Throwable) {
            // fall through to error_log
        }

        error_log('[FloCMS] ' . $message);
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
