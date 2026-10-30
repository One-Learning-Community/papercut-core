<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\Barcode\{ErrorCorrection, QrEyes, QrGradient, QrShape};
use Papercut\Exception\InvalidValue;
use Papercut\{Column, Image, ListNode, Place, QrCode, Row, TableCell, Text, View};
use Papercut\Style\{Align, Anchor, BorderStyle, Direction, ImageFit, Justify, ListMarker, TextAlign};
use Papercut\Value\Length;
use PHPUnit\Framework\TestCase;

use function Papercut\mm;

/** Setters that take an option also take its string value, as the Blade attributes do. */
final class StringSettersTest extends TestCase
{
    public function test_view_setters(): void
    {
        $v = View::make()->direction('Row')->justify('space-between')->align('center');
        self::assertEquals(View::make()->direction(Direction::Row)->justify(Justify::SpaceBetween)->align(Align::Center)->style(), $v->style());
        self::assertEquals(Column::make()->align(Align::Center)->style(), Column::make()->align('center')->style());
        self::assertEquals(Row::make()->align(Align::End)->style(), Row::make()->align(' END ')->style());
    }

    public function test_style_concern_setters(): void
    {
        self::assertEquals(View::make()->alignSelf(Align::End)->style(), View::make()->alignSelf('end')->style());
        self::assertEquals(Text::make('x')->textAlign(TextAlign::Justify)->style(), Text::make('x')->textAlign('justify')->style());
        self::assertEquals(
            View::make()->border(mm(1), 'red-500', BorderStyle::Dashed)->style(),
            View::make()->border(mm(1), 'red-500', 'dashed')->style(),
        );
        self::assertEquals(View::make()->border(mm(1))->style(), View::make()->border(mm(1), null, null)->style());
    }

    public function test_table_cell_image_list_place(): void
    {
        self::assertEquals(TableCell::make('x')->align(TextAlign::Right)->style(), TableCell::make('x')->align('right')->style());
        self::assertSame(ImageFit::Cover, Image::bytes('x')->fit('cover')->fitMode());
        self::assertSame(ListMarker::LowerRoman, ListNode::ordered(Text::make('a'))->marker('lower-roman')->markerStyle());
        self::assertSame(Anchor::BottomRight, Place::at(mm(1), mm(1))->anchor('bottom-right')->anchorPoint());
    }

    public function test_qr_setters(): void
    {
        $qr = QrCode::make('hello');
        self::assertSame(ErrorCorrection::Low, $qr->errorCorrection('l')->level());
        self::assertSame(ErrorCorrection::High, $qr->errorCorrection('H')->level());
        self::assertSame(ErrorCorrection::High, $qr->errorCorrection('high')->level());
        self::assertSame(ErrorCorrection::Low, QrCode::make('hello', 'low')->level());
        self::assertEquals($qr->gradient('#112233', '#445566', QrGradient::Radial), $qr->gradient('#112233', '#445566', 'radial'));
        self::assertEquals($qr->shape(QrShape::Dots), $qr->shape('dots'));
        self::assertEquals($qr->eyes(QrEyes::Dot), $qr->eyes('dot'));
    }

    public function test_a_module_grid_keeps_its_logic_error_before_string_parsing(): void
    {
        $this->expectException(\LogicException::class);
        QrCode::fromModules([[1, 0], [0, 1]])->errorCorrection('nonsense');
    }

    public function test_unknown_strings_throw_with_a_suggestion(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessageMatches('/centre.*center/s');
        View::make()->align('centre');
    }

    public function test_unknown_border_style_throws(): void
    {
        $this->expectException(InvalidValue::class);
        View::make()->border(Length::parse('1mm'), null, 'wavy');
    }
}
