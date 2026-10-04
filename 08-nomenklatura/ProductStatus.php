<?php
declare(strict_types=1);

// Статусы карточки номенклатуры: active=Активный, inactive=Не активный (заблокирован), archived=в архиве.
final class ProductStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const ARCHIVED = 'archived';

    public static function values(): array
    {
        return [self::ACTIVE, self::INACTIVE, self::ARCHIVED];
    }

    public static function label(string $s): string
    {
        return match ($s) {
            self::ACTIVE => 'Активный',
            self::INACTIVE => 'Не активный',
            self::ARCHIVED => 'В архиве',
            default => $s,
        };
    }

    public static function fromActive(int $isActive, int $isArchived): string
    {
        if ($isArchived) return self::ARCHIVED;
        return $isActive ? self::ACTIVE : self::INACTIVE;
    }
}
