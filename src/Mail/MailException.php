<?php
declare(strict_types=1);

namespace FloCMS\Core\Mail;

/** A message could not be handed to the mail server. The message never contains the SMTP password. */
final class MailException extends \RuntimeException
{
}
