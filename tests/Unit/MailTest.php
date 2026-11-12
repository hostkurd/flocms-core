<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Config;
use FloCMS\Core\Env;
use FloCMS\Core\Mail\MailException;
use FloCMS\Core\Mail\Mailer;
use FloCMS\Core\Mail\Message;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Core mail (2.10), moved from flocms-admin.
 */
final class MailTest extends TestCase
{
    private const KEYS = ['MAIL_TRANSPORT', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_TIMEOUT', 'PRIMARY_EMAIL', 'SITE_NAME'];

    private string $log = '';

    protected function setUp(): void
    {
        Config::$settings = [];
        Mailer::sendUsing(null);
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }
    }

    protected function tearDown(): void
    {
        Mailer::sendUsing(null);
        Config::$settings = [];
        foreach (self::KEYS as $key) {
            $this->setEnv($key, null);
        }
        if ($this->log !== '') {
            @unlink($this->log);
        }
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    public function testMessagesRefuseLineBreaksInHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Message('a@example.com', "Hello\r\nBcc: x@example.com", 'text');
    }

    public function testMessagesRefuseBadAddressesAndUnknownHeaders(): void
    {
        foreach ([
            static fn () => new Message('not-an-address', 'Hi', 'text'),
            static fn () => new Message('a@example.com', 'Hi', 'text', from: 'nope'),
            static fn () => new Message('a@example.com', 'Hi', 'text', headers: ['Bcc' => 'x@example.com']),
        ] as $make) {
            try {
                $make();
                self::fail('Expected an exception');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMailboxQuotesOrEncodesTheName(): void
    {
        self::assertSame('a@example.com', Message::mailbox('a@example.com', ' '));
        self::assertSame('"Smith, \"Jo\"" <a@example.com>', Message::mailbox('a@example.com', 'Smith, "Jo"'));
        self::assertStringStartsWith('=?UTF-8?B?', Message::mailbox('a@example.com', 'ئارام'));
    }

    public function testMimeHasTextAndHtmlParts(): void
    {
        $message = new Message('a@example.com', 'Hi', "Line 1\nLine 2", '<p>Line 1</p>', headers: ['Reply-To' => 'b@example.com']);
        $mime = $message->mime('site@example.com', 'Site', 'example.com');

        self::assertContains('From: "Site" <site@example.com>', $mime['headers']);
        self::assertContains('Reply-To: b@example.com', $mime['headers']);
        self::assertStringContainsString('multipart/alternative', implode("\n", $mime['headers']));
        self::assertStringContainsString(base64_encode("Line 1\r\nLine 2"), str_replace("\r\n", '', $mime['body']));
        self::assertStringContainsString('Content-Type: text/html', $mime['body']);
    }

    public function testSettingsComeFromEnvWithDefaults(): void
    {
        $this->setEnv('PRIMARY_EMAIL', 'info@example.com');
        $this->setEnv('SITE_NAME', 'Example');
        $this->setEnv('MAIL_ENCRYPTION', 'ssl');
        $this->setEnv('MAIL_TRANSPORT', 'carrier-pigeon');

        $settings = Mailer::settings();

        self::assertSame('mail', $settings['transport']);
        self::assertSame(465, $settings['port']);
        self::assertSame('info@example.com', $settings['from']);
        self::assertSame('Example', $settings['from_name']);
        self::assertSame(15, $settings['timeout']);
        self::assertSame('Example', Env::get('SITE_NAME'));
    }

    public function testSendUsingReplacesSending(): void
    {
        $sent = [];
        Mailer::sendUsing(static function (Message $message) use (&$sent): void {
            $sent[] = $message->to;
        });

        Mailer::send(new Message('a@example.com', 'Hi', 'text'));

        self::assertSame(['a@example.com'], $sent);
        self::assertNull(Mailer::problem());
    }

    public function testConfigSenderAndItsErrors(): void
    {
        Config::$settings['mail.send'] = static function (): void {
            throw new \RuntimeException('down');
        };

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('down');
        Mailer::send(new Message('a@example.com', 'Hi', 'text'));
    }

    public function testAFunctionNameIsNotASender(): void
    {
        Config::$settings['mail.send'] = 'log';

        self::assertStringContainsString('must be a function', (string) Mailer::problem());
        $this->expectException(MailException::class);
        Mailer::send(new Message('a@example.com', 'Hi', 'text'));
    }

    public function testAnEmptyFromFallsBackToTheSettings(): void
    {
        $this->log = sys_get_temp_dir() . '/flo-mail-' . bin2hex(random_bytes(4)) . '.log';
        Config::$settings['mail.log_path'] = $this->log;
        $this->setEnv('MAIL_TRANSPORT', 'log');
        $this->setEnv('MAIL_FROM', 'site@example.com');

        Mailer::send(new Message('a@example.com', 'Hi', 'text', from: ''));

        self::assertFileExists($this->log);
    }

    public function testLogTransportWritesTheMessage(): void
    {
        $this->log = sys_get_temp_dir() . '/flo-mail-' . bin2hex(random_bytes(4)) . '.log';
        Config::$settings['mail.log_path'] = $this->log;
        $this->setEnv('MAIL_TRANSPORT', 'log');
        $this->setEnv('MAIL_FROM', 'site@example.com');

        Mailer::send(new Message('a@example.com', 'Your link', 'Open https://example.com/x'));

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString("To: a@example.com\nSubject: Your link", $log);
        self::assertStringContainsString('https://example.com/x', $log);
    }

    public function testNoSenderAddressIsAProblem(): void
    {
        self::assertStringContainsString('MAIL_FROM', (string) Mailer::problem());

        $this->expectException(MailException::class);
        Mailer::send(new Message('a@example.com', 'Hi', 'text'));
    }

    public function testSmtpRefusesAPasswordWithoutEncryption(): void
    {
        $this->setEnv('MAIL_TRANSPORT', 'smtp');
        $this->setEnv('MAIL_HOST', 'mail.example.com');
        $this->setEnv('MAIL_ENCRYPTION', 'none');
        $this->setEnv('MAIL_USERNAME', 'user');
        $this->setEnv('MAIL_PASSWORD', 'secret');
        $this->setEnv('MAIL_FROM', 'site@example.com');

        try {
            Mailer::send(new Message('a@example.com', 'Hi', 'text'));
            self::fail('Expected a MailException');
        } catch (MailException $error) {
            self::assertStringContainsString('without encryption', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }
}
