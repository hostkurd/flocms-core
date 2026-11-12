<?php
declare(strict_types=1);

namespace FloCMS\Core\Mail;

/**
 * One email: a plain-text body and, optionally, an HTML body of the same text.
 * Addresses and the subject are checked for line breaks, so a value typed by
 * a visitor can never add headers.
 */
final class Message
{
    public const HEADERS = ['Reply-To', 'List-Unsubscribe', 'List-Unsubscribe-Post', 'Precedence'];

    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $from = null,
        public readonly ?string $fromName = null,
        public readonly ?string $toName = null,
        /** @var array<string, string> extra headers, only these: Reply-To, List-Unsubscribe, List-Unsubscribe-Post, Precedence */
        public readonly array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            if (!in_array($name, self::HEADERS, true) || !is_string($value)) { throw new \InvalidArgumentException('Unsupported email header.'); }
        }
        foreach ([$to, $subject, (string) $from, (string) $fromName, (string) $toName, ...array_values($headers)] as $value) {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \InvalidArgumentException('Line breaks are not allowed in email headers.');
            }
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid recipient address.');
        }
        if ($from !== null && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid sender address.');
        }
    }

    /** "Name <address>" with the name encoded for headers, or just the address. */
    public static function mailbox(string $address, ?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') { return $address; }
        // Always a quoted string (or encoded words), so a name with commas,
        // brackets or "@" can never add an address
        $encoded = preg_match('/^[\x20-\x7E]*$/', $name) === 1
            ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"'
            : self::encodeHeader($name);
        return $encoded . ' <' . $address . '>';
    }

    /** RFC 2047 encoding for a header value with non-ASCII characters. */
    public static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) { return $value; }
        $words = [];
        foreach (mb_str_split($value, 18, 'UTF-8') as $chunk) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    /**
     * The headers (without To and Subject) and the body, as PHP's mail() and an
     * SMTP DATA command need them.
     *
     * @return array{headers: list<string>, body: string}
     */
    public function mime(string $from, string $fromName, string $domain): array
    {
        $headers = [
            'From: ' . self::mailbox($from, $fromName),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        foreach ($this->headers as $name => $value) { $headers[] = $name . ': ' . $value; }
        $text = self::encodeBody($this->text);
        if ($this->html === null || $this->html === '') {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return ['headers' => $headers, 'body' => $text];
        }
        $boundary = 'flo-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $text . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . self::encodeBody($this->html) . "\r\n"
            . '--' . $boundary . "--\r\n";
        return ['headers' => $headers, 'body' => $body];
    }

    private static function encodeBody(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        return rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $content)), 76, "\r\n"));
    }
}
