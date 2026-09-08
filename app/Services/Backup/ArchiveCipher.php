<?php

declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Streaming authenticated encryption for backup archives.
 *
 * A database dump is the most sensitive artifact this platform
 * produces — every caller name, every number, every message body, and
 * the encrypted platform settings. It does not leave the host in the
 * clear, and it is not held in memory in one piece either: a dump can
 * be tens of gigabytes, so both directions stream a chunk at a time.
 *
 * libsodium's secretstream (XChaCha20-Poly1305) rather than a raw
 * cipher, for two reasons that both matter at restore time:
 *
 *   - It is AUTHENTICATED. A truncated upload, a flipped bit, or a
 *     deliberately altered archive fails to decrypt instead of
 *     restoring silent garbage into your production database. That is
 *     also why there is no separate checksum file — tampering is
 *     detected by construction, and a checksum an attacker can rewrite
 *     was never protection anyway.
 *   - It is a STREAM with an explicit final tag. An archive that was
 *     cut short mid-upload is detectable as incomplete, rather than
 *     decrypting cleanly up to the truncation point and looking fine.
 *
 * The passphrase is stretched with sodium_crypto_pwhash against a
 * random per-archive salt, so a weak one costs an attacker real time
 * and memory per guess rather than being instantly enumerable.
 *
 * File layout:
 *
 *   "ORBITALBK1\n"   11 bytes   magic + format version
 *   salt             16 bytes   per-archive, for the KDF
 *   header           24 bytes   secretstream header
 *   chunks           ...        each: 4-byte BE length + ciphertext
 *
 * The chunk length prefix is what lets the reader pull exactly one
 * ciphertext block without knowing the plaintext size in advance.
 */
class ArchiveCipher
{
    public const MAGIC = "ORBITALBK1\n";

    /** Plaintext bytes per chunk. 1 MiB balances memory against overhead. */
    private const CHUNK = 1048576;

    private const SALT_BYTES = SODIUM_CRYPTO_PWHASH_SALTBYTES;

    /**
     * Encrypt $source into $destination. Returns bytes written.
     *
     * @param  resource  $source
     * @param  resource  $destination
     */
    public function encrypt($source, $destination, string $passphrase): int
    {
        $salt = random_bytes(self::SALT_BYTES);
        $key = $this->deriveKey($passphrase, $salt);

        [$state, $header] = array_values((function () use ($key): array {
            $h = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

            return [$h[0], $h[1]];
        })());

        $written = $this->write($destination, self::MAGIC.$salt.$header);

        while (! feof($source)) {
            $chunk = fread($source, self::CHUNK);

            if ($chunk === false) {
                throw new RuntimeException('backup: failed reading plaintext while encrypting');
            }

            if ($chunk === '' && feof($source)) {
                break;
            }

            // The final chunk carries the FINAL tag. A reader that hits
            // end-of-file without having seen it knows the archive is
            // truncated rather than assuming it simply ended.
            $tag = feof($source)
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

            $written += $this->write($destination, pack('N', strlen($cipher)).$cipher);
        }

        sodium_memzero($key);

        return $written;
    }

    /**
     * Decrypt $source into $destination. Returns bytes written.
     *
     * @param  resource  $source
     * @param  resource  $destination
     */
    public function decrypt($source, $destination, string $passphrase): int
    {
        $magic = (string) fread($source, strlen(self::MAGIC));

        if ($magic !== self::MAGIC) {
            throw new RuntimeException(
                'backup: this file is not an Orbital backup archive (bad magic bytes)'
            );
        }

        $salt = (string) fread($source, self::SALT_BYTES);
        $header = (string) fread($source, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (strlen($salt) !== self::SALT_BYTES
            || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('backup: archive header is truncated');
        }

        $key = $this->deriveKey($passphrase, $salt);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

        if ($state === false) {
            throw new RuntimeException('backup: archive header could not be read');
        }

        $written = 0;
        $sawFinal = false;

        while (! feof($source)) {
            $lengthBytes = (string) fread($source, 4);

            if ($lengthBytes === '' && feof($source)) {
                break;
            }

            if (strlen($lengthBytes) !== 4) {
                throw new RuntimeException('backup: archive ends mid-chunk — the upload was truncated');
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('N', $lengthBytes);
            $cipher = $this->readExactly($source, $unpacked[1]);

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);

            // The single most important line in this class. A wrong
            // passphrase and a corrupted archive both land here, and
            // both must stop the restore rather than write partial
            // rubbish into a database somebody is about to trust.
            if ($result === false) {
                throw new RuntimeException(
                    'backup: could not decrypt — wrong passphrase, or the archive is corrupt'
                );
            }

            [$plain, $tag] = $result;
            $written += $this->write($destination, $plain);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                break;
            }
        }

        sodium_memzero($key);

        if (! $sawFinal) {
            throw new RuntimeException(
                'backup: archive is incomplete — it has no end marker, so the backup that produced it did not finish'
            );
        }

        return $written;
    }

    /**
     * Stretch the operator's passphrase into a key.
     *
     * INTERACTIVE limits (~64 MiB, ~0.1s) rather than MODERATE: this
     * runs inside a container with a memory limit, and MODERATE's 256
     * MiB is enough to get the backup job OOM-killed on a small node —
     * a backup that does not run is worse than a key that is slightly
     * cheaper to attack.
     */
    private function deriveKey(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * @param  resource  $handle
     */
    private function readExactly($handle, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $part = fread($handle, $length - strlen($buffer));

            if ($part === false || $part === '') {
                throw new RuntimeException('backup: archive ends mid-chunk — the upload was truncated');
            }

            $buffer .= $part;
        }

        return $buffer;
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $bytes): int
    {
        $written = fwrite($handle, $bytes);

        if ($written === false || $written !== strlen($bytes)) {
            throw new RuntimeException('backup: failed writing archive (out of disk?)');
        }

        return $written;
    }
}
