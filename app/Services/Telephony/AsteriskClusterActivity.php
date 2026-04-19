<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\AsteriskBackend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-node activity counts across the Asterisk cluster. Talks
 * AMI directly to each node (AMI credentials are identical on
 * both since they share config via ARA realtime) and returns
 * a compact map for the Drain UI to poll:
 *
 *   [
 *     'asterisk'   => ['channels' => 3, 'contacts' => 2, 'reachable' => true],
 *     'asterisk-2' => ['channels' => 0, 'contacts' => 0, 'reachable' => true],
 *   ]
 *
 * The drain lifecycle says: a backend is "drained and ready for
 * maintenance" when channels == 0 AND contacts == 0. Channels
 * tracks calls legged on that Asterisk; contacts tracks live
 * softphone / hardware endpoint registrations.
 */
class AsteriskClusterActivity
{
    public function __construct(
        protected float $timeout = 2.0,
    ) {}

    /**
     * Enumerates the registered AsteriskBackend rows and hits each
     * one's AMI endpoint for its live channel count. Inactive rows
     * are excluded so disabled-but-still-in-the-database entries
     * don't show up in the SIP Proxy page.
     *
     * @return array<string, array{channels: int, registrations: int, reachable: bool}>
     */
    public function all(): array
    {
        // Per-node dynamic registrations in one shot: group the
        // shared ps_contacts table by reg_server, which Asterisk
        // stamps with its own systemname (see entrypoint.sh).
        $registrations = $this->registrationsByNode();

        $backends = AsteriskBackend::query()->active()->orderBy('sort_order')->orderBy('hostname')->get();

        $out = [];
        foreach ($backends as $backend) {
            $channels = $this->countChannelsViaAmi($backend->amiHost(), $backend->ami_port);
            $out[$backend->hostname] = [
                'channels' => $channels['count'],
                'registrations' => $registrations[$backend->hostname] ?? 0,
                'reachable' => $channels['reachable'],
            ];
        }
        return $out;
    }

    /**
     * Per-backend channel count via AMI. Registrations live in
     * the shared ARA `ps_contacts` table and we count them via
     * registrationsByNode() in a single grouped query instead of
     * an AMI round-trip per node (which would show the same
     * count on both anyway because it reads the same table).
     *
     * @return array{count: int, reachable: bool}
     */
    protected function countChannelsViaAmi(string $host, int $port = 5038): array
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, (int) $this->timeout);
        if (! $socket) {
            Log::info('asterisk-activity: unreachable', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
            ]);
            return ['count' => 0, 'reachable' => false];
        }

        stream_set_timeout($socket, (int) $this->timeout);
        try {
            // Swallow greeting line.
            fgets($socket);
            if (! $this->login($socket)) {
                return ['count' => 0, 'reachable' => false];
            }
            $channels = $this->countChannels($socket);
            $this->send($socket, ['Action' => 'Logoff']);
            return ['count' => $channels, 'reachable' => true];
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Count dynamic registrations per Asterisk node by grouping
     * the shared ARA contacts table on `reg_server`, which each
     * Asterisk stamps with its own systemname (set from
     * ASTERISK_NODE_NAME in the container entrypoint). Static
     * contacts (trunks, livekit bridge) have no reg_server and
     * are correctly excluded — we only want live WSS/SIP
     * registrations tied to a specific node.
     *
     * @return array<string, int>
     */
    protected function registrationsByNode(): array
    {
        try {
            $rows = DB::table('ps_contacts')
                ->selectRaw('reg_server, COUNT(*) AS n')
                ->whereNotNull('reg_server')
                ->where('reg_server', '<>', '')
                ->groupBy('reg_server')
                ->get();
            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r->reg_server] = (int) $r->n;
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('asterisk-activity: registration query failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @param  resource $sock
     */
    protected function login($sock): bool
    {
        $resp = $this->send($sock, [
            'Action' => 'Login',
            'Username' => (string) config('telephony.asterisk.ami.username'),
            'Secret' => (string) config('telephony.asterisk.ami.secret', ''),
            // Events off so async updates don't queue up between
            // our action/response exchanges.
            'Events' => 'off',
        ]);
        return str_contains($resp, 'Success');
    }

    /**
     * Count live channels via `core show channels concise`. The AMI
     * Command action wraps every line of CLI output with "Output: "
     * on the wire, so we look for that prefix and then check for
     * the '!'-delimited channel format.
     *
     * @param  resource $sock
     */
    protected function countChannels($sock): int
    {
        $resp = $this->send($sock, [
            'Action' => 'Command',
            'Command' => 'core show channels concise',
        ]);
        $count = 0;
        foreach ($this->extractOutputLines($resp) as $line) {
            // concise format: PJSIP/...!context!exten!...
            // one row per active channel.
            if (str_contains($line, '!')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count registered contacts via `pjsip show contacts`. Covers
     * operator softphones (WSS), hardware phones, and AI agent
     * bridge endpoints.
     *
     * @param  resource $sock
     */
    protected function countContacts($sock): int
    {
        $resp = $this->send($sock, [
            'Action' => 'Command',
            'Command' => 'pjsip show contacts',
        ]);
        $count = 0;
        foreach ($this->extractOutputLines($resp) as $line) {
            // Contact rows look like:
            //   Contact:  100/sip:100@172.18.0.12:54321     ...  Avail
            // Skip the header line (literal "<Aor/ContactUri...>")
            // and count only real registrations.
            if (str_starts_with($line, 'Contact:')
                && ! str_contains($line, '<Aor/ContactUri')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Pull just the CLI-output lines out of an AMI Command response.
     * AMI prefixes every output line with "Output: "; the surrounding
     * Response/Privilege/Message/ActionID lines get filtered out.
     *
     * @return iterable<string>
     */
    protected function extractOutputLines(string $response): iterable
    {
        foreach (explode("\n", $response) as $line) {
            $line = rtrim($line, "\r\n\t ");
            if (! str_starts_with($line, 'Output: ')) {
                continue;
            }
            $payload = trim(substr($line, strlen('Output: ')));
            if ($payload === '' || $payload === '--END COMMAND--') {
                continue;
            }
            yield $payload;
        }
    }

    /**
     * Send an action + read until the blank-line terminator.
     *
     * @param  resource $sock
     * @param  array<string, string> $action
     */
    protected function send($sock, array $action): string
    {
        $payload = '';
        foreach ($action as $k => $v) {
            $payload .= "{$k}: {$v}\r\n";
        }
        $payload .= "\r\n";
        fwrite($sock, $payload);

        $response = '';
        $start = microtime(true);
        while (! feof($sock)) {
            $line = fgets($sock, 4096);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // End of message = blank line, OR command footer.
            if (trim($line) === '' && $response !== $line) {
                // Command responses often end with --END COMMAND--
                // on a line of their own, followed by a blank; we
                // detect either as terminator.
                if (str_contains($response, '--END COMMAND--')
                    || ! str_contains($response, 'Response: Follows')) {
                    break;
                }
            }
            if ((microtime(true) - $start) > $this->timeout) {
                break;
            }
        }
        return $response;
    }
}
