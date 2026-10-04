<?php
declare(strict_types=1);

// Статусы из макета "Статусная модель поступления" + бейджи реестра.
// Храним в БД как snake_case коды.

enum ReceiptStatus: string
{
    case Draft       = 'draft';        // Черновик (2)
    case Expected    = 'expected';     // Ожидается (4)
    case Arrived     = 'arrived';      // Прибыл (5)
    case Unloading   = 'unloading';    // Разгружается (6)
    case OnStock     = 'on_stock';     // На складе (7)
    case ReadyAccept = 'ready_accept'; // Готова к приемке (8)
    case Accepting   = 'accepting';    // Приемка (9)
    case ReadyPlace  = 'ready_place';  // Готова к размещению (10)
    case Placing     = 'placing';      // Размещение (11)
    case Closed      = 'closed';       // Закрыто (99)
    case ClosedShort = 'closed_short'; // Закрыто с недостачей (98)
    case Cancelled   = 'cancelled';    // Отменено (3)
    case Archived    = 'archived';     // В архиве (служебный)

    public function label(): string
    {
        return match ($this) {
            self::Draft       => 'Черновик',
            self::Expected    => 'Ожидается',
            self::Arrived     => 'Прибыл',
            self::Unloading   => 'Разгружается',
            self::OnStock     => 'На складе',
            self::ReadyAccept => 'Готова к приемке',
            self::Accepting   => 'Приемка',
            self::ReadyPlace  => 'Готова к размещению',
            self::Placing     => 'Размещение',
            self::Closed      => 'Закрыто',
            self::ClosedShort => 'Закрыто с недостачей',
            self::Cancelled   => 'Отменено',
            self::Archived    => 'В архиве',
        };
    }

    public static function tryLabel(string $v): string
    {
        try {
            return self::from($v)->label();
        } catch (Throwable $e) {
            // Совместимость с подписями чипов: Принято / Частично принято / В процессе
            return match ($v) {
                'accepted' => 'Принято',
                'partial'  => 'Частично принято',
                'process'  => 'В процессе',
                default    => $v,
            };
        }
    }

    // Линейный конвейер приемки + боковые переходы.
    public function canTransitTo(self $to): bool
    {
        return match ($this) {
            self::Draft       => in_array($to, [self::Expected, self::Cancelled], true),
            self::Expected    => in_array($to, [self::Arrived, self::Cancelled], true),
            self::Arrived     => in_array($to, [self::Unloading, self::Cancelled], true),
            self::Unloading   => in_array($to, [self::OnStock, self::Cancelled], true),
            self::OnStock     => in_array($to, [self::ReadyAccept, self::Cancelled], true),
            self::ReadyAccept => in_array($to, [self::Accepting, self::Cancelled], true),
            self::Accepting   => in_array($to, [self::ReadyPlace, self::Placing, self::Cancelled], true),
            self::ReadyPlace  => in_array($to, [self::Placing, self::Cancelled], true),
            self::Placing     => in_array($to, [self::Closed, self::ClosedShort, self::Cancelled], true),
            self::Closed      => in_array($to, [self::Archived], true),
            self::ClosedShort => in_array($to, [self::Archived], true),
            self::Cancelled   => in_array($to, [self::Expected, self::Archived], true), // Вернуть в работу
            self::Archived    => false,
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn(self $s) => $s->value, self::cases());
    }
}
