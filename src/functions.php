<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Value\Fr;
use Papercut\Value\Length;
use Papercut\Value\Percent;
use Papercut\Value\Unit;

function pt(float $value): Length { return Length::of($value, Unit::Pt); }
function px(float $value): Length { return Length::of($value, Unit::Px); }
function mm(float $value): Length { return Length::of($value, Unit::Mm); }
function cm(float $value): Length { return Length::of($value, Unit::Cm); }
function in(float $value): Length { return Length::of($value, Unit::In); }
function percent(float $value): Percent { return Percent::of($value); }
function fr(float $value): Fr { return Fr::of($value); }
