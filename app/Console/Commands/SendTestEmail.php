<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Sends test emails directly to Haraka's SMTP port so they flow
 * through the full inbound pipeline: Haraka → webhook → Laravel
 * router → thread. Useful for verifying the inbox, routing rules,
 * and thread viewer without needing an external mail client.
 *
 * Usage:
 *   sail artisan orbital:send-test-email
 *   sail artisan orbital:send-test-email --html
 *   sail artisan orbital:send-test-email --to 100001@inbound.orbital.test
 *   sail artisan orbital:send-test-email --from "jane@example.com" --subject "Invoice question"
 */
class SendTestEmail extends Command
{
    protected $signature = 'orbital:send-test-email
        {--to= : Recipient address (default: {first_account}@inbound_domain)}
        {--from=test-sender@example.com : Sender address}
        {--from-name=Test Sender : Sender display name}
        {--subject= : Subject line (auto-generated if omitted)}
        {--body= : Plain-text body (auto-generated if omitted)}
        {--html : Send an HTML-formatted email instead of plain text}
        {--count=1 : Number of emails to send}';

    protected $description = 'Send a test email through Haraka to exercise the inbound pipeline.';

    public function handle(): int
    {
        $domain = (string) config('services.inbound_mail.domain', 'inbound.orbital.test');

        // Default recipient: first tenant with an account_number.
        $to = $this->option('to');
        if (! $to) {
            $team = Team::whereNotNull('account_number')->first();
            if (! $team) {
                $this->error('No team with an account_number found. Pass --to explicitly.');

                return self::FAILURE;
            }
            $to = "{$team->account_number}@{$domain}";
            $this->line("Routing to tenant: <info>{$team->name}</info> ({$to})");
        }

        $from = $this->option('from');
        $fromName = $this->option('from-name');
        $count = max(1, (int) $this->option('count'));
        $html = (bool) $this->option('html');

        for ($i = 1; $i <= $count; $i++) {
            $seq = $count > 1 ? " #{$i}" : '';
            $subject = $this->option('subject') ?: ($html
                ? "HTML test email{$seq} — ".now()->format('M j g:i:s a')
                : "Plain text test email{$seq} — ".now()->format('M j g:i:s a'));

            $body = $this->option('body') ?: ($html
                ? $this->sampleHtmlBody($subject)
                : $this->samplePlainBody($subject));

            $mime = $this->buildMime($from, $fromName, $to, $subject, $body, $html);

            $this->sendSmtp($from, $to, $mime);
            $this->info("Sent: {$subject}");

            if ($i < $count) {
                usleep(200_000); // 200ms between messages
            }
        }

        $this->newLine();
        $this->info('Done. Check the operator Email Inbox for the new thread(s).');

        return self::SUCCESS;
    }

    private function buildMime(string $from, string $fromName, string $to, string $subject, string $body, bool $html): string
    {
        $messageId = sprintf('%s@%s', bin2hex(random_bytes(16)), parse_url(config('app.url'), PHP_URL_HOST) ?: 'orbital.test');
        $date = now()->format('r');

        $headers = implode("\r\n", [
            "From: {$fromName} <{$from}>",
            "To: {$to}",
            "Subject: {$subject}",
            "Date: {$date}",
            "Message-ID: <{$messageId}>",
            'MIME-Version: 1.0',
        ]);

        if ($html) {
            $boundary = '----=_Part_'.bin2hex(random_bytes(8));
            $headers .= "\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"";

            $plainFallback = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body));

            $mimeBody = "\r\n--{$boundary}\r\n"
                ."Content-Type: text/plain; charset=UTF-8\r\n"
                ."Content-Transfer-Encoding: 8bit\r\n\r\n"
                .$plainFallback
                ."\r\n--{$boundary}\r\n"
                ."Content-Type: text/html; charset=UTF-8\r\n"
                ."Content-Transfer-Encoding: 8bit\r\n\r\n"
                .$body
                ."\r\n--{$boundary}--\r\n";

            return $headers."\r\n".$mimeBody;
        }

        $headers .= "\r\nContent-Type: text/plain; charset=UTF-8";

        return $headers."\r\n\r\n".$body;
    }

    private function sendSmtp(string $from, string $to, string $mime): void
    {
        $host = 'haraka';
        $port = 25;
        $timeout = 10;

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (! $fp) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port} — {$errstr} ({$errno})");
        }

        $this->smtpRead($fp);
        $this->smtpCmd($fp, 'EHLO orbital.test');
        $this->smtpCmd($fp, "MAIL FROM:<{$from}>");
        $this->smtpCmd($fp, "RCPT TO:<{$to}>");
        $this->smtpCmd($fp, 'DATA', 354);

        fwrite($fp, $mime."\r\n.\r\n");
        $this->smtpRead($fp);

        $this->smtpCmd($fp, 'QUIT', 221);
        fclose($fp);
    }

    private function smtpCmd($fp, string $cmd, int $expect = 250): void
    {
        fwrite($fp, $cmd."\r\n");
        $response = $this->smtpRead($fp);
        $code = (int) substr($response, 0, 3);
        if ($code !== $expect) {
            throw new \RuntimeException("SMTP error: expected {$expect}, got: {$response}");
        }
    }

    private function smtpRead($fp): string
    {
        $response = '';
        while ($line = fgets($fp, 512)) {
            $response .= $line;
            // A line starting with "NNN " (space, not dash) is the final line.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return trim($response);
    }

    private function samplePlainBody(string $subject): string
    {
        return <<<TEXT
        Hi there,

        This is a plain-text test email sent via `orbital:send-test-email`.

        Subject: {$subject}
        Sent at: {$this->now()}

        Please reply to this message to test the outbound reply pipeline.

        Thanks,
        Test Sender
        TEXT;
    }

    private function sampleHtmlBody(string $subject): string
    {
        $now = $this->now();

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f4f4f5; }
                .wrapper { max-width: 600px; margin: 0 auto; padding: 24px; }
                .card { background: #ffffff; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                h1 { color: #18181b; font-size: 20px; margin: 0 0 16px 0; }
                p { color: #3f3f46; font-size: 14px; line-height: 1.6; margin: 0 0 12px 0; }
                .meta { background: #f4f4f5; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #71717a; margin: 16px 0; }
                .meta strong { color: #18181b; }
                .badge { display: inline-block; background: #4f46e5; color: #fff; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; }
                .cta { display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-top: 16px; }
                .footer { text-align: center; padding: 16px 0; font-size: 12px; color: #a1a1aa; }
                table { width: 100%; border-collapse: collapse; margin: 12px 0; }
                th, td { text-align: left; padding: 8px 12px; font-size: 13px; border-bottom: 1px solid #e4e4e7; }
                th { background: #f4f4f5; color: #52525b; font-weight: 600; }
                td { color: #3f3f46; }
            </style>
        </head>
        <body>
            <div class="wrapper">
                <div class="card">
                    <h1>HTML Test Email <span class="badge">Test</span></h1>
                    <p>This is a <strong>rich HTML</strong> test email sent via <code>orbital:send-test-email --html</code>. It exercises the HTML body rendering in the thread viewer, including the iframe sandbox and the HTML/Plain toggle.</p>

                    <div class="meta">
                        <strong>Subject:</strong> {$subject}<br>
                        <strong>Sent at:</strong> {$now}<br>
                        <strong>Purpose:</strong> Verify HTML email rendering in operator inbox
                    </div>

                    <p>Here's a sample table to test layout rendering:</p>
                    <table>
                        <thead>
                            <tr><th>Item</th><th>Status</th><th>Notes</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Thread creation</td><td>Active</td><td>Should create or append to thread</td></tr>
                            <tr><td>HTML viewer</td><td>Active</td><td>Rendered in sandboxed iframe</td></tr>
                            <tr><td>Plain text fallback</td><td>Active</td><td>Visible via the Plain toggle</td></tr>
                        </tbody>
                    </table>

                    <p>Please reply to this message to test the outbound reply pipeline.</p>
                    <a href="https://orbital.test" class="cta">Open Orbital</a>
                </div>
                <div class="footer">
                    This is a test email generated by Orbital's development tools.
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function now(): string
    {
        return now()->format('M j, Y g:i:s A T');
    }
}
