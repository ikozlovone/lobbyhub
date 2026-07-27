<?php

namespace App\Services\Monitoring\Support;

use App\Services\Monitoring\Exceptions\QueryFailed;

/**
 * Little-endian reader for Valve's A2S datagrams. Every read is bounds-checked:
 * the payload comes from an untrusted server over UDP.
 */
final class ByteReader
{
    private int $offset = 0;

    private int $length;

    public function __construct(private string $bytes)
    {
        $this->length = strlen($bytes);
    }

    public function raw(int $count): string
    {
        $this->assertAvailable($count);

        $value = substr($this->bytes, $this->offset, $count);
        $this->offset += $count;

        return $value;
    }

    public function byte(): int
    {
        return ord($this->raw(1));
    }

    /** Unsigned 16-bit. */
    public function short(): int
    {
        return unpack('v', $this->raw(2))[1];
    }

    /** Signed 32-bit. */
    public function long(): int
    {
        return unpack('l', $this->raw(4))[1];
    }

    /** Null-terminated UTF-8 string. */
    public function string(): string
    {
        $end = strpos($this->bytes, "\x00", $this->offset);

        if ($end === false) {
            throw QueryFailed::malformed('unterminated string');
        }

        $value = substr($this->bytes, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $value;
    }

    public function skip(int $count): void
    {
        $this->assertAvailable($count);
        $this->offset += $count;
    }

    public function remaining(): int
    {
        return $this->length - $this->offset;
    }

    private function assertAvailable(int $count): void
    {
        if ($count < 0 || $this->offset + $count > $this->length) {
            throw QueryFailed::malformed("wanted {$count} byte(s) past the end of the payload");
        }
    }
}
