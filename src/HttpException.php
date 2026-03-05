<?php
namespace FloCMS\Core;

use Exception;
class HttpException extends Exception
{
    public int $status;

    public function __construct(int $status, string $message = '')
    {
        parent::__construct($message ?: "HTTP $status");
        $this->status = $status;
    }
}