<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case OPEN = 'open';
    case PARTLY_PAID = 'partly_paid';
    case PAID = 'paid';
}