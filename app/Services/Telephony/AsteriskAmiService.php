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

        // Login
        $response = $this->sendAction([
            'Action' => 'Login',
            'Username' => $this->username,
            'Secret' => $this->secret,
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
