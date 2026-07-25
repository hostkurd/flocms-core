<?php
namespace FloCMS\Core;

use Psr\Log\AbstractLogger;

final class Logger extends AbstractLogger
{
    public function __construct(private string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }

    public function log($level, $message, array $context = []): void
    {
        $ts = date('Y-m-d H:i:s');
        $ctx = $context
            ? json_encode(
                $context,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            )
            : '';
        $line = sprintf(
            "[%s] %s: %s%s\n",
            $ts,
            (string) $level,
            (string) $message,
            $ctx !== '' ? ' ' . $ctx : ''
        );

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
