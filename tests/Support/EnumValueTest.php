<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use Papercut\Barcode\{ErrorCorrection, QrGradient};
use Papercut\Exception\InvalidValue;
use Papercut\Style\{Align, Direction};
use Papercut\Support\EnumValue;
use PHPUnit\Framework\TestCase;

final class EnumValueTest extends TestCase
{
    public function test_matches_the_value_ignoring_case_and_whitespace(): void
    {
        self::assertSame(Align::Center, EnumValue::from(Align::class, 'center'));
        self::assertSame(Align::Center, EnumValue::from(Align::class, "  CeNtEr\n"));
        self::assertSame(Align::Center, EnumValue::from(Align::class, Align::Center));
    }

    public function test_uppercase_backing_values_match_in_either_case(): void
    {
        self::assertSame(ErrorCorrection::Low, EnumValue::from(ErrorCorrection::class, 'l'));
        self::assertSame(ErrorCorrection::High, EnumValue::from(ErrorCorrection::class, 'H'));
        self::assertSame(ErrorCorrection::Quartile, EnumValue::from(ErrorCorrection::class, ' q '));
    }

    public function test_an_unknown_value_suggests_the_closest(): void
    {
        try {
            EnumValue::from(Align::class, 'centre');
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('centre', $e->getMessage());
            self::assertStringContainsString('center', $e->getMessage());
        }
    }

    public function test_an_unknown_value_without_a_close_match_still_throws(): void
    {
        $this->expectException(InvalidValue::class);
        EnumValue::from(Direction::class, 'zzzzzzzz');
    }

    public function test_another_enum_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        EnumValue::from(Align::class, Direction::Row);
    }

    public function test_case_names_match_too(): void
    {
        self::assertSame(ErrorCorrection::High, EnumValue::from(ErrorCorrection::class, 'high'));
        self::assertSame(ErrorCorrection::Medium, EnumValue::from(ErrorCorrection::class, ' Medium '));
        self::assertSame(QrGradient::InverseDiagonal, EnumValue::from(QrGradient::class, 'inverse-diagonal'));
    }

    public function test_suggestions_are_compared_in_lower_case_and_shown_as_spelled(): void
    {
        try {
            EnumValue::from(ErrorCorrection::class, 'medim');
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('Did you mean "Medium"?', $e->getMessage());
        }
    }

    public function test_a_single_letter_value_is_not_suggested_for_garbage(): void
    {
        try {
            EnumValue::from(ErrorCorrection::class, 'x');
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringNotContainsString('Did you mean', $e->getMessage());
        }
    }
}
