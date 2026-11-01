<?php

namespace Tests\Unit;

use App\Services\Dav\BinaryValue;
use PHPUnit\Framework\TestCase;

class BinaryValueTest extends TestCase
{
    public function test_a_stream_and_a_hex_literal_become_the_original_bytes(): void
    {
        $stream = fopen('php://memory', 'rb+');
        $this->assertIsResource($stream);
        fwrite($stream, 'BEGIN:VCALENDAR');
        rewind($stream);

        $this->assertSame('BEGIN:VCALENDAR', BinaryValue::toString($stream));
        $this->assertSame('BEGIN:VCALENDAR', BinaryValue::toString('\x'.bin2hex('BEGIN:VCALENDAR')));
        $this->assertSame('BEGIN:VCALENDAR', BinaryValue::toString('BEGIN:VCALENDAR'));
    }
}
