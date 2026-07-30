<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 15:53
 */

namespace Tito10047\Calendar\Enum;

enum DayName: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromDate(\DateTimeInterface $date): self
    {
        $numDay = (int)$date->format('N');
        foreach (DayName::cases() as $day) {
            if ($day->value == $numDay) {
                return $day;
            }
        }
        throw new \LogicException('Invalid day number');
    }

    public function getShortName(): string
    {
        return match ($this) {
            self::Monday => 'Mon',
            self::Tuesday => 'Tue',
            self::Wednesday => 'Wed',
            self::Thursday => 'Thu',
            self::Friday => 'Fri',
            self::Saturday => 'Sat',
            self::Sunday => 'Sun',
        };
    }

    /**
     * @return list<DayName>
     */
    public static function all(DayName $startDay = self::Monday): array
    {
        $days = DayName::cases();
        $start = array_search($startDay, $days, strict: true);
        if ($start === false) {
            throw new \LogicException('DayName case not found in cases list');
        }
        return array_merge(array_slice($days, $start), array_slice($days, 0, $start));
    }

    public function getDayNumber(): int
    {
        return $this->value;
    }
}
