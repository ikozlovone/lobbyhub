<?php

namespace App\Services\Monitoring\Support;

use App\Services\Monitoring\Exceptions\QueryFailed;

/**
 * Reader/writer for the Minecraft wire format: VarInt-prefixed fields inside
 * a length-prefixed packet frame.
 */
final class PacketBuffer
{
    private int $offset = 0;

    public function __construct(private string $bytes = '') {}

    /**
     * VarInts are 32-bit two's complement, so negative values (the -1 that
     * clients send as "unknown protocol version") become five bytes.
     */
    public static function encodeVarInt(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $out = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $out;
    }

    public function putVarInt(int $value): static
    {
        return $this->putRaw(self::encodeVarInt($value));
    }

    public function putString(string $value): static
    {
        return $this->putVarInt(strlen($value))->putRaw($value);
    }

    public function putUnsignedShort(int $value): static
    {
        return $this->putRaw(pack('n', $value));
    }

    public function putRaw(string $value): static
    {
        $this->bytes .= $value;

        return $this;
    }

    /** Wrap what has been written as a packet: VarInt(length) + VarInt(id) + body. */
    public function toPacket(int $id): string
    {
        $body = self::encodeVarInt($id).$this->bytes;

        return self::encodeVarInt(strlen($body)).$body;
    }

    public function readVarInt(): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($shift >= 35) {
                throw QueryFailed::malformed('VarInt is longer than 5 bytes');
            }

            $byte = $this->readByte();
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $result;
    }

    public function readString(): string
    {
        $length = $this->readVarInt();

        if ($length < 0 || $this->offset + $length > strlen($this->bytes)) {
            throw QueryFailed::malformed("string of {$length} bytes runs past the payload");
        }

        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function readByte(): int
    {
        if ($this->offset >= strlen($this->bytes)) {
            throw QueryFailed::malformed('payload ended early');
        }

        return ord($this->bytes[$this->offset++]);
    }
}
