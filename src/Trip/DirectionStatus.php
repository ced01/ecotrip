<?php
namespace App\Trip;
enum DirectionStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Empty = 'empty';
    case OutOfCoverage = 'out_of_coverage';
    case Unavailable = 'unavailable';
    case NotRequested = 'not_requested';
}
