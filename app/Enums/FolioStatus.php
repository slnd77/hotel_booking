<?php

namespace App\Enums;

enum FolioStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Settled = 'settled';
}
