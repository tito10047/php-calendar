<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 18:54
 */

namespace Tito10047\Calendar\Interface;

interface EventInterface
{
    public function getFrom(): \DateTimeImmutable;
    public function getTo(): \DateTimeImmutable;
    public function getTitle(): string;
    public function getDescription(): string;
    public function getStatus(): string;
}
