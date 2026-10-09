<?php
declare(strict_types=1);

namespace FloCMS\Core;

use PDOException;
use RuntimeException;
use Throwable;

/**
 * Thrown by App::db() when the database server cannot be reached or refuses
 * the connection. Extends RuntimeException, so existing catch blocks keep working.
 *
 * The public message stays generic; the underlying PDO message is available
 * through detail() and should only be shown in debug mode.
 */
class DatabaseConnectionException extends RuntimeException
{
    public const REASON_SERVER_UNAVAILABLE = 'server_unavailable';
    public const REASON_ACCESS_DENIED = 'access_denied';
    public const REASON_UNKNOWN_DATABASE = 'unknown_database';
    public const REASON_OTHER = 'other';

    /** MySQL client errors meaning the server could not be reached. */
    private const SERVER_UNAVAILABLE_CODES = [2002, 2003, 2005, 2006, 2013];

    public function __construct(
        string $message,
        private readonly ?int $driverCode = null,
        private readonly string $detail = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $driverCode ?? 0, $previous);
    }

    public static function fromPdo(PDOException $e): self
    {
        return new self(
            'Database connection failed. Check DB config.',
            self::driverCodeOf($e),
            $e->getMessage(),
            $e
        );
    }

    /**
     * The MySQL driver error code of a PDOException (e.g. 1045, 2002), or null.
     *
     * Connection errors leave errorInfo empty and only carry the code in the
     * message ("SQLSTATE[HY000] [1045] Access denied ..."), so both are checked.
     */
    public static function driverCodeOf(PDOException $e): ?int
    {
        $info = $e->errorInfo ?? null;

        if (is_array($info) && isset($info[1]) && is_numeric($info[1])) {
            return (int) $info[1];
        }

        if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
            return (int) $m[1];
        }

        $code = $e->getCode();

        return is_int($code) && $code >= 1000 && $code <= 9999 ? $code : null;
    }

    public function driverCode(): ?int
    {
        return $this->driverCode;
    }

    /**
     * The underlying driver message. Contains host/user names: debug output only.
     */
    public function detail(): string
    {
        return $this->detail;
    }

    public function reason(): string
    {
        return match (true) {
            in_array($this->driverCode, self::SERVER_UNAVAILABLE_CODES, true) => self::REASON_SERVER_UNAVAILABLE,
            in_array($this->driverCode, [1044, 1045, 1698], true) => self::REASON_ACCESS_DENIED,
            $this->driverCode === 1049 => self::REASON_UNKNOWN_DATABASE,
            default => self::REASON_OTHER,
        };
    }

    public function isServerUnavailable(): bool
    {
        return $this->reason() === self::REASON_SERVER_UNAVAILABLE;
    }

    /**
     * A short message that is safe to show to visitors.
     */
    public function friendlyMessage(): string
    {
        return match ($this->reason()) {
            self::REASON_SERVER_UNAVAILABLE => 'Database server is not available right now.',
            self::REASON_ACCESS_DENIED => 'Database access denied (invalid username/password).',
            self::REASON_UNKNOWN_DATABASE => 'Database not found.',
            default => 'Database connection failed.',
        };
    }
}
