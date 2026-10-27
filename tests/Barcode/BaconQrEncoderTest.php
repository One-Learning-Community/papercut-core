<?php

declare(strict_types=1);

namespace Papercut\Tests\Barcode;

use Papercut\Barcode\Bacon\BaconQrEncoder;
use Papercut\Barcode\{ErrorCorrection, QrShape};
use Papercut\Exception\InvalidValue;
use Papercut\QrCode;
use PHPUnit\Framework\TestCase;

/** BaconQrCode's encoder, for text longer than php-pdf's encoder holds. */
final class BaconQrEncoderTest extends TestCase
{
    public function test_it_holds_text_the_default_encoder_cannot(): void
    {
        $text = str_repeat('https://acme.test/', 50);

        try {
            QrCode::make($text);
            self::fail('php-pdf\'s encoder took 900 bytes.');
        } catch (InvalidValue) {
        }
        $qr = QrCode::make($text, ErrorCorrection::Medium, new BaconQrEncoder());

        $n = count($qr->modules());
        self::assertGreaterThan(57, $n);
        self::assertSame(0, ($n - 17) % 4);
        foreach ($qr->modules() as $row) {
            self::assertCount($n, $row);
        }
        self::assertNotNull($qr->shape(QrShape::Dots)->design());
    }

    public function test_the_eyes_are_where_a_scanner_looks(): void
    {
        $m = (new BaconQrEncoder())->encode('Grüße aus Köln', ErrorCorrection::Medium);
        $n = count($m);

        // Each finder pattern: a dark 7 x 7 ring, a light ring, a dark 3 x 3 centre.
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
            self::assertTrue($m[$y][$x] && $m[$y + 6][$x + 6] && $m[$y + 3][$x + 3]);
            self::assertFalse($m[$y + 1][$x + 1] || $m[$y + 5][$x + 5]);
        }
    }

    public function test_a_higher_level_needs_more_modules(): void
    {
        $encoder = new BaconQrEncoder();

        self::assertGreaterThan(count($encoder->encode(str_repeat('x', 100), ErrorCorrection::Low)), count($encoder->encode(str_repeat('x', 100), ErrorCorrection::High)));
    }

    public function test_text_too_long_is_explained(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('too long at high error correction');

        (new BaconQrEncoder())->encode(str_repeat('x', 3000), ErrorCorrection::High);
    }

    public function test_empty_text_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('nothing to encode');

        (new BaconQrEncoder())->encode('', ErrorCorrection::Medium);
    }
}
