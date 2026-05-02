<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case PHOTOGRAPHER = 'photographer';
    case CUSTOMER_MANAGER = 'customer_manager';
    case POWER_USER = 'power_user';
    case CLIENT = 'client';
}
