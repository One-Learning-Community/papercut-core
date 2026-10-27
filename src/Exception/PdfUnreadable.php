<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

/** A PDF page could not be drawn: the file is missing, is not a PDF, needs a password, or lacks the page. */
final class PdfUnreadable extends PapercutException
{
}
