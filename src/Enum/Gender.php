<?php

declare(strict_types=1);

namespace App\Enum;

enum Gender: string
{
    case Male = 'MALE';
    case Female = 'FEMALE';
    case Other = 'OTHER';
}
