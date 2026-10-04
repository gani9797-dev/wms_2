<?php
declare(strict_types=1);

// Статусы модуля "Задания" (макет 7 - TL Модуль Задания от 31.08.2026):
// Новый, В работе, На паузе, Выполнен, Отменено. Храним snake_case коды.

enum TaskStatus: string
{
    case New       = 'new';       // Новый
    case Work      = 'work';      // В работе
    case Paused    = 'paused';    // На паузе
    case Done      = 'done';      // Выполнен
    case Cancelled = 'cancelled'; // Отменено

    public function label(): string
    {
        return match ($this) {
            self::New       => 'Новый',
            self::Work      => 'В работе',
            self::Paused    => 'На паузе',
            self::Done      => 'Выполнен',
            self::Cancelled => 'Отменено',
        };
    }

    public static function tryLabel(string $v): string
    {
        try {
            return self::from($v)->label();
        } catch (Throwable) {
            return $v;
        }
    }

    // Автомат переходов для ПКМ: Приостановить / Вернуть к началу / Выполнить автоматически.
    public function canTransitTo(self $to): bool
    {
        return match ($this) {
            self::New       => in_array($to, [self::Work, self::Paused, self::Cancelled, self::Done], true),
            self::Work      => in_array($to, [self::Paused, self::Done, self::Cancelled, self::New], true),
            self::Paused    => in_array($to, [self::Work, self::New, self::Cancelled, self::Done], true),
            self::Done      => false,
            self::Cancelled => in_array($to, [self::New], true), // Вернуть к началу
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn(self $s) => $s->value, self::cases());
    }
}
