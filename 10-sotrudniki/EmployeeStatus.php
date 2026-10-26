<?php
declare(strict_types=1);

// Статусы сотрудника (реестр и карточка): active=Активный, blocked=Заблокирован, fired=Уволен.
final class EmployeeStatus
{
    public const ACTIVE = 'active';
    public const BLOCKED = 'blocked';
    public const FIRED = 'fired';

    public static function values(): array
    {
        return [self::ACTIVE, self::BLOCKED, self::FIRED];
    }

    public static function label(string $s): string
    {
        return match ($s) {
            self::ACTIVE => 'Активный',
            self::BLOCKED => 'Заблокирован',
            self::FIRED => 'Уволен',
            default => $s,
        };
    }

    /**
     * Статус карточки по полям таблицы: флаг is_blocked важнее строки status.
     */
    public static function fromFlags(int $isBlocked, string $status): string
    {
        if ($isBlocked) {
            return self::BLOCKED;
        }
        $s = trim($status);
        return $s !== '' ? $s : self::ACTIVE;
    }
}
