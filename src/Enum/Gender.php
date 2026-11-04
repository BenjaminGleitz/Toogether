<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum Gender: string implements TranslatableInterface
{
    case Male = 'MALE';
    case Female = 'FEMALE';
    case Other = 'OTHER';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::Male => $translator->trans('user.gender.male', locale: $locale),
            self::Female => $translator->trans('user.gender.female', locale: $locale),
            self::Other => $translator->trans('user.gender.other', locale: $locale),
        };
    }
}
