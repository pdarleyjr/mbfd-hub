<?php

declare(strict_types=1);

namespace App\Enums;

enum SessionContextClass: string
{
    case ManagedCity = 'managed_city';
    case EnrolledPhone = 'enrolled_phone';
    case UnmanagedBrowser = 'unmanaged_browser';
    case SharedStation = 'shared_station';
    case KioskOverlay = 'kiosk_overlay';
    case Privileged = 'privileged';
}
