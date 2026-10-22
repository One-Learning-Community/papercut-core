<?php

declare(strict_types=1);

namespace Papercut\Tree;

enum Region: string
{
    case Header = 'header';
    case Body = 'body';
    case Footer = 'footer';
    case Background = 'background';
}
