<?php

namespace Tests\Unit;

use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\Support\PacketBuffer;
use PHPUnit\Framework\TestCase;

class PacketBufferTest extends TestCase
{
    /**
     * Reference values from the protocol spec's VarInt table.
     */
    public function test_it_encodes_varints_the_way_the_protocol_specifies(): void
    {
        $this->assertSame('00', bin2hex(PacketBuffer::encodeVarInt(0)));
        $this->assertSame('01', bin2hex(PacketBuffer::encodeVarInt(1)));
        $this->assertSame('7f', bin2hex(PacketBuffer::encodeVarInt(127)));
        $this->assertSame('8001', bin2hex(PacketBuffer::encodeVarInt(128)));
        $this->assertSame('ff01', bin2hex(PacketBuffer::encodeVarInt(255)));
        // 2097151 is 2^21-1 — exactly three 7-bit groups, so three bytes.
        $this->assertSame('ffff7f', bin2hex(PacketBuffer::encodeVarInt(2097151)));
        $this->assertSame('ddc701', bin2hex(PacketBuffer::encodeVarInt(25565)));
    }

    public function test_negative_varints_are_encoded_as_32_bit_twos_complement(): void
    {
        // -1 is what clients send for "unknown protocol version".
        $this->assertSame('ffffffff0f', bin2hex(PacketBuffer::encodeVarInt(-1)));
    }

    public function test_varints_round_trip(): void
    {
        foreach ([0, 1, 2, 127, 128, 255, 300, 2097151, 25565, 767] as $value) {
            $buffer = new PacketBuffer(PacketBuffer::encodeVarInt($value));
            $this->assertSame($value, $buffer->readVarInt(), "round trip failed for {$value}");
        }
    }

    public function test_strings_round_trip_with_a_length_prefix(): void
    {
        $buffer = new PacketBuffer((new PacketBuffer)->putString('mc.hypixel.net')->toPacket(0x00));

        $this->assertSame(16, $buffer->readVarInt());   // frame: 1 id + 1 length byte + 14 chars
        $this->assertSame(0x00, $buffer->readVarInt()); // packet id
        $this->assertSame('mc.hypixel.net', $buffer->readString());
    }

    public function test_a_packet_is_framed_as_length_then_id_then_body(): void
    {
        $packet = (new PacketBuffer)
            ->putVarInt(767)
            ->putString('localhost')
            ->putUnsignedShort(25565)
            ->putVarInt(1)
            ->toPacket(0x00);

        $buffer = new PacketBuffer($packet);
        $length = $buffer->readVarInt();

        // The declared length must cover everything after the length field itself.
        $this->assertSame(strlen($packet) - 1, $length);
        $this->assertSame(0x00, $buffer->readVarInt());
        $this->assertSame(767, $buffer->readVarInt());
        $this->assertSame('localhost', $buffer->readString());
        $this->assertSame("\x63\xdd", chr($buffer->readByte()).chr($buffer->readByte())); // 25565, big-endian
        $this->assertSame(1, $buffer->readVarInt());
    }

    public function test_it_rejects_a_string_that_runs_past_the_payload(): void
    {
        $this->expectException(QueryFailed::class);

        (new PacketBuffer(PacketBuffer::encodeVarInt(50).'short'))->readString();
    }

    public function test_it_rejects_a_truncated_payload(): void
    {
        $this->expectException(QueryFailed::class);

        (new PacketBuffer)->readByte();
    }
}
