<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\RtpengineNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Wraps the rtpengine NG control protocol (bencode-over-UDP, cookie
 * + bencoded dict) and fans every command out across every active
 * `rtpengine_nodes` row.
 *
 * Same fan-out shape as {@see KamailioService::setBackendState} and
 * {@see HAProxyStatsClient::action} — loop every node, attach
 * results, treat the operation as successful only when every node
 * accepted. Per-node failures don't block the others.
 *
 * NG message wire format (rtpengine docs §4): `<cookie> <bencoded
 * dict>`. The cookie is a client-picked random token the daemon
 * echoes in its response so we know which reply belongs to which
 * request when multiplexing.
 */
class RtpengineService
{
    /**
     * UDP timeout for an NG round-trip, in seconds. The daemon is
     * supposed to be sub-millisecond locally; 1.5s leaves wide
     * headroom for cross-VM hops + lets a sick node fail out fast.
     */
    private const TIMEOUT_SECONDS = 1.5;

    /**
     * Ping every node. Returns `[hostname => bool]`.
     *
     * @return array<string, bool>
     */
    public function pingAll(): array
    {
        $results = [];
        foreach (RtpengineNode::active()->orderBy('sort_order')->get() as $node) {
            $resp = $this->send($node, ['command' => 'ping']);
            $results[$node->hostname] = ($resp['result'] ?? null) === 'pong';
        }
        return $results;
    }

    /**
     * Statistics from one node — totals (current sessions, bytes,
     * packets, errors). Returns the raw decoded dict so callers
     * pick whatever metric they care about. Null on transport error.
     *
     * @return array<string, mixed>|null
     */
    public function statistics(RtpengineNode $node): ?array
    {
        return $this->send($node, ['command' => 'statistics']);
    }

    /**
     * Active call list from one node. Returns the bencoded `calls`
     * array verbatim so callers can count active sessions or look
     * up by call-id. Null on transport error.
     *
     * @return array<int, string>|null
     */
    public function listCalls(RtpengineNode $node): ?array
    {
        $resp = $this->send($node, ['command' => 'list']);
        if ($resp === null) {
            return null;
        }
        return is_array($resp['calls'] ?? null) ? $resp['calls'] : [];
    }

    /**
     * Drain a node — reject new offers but let in-flight calls
     * finish. NG `set-forwarding` toggles the relay loop; combined
     * with our own `is_active=false` flag in `rtpengine_nodes`,
     * Kamailio's per-call selection skips the draining node for
     * any new dialog so existing calls stay routed correctly.
     *
     * Caller is expected to wrap this in {@see RtpengineDrainService}
     * which also flips the registry flag and writes the audit log.
     */
    public function setForwarding(RtpengineNode $node, bool $enabled): bool
    {
        $resp = $this->send($node, [
            'command' => 'set-forwarding',
            'set' => $enabled ? 'on' : 'off',
        ]);
        return ($resp['result'] ?? null) === 'ok';
    }

    /**
     * Send one NG command to one node. Returns the decoded dict
     * minus the wrapping cookie, or null on transport / decode error.
     *
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>|null
     */
    protected function send(RtpengineNode $node, array $command): ?array
    {
        $cookie = (string) Str::random(16);
        $payload = $cookie.' '.$this->bencode($command);

        $address = 'udp://'.$node->ngHost().':'.$node->ng_port;
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
        );
        if ($sock === false) {
            Log::warning('rtpengine NG connect failed', [
                'node' => $node->hostname,
                'error' => $errstr,
                'errno' => $errno,
            ]);
            return null;
        }

        try {
            stream_set_timeout($sock, 0, (int) (self::TIMEOUT_SECONDS * 1_000_000));
            fwrite($sock, $payload);

            $raw = fread($sock, 65535);
            if ($raw === false || $raw === '') {
                return null;
            }

            // Split off the echoed cookie. rtpengine returns
            // `<cookie> <bencoded dict>` exactly the same shape we sent.
            [$echoed, $body] = array_pad(explode(' ', $raw, 2), 2, '');
            if ($echoed !== $cookie) {
                Log::warning('rtpengine NG cookie mismatch', [
                    'node' => $node->hostname,
                    'sent' => $cookie,
                    'recv' => $echoed,
                ]);
                return null;
            }

            return $this->bdecode($body);
        } catch (\Throwable $e) {
            Log::warning('rtpengine NG transport error', [
                'node' => $node->hostname,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            fclose($sock);
        }
    }

    /**
     * Bencode a PHP value. Strings are length-prefixed
     * (`5:hello`), ints wrap in `i…e`, lists in `l…e`, dicts in
     * `d…e` with keys sorted lexicographically (the format requires
     * canonical ordering for byte-level reproducibility).
     */
    protected function bencode(mixed $v): string
    {
        if (is_string($v)) {
            return strlen($v).':'.$v;
        }
        if (is_int($v)) {
            return 'i'.$v.'e';
        }
        if (is_bool($v)) {
            return 'i'.($v ? 1 : 0).'e';
        }
        if (is_array($v)) {
            if ($v === [] || array_is_list($v)) {
                $out = 'l';
                foreach ($v as $vv) {
                    $out .= $this->bencode($vv);
                }
                return $out.'e';
            }
            ksort($v);
            $out = 'd';
            foreach ($v as $k => $vv) {
                $out .= $this->bencode((string) $k).$this->bencode($vv);
            }
            return $out.'e';
        }
        throw new RuntimeException('rtpengine NG: unsupported bencode type '.gettype($v));
    }

    /**
     * Decode a bencoded string. One-pass cursor parser — bencode is
     * unambiguous so no lookahead is needed beyond the type tag.
     *
     * @return mixed
     */
    protected function bdecode(string $s): mixed
    {
        $pos = 0;
        $value = $this->bdecodeAt($s, $pos);
        return $value;
    }

    protected function bdecodeAt(string $s, int &$pos): mixed
    {
        $tag = $s[$pos] ?? '';

        if ($tag === 'i') {
            $end = strpos($s, 'e', $pos + 1);
            if ($end === false) {
                throw new RuntimeException('rtpengine NG: malformed integer');
            }
            $n = (int) substr($s, $pos + 1, $end - $pos - 1);
            $pos = $end + 1;
            return $n;
        }

        if ($tag === 'l') {
            $pos++;
            $list = [];
            while (($s[$pos] ?? '') !== 'e') {
                $list[] = $this->bdecodeAt($s, $pos);
            }
            $pos++;
            return $list;
        }

        if ($tag === 'd') {
            $pos++;
            $dict = [];
            while (($s[$pos] ?? '') !== 'e') {
                $key = $this->bdecodeAt($s, $pos);
                if (! is_string($key)) {
                    throw new RuntimeException('rtpengine NG: dict key must be string');
                }
                $dict[$key] = $this->bdecodeAt($s, $pos);
            }
            $pos++;
            return $dict;
        }

        // String — length-prefixed, e.g. `5:hello`.
        if (ctype_digit($tag)) {
            $colon = strpos($s, ':', $pos);
            if ($colon === false) {
                throw new RuntimeException('rtpengine NG: malformed string');
            }
            $len = (int) substr($s, $pos, $colon - $pos);
            $val = substr($s, $colon + 1, $len);
            $pos = $colon + 1 + $len;
            return $val;
        }

        throw new RuntimeException('rtpengine NG: unknown bencode tag at '.$pos);
    }
}
