[![PHP Tests](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml/badge.svg)](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml)

# Plain PHP Calendar Package

This package provides a simple calendar component that can be used in PHP applications.

## Installation

To install the package, use the following commands:

### Using Composer
```sh
composer require tito10047/php-callendar
```

## Usage

### In PHP
To use the calendar component in your PHP application, include the autoload file and instantiate the calendar class.

```php
require 'vendor/autoload.php';

use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

$calendar = new Calendar(
    date:new DateTime('2021-01-01'),
    daysGenerator:CalendarType::Monthly,
    startDay:DayName::Monday
);
$nextMonthCalendar = $calendar->nextMonth();
$renderer = Renderer::factory(CalendarType::Monthly,'calendar');

$calendar->disableDays(new DateTime('2021-01-01'));
$calendar->disableDaysByName(DayName::Sunday);
$nextMonthCalendar->disableDaysByName(DayName::Sunday);

echo $renderer->render($calendar);
echo $renderer->render($nextMonthCalendar);
```

### In Twig
To use the calendar component in your Twig template, include the calendar template and pass the calendar object to the template.

```php
use Tito10047\Calendar\Calendar;
$calendar = new Calendar(
    date:new DateTime('2021-01-01'),
    daysGenerator:CalendarType::Monthly,
    startDay:DayName::Monday
);
$table = $calendar->getDaysTable();
```

```twig
<table>
{%for weekNum,week in table%}
    <tr>
        {%for dayNum,day in week%}
            {% set classes = 'day' %}
            {% if day.today %}{% set classes = classes ~ ' today' %}{% endif %}
            {% if not day.enabled %}{% set classes = classes ~ ' disabled' %}{% endif %}
            <td>
                {% if not day.ghost %}
                    {{day.date|format('d')}}
                {%endif%}                    
            </td>
        {%endfor%}
    </tr>
{%endfor%}
</table>
```

### Custom Days Generator
You can create your own days generator by implementing the `DaysGeneratorInterface` interface.

```php
use Tito10047\Calendar\Interface\DaysGeneratorInterface;

class CustomDaysGenerator implements DaysGeneratorInterface
{
    public function getDays(\DateTimeImmutable $day, DayName $firstDay):array
    {
        // Your custom logic here
    }
}

$calendar = new Calendar(
    date:new DateTime('2021-01-01'),
    daysGenerator:new CustomDaysGenerator(),
    startDay:DayName::Monday
);
```