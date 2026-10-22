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

    /** Session key of a held sign-in (2.7+). */
    public const HELD_KEY = 'auth_held';

    /** Session key that remembers the holds were asked for this sign-in (2.7+). */
    public const HOLDS_ASKED_KEY = 'auth_holds_asked';

    /** Session keys a hold parks by default ('auth.hold_keys'): what the skeleton sets at sign-in. */
    private const HOLD_KEYS = ['isloggedin', 'admin_access', 'role', 'user_id', 'username', 'email', 'fullname'];

    /** @var list<callable(array<string, mixed>): ?string> */
    private static array $sessionHolds = [];

    /**
     * Hold a freshly signed-in session at one admin page until a step is done
     * (2.7+), for example a two-factor code, accepting new terms or changing a
     * temporary password.
     *
     * The callable gets the user row (from 'auth.user_loader') and returns null
     * (no hold) or an admin path such as 'two-factor' or 'account/terms'. The
     * holds are asked once per sign-in, on its first request on any route.
     * While a session is held, the sign-in keys ('auth.hold_keys') are parked:
     * the site and the admin gate see a visitor who is not signed in, and every
     * admin request goes to /admin/<path>, except guest admin actions and
     * users/admin_logout. The path's action must be opened with
     * App::allowGuestAdminActions(). The step calls releaseHold() when done, or
     * cancelHold() to sign out.
     *
     * A hold that throws is logged and ends the session (it never lets a
     * session through). A held session older than 'auth.hold_lifetime' seconds
     * (default 600) ends; the person starts again at the login page.
     *
     * @param callable(array<string, mixed> $user): ?string $hold
     */
    public static function holdSessionsUsing(callable $hold): void
    {
        self::$sessionHolds[] = $hold;
    }

    /** Remove every hold added with holdSessionsUsing(). */
    public static function clearSessionHolds(): void
    {
        self::$sessionHolds = [];
    }

    /** The admin path this session is held at, or null. */
    public static function heldPath(): ?string
    {
        $held = Session::get(self::HELD_KEY);

        return is_array($held) && is_string($held['path'] ?? null) ? $held['path'] : null;
    }

    /** The id of the user whose sign-in is held, or null. */
    public static function heldUserId(): ?int
    {
        $held = Session::get(self::HELD_KEY);
        $id = is_array($held) ? ($held['saved']['user_id'] ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Ask the holds for a freshly signed-in session (App::run() calls this on
     * every request). Returns the path the session is held at, or null.
     */
    public static function applyHolds(): ?string
    {
        $held = Session::get(self::HELD_KEY);

        if (is_array($held)) {
            if (Session::get('isloggedin')) {
                // Someone signed in again in this session: the old hold no longer applies.
                Session::delete(self::HELD_KEY);
            } elseif (time() - (int) ($held['since'] ?? 0) > self::holdLifetime()) {
                self::endSession();
                Session::setFlash(Lang::get('auth.hold_expired', 'Your sign-in took too long. Please log in again.'), 'warning');
                return null;
            } else {
                return self::heldPath();
            }
        }

        if (self::$sessionHolds === [] || !Session::get('isloggedin')) {
            return null;
        }

        $marker = self::signInMarker();
        if (Session::get(self::HOLDS_ASKED_KEY) === $marker) {
            return null;
        }

        $user = self::loadSessionUser();
        if ($user === null) {
            // Nobody to ask about: refresh() ends the session on the next admin request.
            return null;
        }

        return self::askHolds($user, 0);
    }

    /**
     * The step is done: the parked sign-in comes back with a new session id,
     * and the next hold (if any) is asked. Returns the path of the next hold,
     * or null when the session is fully signed in (or ended because the user
     * is no longer active).
     */
    public static function releaseHold(): ?string
    {
        $held = Session::get(self::HELD_KEY);
        if (!is_array($held)) {
            return null;
        }

        Session::delete(self::HELD_KEY);
        foreach ((array) ($held['saved'] ?? []) as $key => $value) {
            Session::set((string) $key, $value);
        }

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        // Status and role as they are now (the user may have been suspended meanwhile).
        Session::delete('auth_checked_at');
        if (!self::refresh()) {
            return null;
        }

        $user = self::loadSessionUser();
        if ($user === null) {
            return null;
        }

        return self::askHolds($user, (int) ($held['hold'] ?? -1) + 1);
    }

    /** Give up the held sign-in: the session ends (signed out). */
    public static function cancelHold(): void
    {
        if (Session::get(self::HELD_KEY) !== null) {
            self::endSession();
        }
    }

    /** @internal App::run() logs a held path whose action is not a guest admin action. */
    public static function warnHeldPathClosed(string $path): void
    {
        self::warn('The session was held at admin/' . $path . ', which is not opened with App::allowGuestAdminActions(); the session was ended.');
    }

    /** @param array<string, mixed> $user */
    private static function askHolds(array $user, int $from): ?string
    {
        foreach (array_slice(self::$sessionHolds, $from, null, true) as $index => $hold) {
            try {
                $path = $hold($user);
            } catch (\Throwable $error) {
                self::warn('A session hold failed and ended the session: ' . $error->getMessage());
                self::endSession();
                return null;
            }

            if ($path === null) {
                continue;
            }

            if (!is_string($path) || preg_match('#^[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)?$#', $path) !== 1) {
                self::warn('A session hold returned an invalid path; the session was ended.');
                self::endSession();
                return null;
            }

            self::park($path, (int) $index);
            return $path;
        }

        Session::set(self::HOLDS_ASKED_KEY, self::signInMarker());

        return null;
    }

    private static function park(string $path, int $index): void
    {
        $keys = Config::get('auth.hold_keys', self::HOLD_KEYS);
        $keys = is_array($keys) ? array_values(array_filter($keys, 'is_string')) : self::HOLD_KEYS;
        if (!in_array('isloggedin', $keys, true)) {
            $keys[] = 'isloggedin';
        }
        if (!in_array('user_id', $keys, true)) {
            $keys[] = 'user_id';
        }

        $saved = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $_SESSION ?? [])) {
                $saved[$key] = $_SESSION[$key];
                unset($_SESSION[$key]);
            }
        }

        Session::set('isloggedin', false);
        Session::set('admin_access', false);
        Session::set(self::HELD_KEY, ['path' => $path, 'hold' => $index, 'since' => time(), 'saved' => $saved]);
    }

    /** @return array<string, mixed>|null */
    private static function loadSessionUser(): ?array
    {
        $loader = Config::get('auth.user_loader');
        $id = Session::get('user_id');

        if (!is_callable($loader) || $id === null || !is_numeric($id)) {
            return null;
        }

        $user = $loader((int) $id);
        $user = is_object($user) ? (array) $user : $user;

        return is_array($user) ? $user : null;
    }

    /** Identifies one sign-in: the skeleton gives the session a new id at sign-in. */
    private static function signInMarker(): string
    {
        $id = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';

        return hash('sha256', $id . '|' . (string) Session::get('user_id'));
    }

    private static function holdLifetime(): int
    {
        $seconds = Config::get('auth.hold_lifetime', 600);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : 600;
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
