<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Log;

class AsteriskAmiService
{
    protected string $host;
    protected int $port;
    protected string $username;
    protected string $secret;

    /** @var resource|null */
    protected $socket = null;

    public function __construct()
    {
        $this->host = config('telephony.asterisk.ami.host');
        $this->port = config('telephony.asterisk.ami.port');
        $this->username = config('telephony.asterisk.ami.username');
        $this->secret = config('telephony.asterisk.ami.secret', '');
    }

    protected function connect(): bool
    {
        if ($this->socket) {
            return true;
        }

        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (! $socket) {
            Log::error("AMI connection failed: {$errstr} ({$errno})");

            return false;
        }

        $this->socket = $socket;

        // Read greeting
        fgets($this->socket);

        // Login with `Events: off` so Asterisk doesn't push async
        // events (FullyBooted, PeerStatus, Registry, etc.) onto
        // this socket. Without it, those events queue up between
        // actions and the next sendAction reads the queued event
        // as if it were the command response — which has no
        // Success/Follows marker, so the reload check fails.
        //
        // We only use AMI for one-shot commands (reload / originate
        // / show channels), never for event subscription, so
        // suppressing events is the right default.
        $response = $this->sendAction([
            'Action' => 'Login',
            'Username' => $this->username,
            'Secret' => $this->secret,
            'Events' => 'off',
        ]);

        return str_contains($response, 'Success');
    }

    protected function disconnect(): void
    {
        if ($this->socket) {
            $this->sendAction(['Action' => 'Logoff']);
            fclose($this->socket);
            $this->socket = null;
        }
    }

    protected function sendAction(array $action): string
    {
        if (! $this->socket) {
            return '';
        }

        $message = '';
        foreach ($action as $key => $value) {
            $message .= "{$key}: {$value}\r\n";
        }
        $message .= "\r\n";

        fwrite($this->socket, $message);

        $response = '';
        while ($line = fgets($this->socket)) {
            $response .= $line;
            if (trim($line) === '') {
                break;
            }
        }

        return $response;
    }

    public function reload(): bool
    {
        if (! $this->connect()) {
            return false;
        }

        $response = $this->sendAction([
            'Action' => 'Command',
            'Command' => 'core reload',
        ]);

        $this->disconnect();

        return str_contains($response, 'Success') || str_contains($response, 'Follows');
    }

    /**
     * Run an AMI Command against a specific Asterisk backend (not
     * the singleton's default). Opens a one-shot connection so it
     * doesn't clobber $this->socket / $this->host state the rest
     * of the singleton depends on. Used by the fan-out reload path
     * so `dialplan reload` propagates to every AsteriskBackend row
     * when the config generator writes new files.
     *
     * Returns true when Asterisk answered with Success / Follows,
     * false on any socket/auth/command failure (with a warning log).
     */
    public function commandOn(string $host, int $port, string $command): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if (! $socket) {
            Log::warning('AMI fan-out: connection failed', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
            ]);
            return false;
        }

        try {
            // Drain the greeting line before sending Login.
            fgets($socket);

            $login = $this->sendOn($socket, [
                'Action' => 'Login',
                'Username' => $this->username,
                'Secret' => $this->secret,
                'Events' => 'off',
            ]);
            if (! str_contains($login, 'Success')) {
                Log::warning('AMI fan-out: login failed', [
                    'host' => $host,
                    'response' => mb_substr($login, 0, 200),
                ]);
                return false;
            }

            $response = $this->sendOn($socket, [
                'Action' => 'Command',
                'Command' => $command,
            ]);

            $this->sendOn($socket, ['Action' => 'Logoff']);

            return str_contains($response, 'Success') || str_contains($response, 'Follows');
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Inner helper for commandOn(). Writes one AMI action frame to
     * the given socket and reads the response up to the blank-line
     * terminator. Kept private because it doesn't manage the
     * login/logoff lifecycle — only commandOn() does.
     *
     * @param  resource  $socket
     * @param  array<string, string>  $action
     */
    private function sendOn($socket, array $action): string
    {
        $message = '';
        foreach ($action as $key => $value) {
            $message .= "{$key}: {$value}\r\n";
        }
        $message .= "\r\n";

        fwrite($socket, $message);

        $response = '';
        while ($line = fgets($socket)) {
            $response .= $line;
            if (trim($line) === '') {
                break;
            }
        }
        return $response;
    }

    /**
     * Reload only the dialplan (pbx_config). Cheaper than `core
     * reload` because Asterisk doesn't re-parse pjsip endpoints,
     * queues, codecs, or any of the other module configs — it just
     * re-reads `extensions.conf` (and #include'd files) and rebuilds
     * the dialplan tree.
     *
     * Used by the per-client dialplan write path so a single
     * client's RoutingRule edit doesn't churn the whole platform.
     * Endpoint and queue changes in the ARA path don't trigger any
     * reload at all — Asterisk pulls those per-call from the DB.
     */
    public function reloadDialplan(): bool
    {
        if (! $this->connect()) {
            return false;
        }

        $response = $this->sendAction([
            'Action' => 'Command',
            'Command' => 'dialplan reload',
        ]);

        $this->disconnect();

        return str_contains($response, 'Success') || str_contains($response, 'Follows');
    }

    public function getActiveChannels(): array
    {
        if (! $this->connect()) {
            return [];
        }

        $response = $this->sendAction([
            'Action' => 'Command',
            'Command' => 'core show channels concise',
        ]);

        $this->disconnect();

        $channels = [];
        foreach (explode("\n", $response) as $line) {
            $line = trim($line);
            if ($line && ! str_starts_with($line, 'Response:') && ! str_starts_with($line, 'Privilege:') && ! str_starts_with($line, 'Output:') && $line !== '--END COMMAND--') {
                $channels[] = $line;
            }
        }

        return $channels;
    }

    public function originate(string $channel, string $extension, string $context = 'internal'): bool
    {
        if (! $this->connect()) {
            return false;
        }

        $response = $this->sendAction([
            'Action' => 'Originate',
            'Channel' => $channel,
            'Exten' => $extension,
            'Context' => $context,
            'Priority' => '1',
            'Async' => 'true',
        ]);

        $this->disconnect();

        return str_contains($response, 'Success');
    }
}
