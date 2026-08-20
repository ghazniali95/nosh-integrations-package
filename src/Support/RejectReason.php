<?php

namespace Nosh\OmniConnect\Support;

/**
 * Valid order-rejection reasons for Delivery Hero (from the Middleware spec's
 * OrderStatusUpdateRequest.order_rejected.reason enum). A rejection must carry
 * one of these.
 */
final class RejectReason
{
    public const ADDRESS_INCOMPLETE_MISSTATED = 'ADDRESS_INCOMPLETE_MISSTATED';
    public const BAD_WEATHER = 'BAD_WEATHER';
    public const BLACKLISTED = 'BLACKLISTED';
    public const CARD_READER_NOT_AVAILABLE = 'CARD_READER_NOT_AVAILABLE';
    public const CLOSED = 'CLOSED';
    public const CONTENT_WRONG_MISLEADING = 'CONTENT_WRONG_MISLEADING';
    public const FOOD_QUALITY_SPILLAGE = 'FOOD_QUALITY_SPILLAGE';
    public const FRAUD_PRANK = 'FRAUD_PRANK';
    public const ITEM_UNAVAILABLE = 'ITEM_UNAVAILABLE';
    public const LATE_DELIVERY = 'LATE_DELIVERY';
    public const MENU_ACCOUNT_SETTINGS = 'MENU_ACCOUNT_SETTINGS';
    public const MOV_NOT_REACHED = 'MOV_NOT_REACHED';
    public const NO_COURIER = 'NO_COURIER';
    public const NO_PICKER = 'NO_PICKER';
    public const NO_RESPONSE = 'NO_RESPONSE';
    public const OUTSIDE_DELIVERY_AREA = 'OUTSIDE_DELIVERY_AREA';
    public const TECHNICAL_PROBLEM = 'TECHNICAL_PROBLEM';
    public const TEST_ORDER = 'TEST_ORDER';
    public const TOO_BUSY = 'TOO_BUSY';
    public const UNABLE_TO_FIND = 'UNABLE_TO_FIND';
    public const UNABLE_TO_PAY = 'UNABLE_TO_PAY';
    public const UNPROFESSIONAL_BEHAVIOUR = 'UNPROFESSIONAL_BEHAVIOUR';
    public const WILL_NOT_WORK_WITH_PLATFORM = 'WILL_NOT_WORK_WITH_PLATFORM';
    public const WRONG_ORDER_ITEMS_DELIVERED = 'WRONG_ORDER_ITEMS_DELIVERED';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::ADDRESS_INCOMPLETE_MISSTATED, self::BAD_WEATHER, self::BLACKLISTED,
            self::CARD_READER_NOT_AVAILABLE, self::CLOSED, self::CONTENT_WRONG_MISLEADING,
            self::FOOD_QUALITY_SPILLAGE, self::FRAUD_PRANK, self::ITEM_UNAVAILABLE,
            self::LATE_DELIVERY, self::MENU_ACCOUNT_SETTINGS, self::MOV_NOT_REACHED,
            self::NO_COURIER, self::NO_PICKER, self::NO_RESPONSE, self::OUTSIDE_DELIVERY_AREA,
            self::TECHNICAL_PROBLEM, self::TEST_ORDER, self::TOO_BUSY, self::UNABLE_TO_FIND,
            self::UNABLE_TO_PAY, self::UNPROFESSIONAL_BEHAVIOUR, self::WILL_NOT_WORK_WITH_PLATFORM,
            self::WRONG_ORDER_ITEMS_DELIVERED,
        ];
    }

    public static function isValid(string $reason): bool
    {
        return in_array($reason, self::all(), true);
    }
}
