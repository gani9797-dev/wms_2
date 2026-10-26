<?php
declare(strict_types=1);

// Статусы ячейки из схемы (бейджи реестра).
// Храним в БД как snake_case коды.

final class CellStatus
{
    public const FREE = 'free';           // Свободна
    public const BUSY = 'busy';           // Занята
    public const RESERVED = 'reserved';   // Зарезервирована
    public const BLOCKED = 'blocked';     // Заблокирована

    /** @return string[] */
    public static function values(): array
    {
        return [self::FREE, self::BUSY, self::RESERVED, self::BLOCKED];
    }

    public static function label(string $v): string
    {
        return match ($v) {
            self::FREE     => 'Свободна',
            self::BUSY     => 'Занята',
            self::RESERVED => 'Зарезервирована',
            self::BLOCKED  => 'Заблокирована',
            default        => $v,
        };
    }

    public static function zoneLabel(?string $v): string
    {
        return match ($v) {
            'storage'   => 'Хранение',
            'shipment'  => 'Отгрузка',
            'receiving' => 'Приёмка',
            'quarantine'=> 'Карантин',
            default     => (string)$v,
        };
    }

    /** Базовые коды зон ячейки (справочник storage_zones может дополнять). */
    public const ZONES = ['storage', 'shipment', 'receiving', 'quarantine'];

    /** @return string[] */
    public static function zones(): array
    {
        return self::ZONES;
    }

    // --- Дополнительные зоны ячейки (ТСД «Отбор по зоне») ---
    // У ячейки может быть несколько зон отбора:
    //   manual — Ручной отбор: задание падает только роли Кладовщик;
    //   driver — Отбор водителем: задание падает только роли Водитель.
    public const PICK_MANUAL = 'manual';
    public const PICK_DRIVER = 'driver';

    /** @return string[] */
    public static function pickZones(): array
    {
        return [self::PICK_MANUAL, self::PICK_DRIVER];
    }

    public static function pickZoneLabel(?string $v): string
    {
        return match ($v) {
            self::PICK_MANUAL => 'Ручной отбор',
            self::PICK_DRIVER => 'Отбор водителем',
            default           => (string)$v,
        };
    }

    /** Роль ТСД, которой падает зона отбора. */
    public static function pickZoneRole(string $v): string
    {
        return $v === self::PICK_DRIVER ? 'driver' : 'storekeeper';
    }

    /** Зона отбора по роли ТСД. */
    public static function pickZoneByRole(string $role): string
    {
        return $role === 'driver' ? self::PICK_DRIVER : self::PICK_MANUAL;
    }

    /** Нормализация входящего значения зоны отбора (пустое значение — не зона). */
    public static function normalizePickZone(mixed $v): ?string
    {
        $s = strtolower(trim((string)($v ?? '')));
        if ($s === '') {
            return null;
        }
        $map = [
            'manual' => self::PICK_MANUAL, 'ручной' => self::PICK_MANUAL,
            'ручной отбор' => self::PICK_MANUAL, 'ручнойотбор' => self::PICK_MANUAL,
            'driver' => self::PICK_DRIVER, 'водитель' => self::PICK_DRIVER,
            'отбор водителем' => self::PICK_DRIVER, 'отборводителем' => self::PICK_DRIVER,
        ];
        return $map[$s] ?? null;
    }

    /** @param mixed $v массив/CSV кодов зон отбора
     *  @return string[] нормализованные коды зон */
    public static function normalizePickZones(mixed $v): array
    {
        $raw = [];
        if (is_array($v)) {
            $raw = $v;
        } elseif (is_string($v) && trim($v) !== '') {
            $raw = preg_split('/[,\s;]+/u', $v);
        }
        $out = [];
        foreach ($raw as $one) {
            $z = self::normalizePickZone($one);
            if ($z !== null && !in_array($z, $out, true)) {
                $out[] = $z;
            }
        }
        return $out;
    }

    public static function typeLabel(?string $v): string
    {
        return match ($v) {
            'pallet' => 'Паллетная',
            'box'    => 'Коробочная',
            default  => (string)$v,
        };
    }

    // Пересчет статуса по факту: заблокированные и резерв не трогаем.
    public static function actual(string $current, float $qty): string
    {
        if (in_array($current, [self::BLOCKED, self::RESERVED], true)) {
            return $current;
        }
        return $qty > 0 ? self::BUSY : self::FREE;
    }
}
