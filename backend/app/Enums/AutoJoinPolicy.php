<?php

namespace App\Enums;

enum AutoJoinPolicy: string
{
    case IMMEDIATE = 'immediate';
    case REQUIRES_INVITE = 'requires_invite';
    case DISABLED = 'disabled';
}
