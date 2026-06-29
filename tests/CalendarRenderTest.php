<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Renderer;

class CalendarRenderTest extends TestCase
{
    public function testRenderDays(): void
    {
        // November 2024 with Monday start: always 5 weeks (Oct 28 – Dec 01), 35 cells.
        $calendar = new Calendar(
            new DateTimeImmutable('2024-11-05'),
            CalendarType::Monthly,
            DayName::Monday,
        );
        $renderer = Renderer::factory(CalendarType::Monthly, 'calendar');
        $html = $renderer->render($calendar);
        $this->assertNotEmpty($html);
        // 1 header <tr> + 5 week <tr> from WeekRowRenderer
        $this->assertSame(6, substr_count($html, '<tr>'));
        $this->assertSame(35, substr_count($html, '<td'));
    }
}
