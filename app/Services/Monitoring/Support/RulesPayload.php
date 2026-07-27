<?php

namespace App\Services\Monitoring\Support;

use App\Services\Monitoring\Exceptions\QueryFailed;

/**
 * Decodes an A2S_RULES reply.
 *
 * Rules lists are long — a Rust server sends a page and a half of them — so the
 * answer often arrives split across several datagrams that have to be
 * reassembled in order before anything can be read.
 */
final class RulesPayload
{
    private const SINGLE = "\xFF\xFF\xFF\xFF";

    private const MULTI = "\xFF\xFF\xFF\xFE";

    private const RESPONSE = 'E';

    /**
     * Reassemble one complete payload from datagrams as they arrive.
     *
     * @param  callable(): string  $readDatagram  returns the next datagram
     */
    public static function collect(string $first, callable $readDatagram): string
    {
        if (str_starts_with($first, self::SINGLE)) {
            return substr($first, 4);
        }

        if (! str_starts_with($first, self::MULTI)) {
            throw QueryFailed::malformed('unexpected A2S_RULES header');
        }

        $fragments = [];
        $datagram = $first;
        $total = null;

        // A server that stops mid-sequence must not hang the worker.
        for ($guard = 0; $guard < 32; $guard++) {
            if (strlen($datagram) < 12 || ! str_starts_with($datagram, self::MULTI)) {
                break;
            }

            $id = unpack('V', substr($datagram, 4, 4))[1];

            // The high bit marks a bzip2-compressed payload, which needs ext-bz2.
            if (($id & 0x80000000) !== 0 && ! function_exists('bzdecompress')) {
                throw QueryFailed::malformed('compressed A2S_RULES reply, ext-bz2 not available');
            }

            $total = ord($datagram[8]);
            $number = ord($datagram[9]);
            $fragments[$number] = substr($datagram, 12);

            if (count($fragments) >= $total) {
                break;
            }

            $datagram = $readDatagram();

            if ($datagram === '') {
                break;
            }
        }

        if ($total === null || count($fragments) < $total) {
            throw QueryFailed::malformed('incomplete A2S_RULES reply');
        }

        ksort($fragments);

        // The reassembled stream carries the ordinary single-packet header.
        return substr(implode('', $fragments), 4);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $payload): array
    {
        $reader = new ByteReader($payload);
        $type = chr($reader->byte());

        if ($type !== self::RESPONSE) {
            throw QueryFailed::malformed("unexpected A2S_RULES type [{$type}]");
        }

        $declared = $reader->short();
        $rules = [];

        // Servers do get the declared count wrong, and a reply can end mid-pair.
        // Keep whatever parsed cleanly rather than discarding the whole answer.
        for ($i = 0; $i < $declared && $reader->remaining() > 0; $i++) {
            try {
                $key = $reader->string();
                $value = $reader->string();
            } catch (QueryFailed) {
                break;
            }

            if ($key !== '') {
                $rules[$key] = $value;
            }
        }

        return $rules;
    }
}
