<?php
declare(strict_types=1);

namespace FloCMS\Core\Mail;

/**
 * A small SMTP client: implicit TLS ("ssl", port 465) or STARTTLS ("tls",
 * port 587), AUTH PLAIN or LOGIN, one message per connection. Certificates
 * are verified; replies are checked after every command.
 *
 * @internal
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    /** @param array{host: string, port: int, encryption: string, username: string, password: string, timeout: int} $settings */
    public function __construct(private readonly array $settings)
    {
    }

    /** @param array{headers: list<string>, body: string} $mime */
    public function send(Message $message, string $from, array $mime): void
    {
        if ($this->settings['username'] !== '' && $this->settings['encryption'] === 'none' && !in_array($this->settings['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            throw new MailException('Refusing to send the SMTP password without encryption: set MAIL_ENCRYPTION=tls or ssl.');
        }
        try {
            $this->connect();
            $this->expect(220);
            $greeting = $this->ehlo();
            if ($this->settings['encryption'] === 'tls') {
                if (!str_contains($greeting, 'STARTTLS')) {
                    throw new MailException('The SMTP server does not offer STARTTLS: set MAIL_ENCRYPTION=ssl or none.');
                }
                $this->command('STARTTLS', 220);
                if (@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new MailException('Could not start TLS with the SMTP server.');
                }
                $greeting = $this->ehlo();
            }
            if ($this->settings['username'] !== '') {
                $this->authenticate($greeting);
            }
            $this->command('MAIL FROM:<' . $from . '>', 250);
            $this->command('RCPT TO:<' . $message->to . '>', [250, 251]);
            $this->command('DATA', 354);
            $headers = $mime['headers'];
            array_splice($headers, 1, 0, ['To: ' . Message::mailbox($message->to, $message->toName), 'Subject: ' . Message::encodeHeader($message->subject)]);
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $mime['body'];
            // Dot-stuffing: a line that starts with "." gets another one
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $data));
            $this->write($data . "\r\n.\r\n");
            $this->expect(250);
            // The message is accepted: a failing QUIT must not make the caller send it again
            try {
                $this->command('QUIT', 221, quiet: true);
            } catch (MailException) {
            }
        } finally {
            if (is_resource($this->socket)) { fclose($this->socket); }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $host = $this->settings['host'];
        $scheme = $this->settings['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $address = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        $socket = @stream_socket_client($scheme . '://' . $address . ':' . $this->settings['port'], $code, $error, $this->settings['timeout'], STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new MailException('Could not connect to ' . $host . ':' . $this->settings['port'] . ($error !== '' ? ' (' . $error . ')' : '') . '.');
        }
        stream_set_timeout($socket, $this->settings['timeout']);
        $this->socket = $socket;
    }

    private function ehlo(): string
    {
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost')) ?: 'localhost';
        return $this->command('EHLO ' . $name, 250);
    }

    private function authenticate(string $greeting): void
    {
        $user = $this->settings['username'];
        $password = $this->settings['password'];
        if (preg_match('/AUTH[ =][^\r\n]*\bPLAIN\b/i', $greeting) === 1) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $password), 235, secret: true);
            return;
        }
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($user), 334, secret: true);
        $this->command(base64_encode($password), 235, secret: true);
    }

    /** @param int|list<int> $expected */
    private function command(string $line, int|array $expected, bool $secret = false, bool $quiet = false): string
    {
        $this->write($line . "\r\n");
        try {
            return $this->expect($expected);
        } catch (MailException $error) {
            if ($quiet) { return ''; }
            if ($secret) {
                throw new MailException('The SMTP server refused the login (check MAIL_USERNAME and MAIL_PASSWORD).');
            }
            throw $error;
        }
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        for ($sent = 0; $sent < $length;) {
            $written = @fwrite($this->socket, substr($data, $sent));
            if ($written === false || $written === 0) {
                throw new MailException('The SMTP connection was closed while sending.');
            }
            $sent += $written;
        }
    }

    /** @param int|list<int> $expected */
    private function expect(int|array $expected): string
    {
        $reply = '';
        while (true) {
            $line = fgets($this->socket);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new MailException($meta['timed_out'] ? 'The SMTP server did not answer in time.' : 'The SMTP connection was closed.');
            }
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { break; }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            // Without the addresses a reply may repeat, as the message ends up in the error log
            throw new MailException('The SMTP server answered: ' . trim((string) preg_replace('/[^\s<>]+@[^\s<>]+/', '[address]', mb_substr($reply, 0, 300))));
        }
        return $reply;
    }
}
