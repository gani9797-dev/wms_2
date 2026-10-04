<?php
declare(strict_types=1);

// Статусы из макета: На сборке, Собрано, Доставляется, Доставлена, Ожидает оплаты, Отменена
// + служебные: Черновик, Архив. Храним в БД как snake_case коды.

enum ShipmentStatus: string
{
    case Draft           = 'draft';            // Черновик (до проведения)
    case Created         = 'created';          // Создан
    case Assembling      = 'assembling';       // На сборке / В сборке
    case Assembled       = 'assembled';        // Собрано
    case Packing         = 'packing';          // На упаковке
    case Packed          = 'packed';           // Упаковано
    case OnCheck         = 'on_check';         // На проверке
    case Checked         = 'checked';          // Проверено
    case Ready           = 'ready';            // Готов к отгрузке / Подготовлено к отгрузке
    case AwaitingPayment = 'awaiting_payment'; // Ожидает оплаты
    case Delivering      = 'delivering';       // Доставляется
    case Delivered       = 'delivered';        // Доставлена
    case Cancelled       = 'cancelled';        // Отменена
    case Archived        = 'archived';         // Архивирована

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Черновик',
            self::Created         => 'Создан',
            self::Assembling      => 'На сборке',
            self::Assembled       => 'Собрано',
            self::Packing         => 'На упаковке',
            self::Packed          => 'Упаковано',
            self::OnCheck         => 'На проверке',
            self::Checked         => 'Проверено',
            self::Ready           => 'Готов к отгрузке',
            self::AwaitingPayment => 'Ожидает оплаты',
            self::Delivering      => 'Доставляется',
            self::Delivered       => 'Доставлена',
            self::Cancelled       => 'Отменена',
            self::Archived        => 'В архиве',
        };
    }

    public static function tryLabel(string $v): string
    {
        try {
            return self::from($v)->label();
        } catch (Throwable $e) {
            return $v;
        }
    }

    // Автомат переходов для ПКМ "Провести / Отменить/Вернуть".
    // Возвращает true если переход разрешен.
    public function canTransitTo(self $to): bool
    {
        return match ($this) {
            self::Draft           => in_array($to, [self::Created, self::Assembling, self::Cancelled], true),
            self::Created         => in_array($to, [self::Assembling, self::Cancelled], true),
            self::Assembling      => in_array($to, [self::Assembled, self::Packing, self::Cancelled], true),
            self::Assembled       => in_array($to, [self::Packing, self::OnCheck, self::AwaitingPayment, self::Delivering, self::Cancelled], true),
            self::Packing         => in_array($to, [self::Packed, self::Cancelled], true),
            self::Packed          => in_array($to, [self::OnCheck, self::Ready, self::Cancelled], true),
            self::OnCheck         => in_array($to, [self::Checked, self::Cancelled], true),
            self::Checked         => in_array($to, [self::Ready, self::AwaitingPayment, self::Cancelled], true),
            self::Ready           => in_array($to, [self::AwaitingPayment, self::Delivering, self::Cancelled], true),
            self::AwaitingPayment => in_array($to, [self::Delivering, self::Cancelled], true),
            self::Delivering      => in_array($to, [self::Delivered, self::Cancelled], true),
            self::Delivered       => in_array($to, [self::Archived], true),
            self::Cancelled       => in_array($to, [self::Assembling, self::Archived], true), // Вернуть в работу
            self::Archived        => false,
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn(self $s) => $s->value, self::cases());
    }
}
