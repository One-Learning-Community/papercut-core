<?php

declare(strict_types=1);

namespace Papercut\Tree;

enum Region: string
{
    case Header = 'header';
    case Body = 'body';
    case Footer = 'footer';
    case Background = 'background';
    case Overlay = 'overlay';
    case FirstHeader = 'first-header';
    case EvenHeader = 'even-header';
    case FirstFooter = 'first-footer';
    case EvenFooter = 'even-footer';

    /** Whether this is the header or one of its page variants. */
    public function isHeader(): bool
    {
        return $this === self::Header || $this === self::FirstHeader || $this === self::EvenHeader;
    }

    /** Whether this is the footer or one of its page variants. */
    public function isFooter(): bool
    {
        return $this === self::Footer || $this === self::FirstFooter || $this === self::EvenFooter;
    }
}
