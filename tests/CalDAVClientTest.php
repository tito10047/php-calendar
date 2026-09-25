<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\ICal\CalDAVClient;

final class CalDAVClientTest extends TestCase
{
    private const MULTISTATUS = '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        . '<d:response><d:href>/cal/e1.ics</d:href><d:propstat><d:prop><c:calendar-data>'
        . "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:e1\r\nDTSTART:20250601T100000Z\r\nSUMMARY:Event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"
        . '</c:calendar-data></d:prop></d:propstat></d:response></d:multistatus>';

    public function testFetchEventsThroughTransport(): void
    {
        $calls  = [];
        $client = (new CalDAVClient('https://dav.example.com/cal'))
            ->authenticate('alice', 's3cret')
            ->withTransport(static function (string $method, string $url, string $body, array $headers) use (&$calls): array {
                $calls[] = [$method, $url, $headers];
                return ['status' => 207, 'body' => self::MULTISTATUS];
            });

        $events = $client->fetchEvents(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'));

        $this->assertCount(1, $events);
        $this->assertSame('e1', $events[0]->uid);
        $this->assertSame('REPORT', $calls[0][0]);
        $this->assertSame('https://dav.example.com/cal/', $calls[0][1]);
        $this->assertContains('Authorization: Basic ' . base64_encode('alice:s3cret'), $calls[0][2]);
    }

    public function testUnauthorizedIsAnErrorNotAnEmptyResult(): void
    {
        $client = (new CalDAVClient('https://dav.example.com/cal'))
            ->withTransport(static fn () => ['status' => 401, 'body' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('401');
        $client->fetchEvents(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'));
    }

    public function testCrossOriginRedirectIsRefused(): void
    {
        $urls   = [];
        $client = (new CalDAVClient('https://dav.example.com/cal'))
            ->authenticate('alice', 's3cret')
            ->withTransport(static function (string $method, string $url) use (&$urls): array {
                $urls[] = $url;
                return ['status' => 307, 'body' => '', 'location' => 'https://attacker.example.net/steal'];
            });

        try {
            $client->listCalendars();
            $this->fail('Cross-origin redirect must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different origin', $e->getMessage());
        }
        $this->assertSame(['https://dav.example.com/cal/'], $urls, 'credentials must never reach the redirect target');
    }

    public function testSameOriginRedirectIsFollowed(): void
    {
        $client = (new CalDAVClient('https://dav.example.com/.well-known/caldav'))
            ->withTransport(static fn (string $method, string $url) => $url === 'https://dav.example.com/.well-known/caldav/'
                ? ['status' => 301, 'body' => '', 'location' => '/remote.php/dav/']
                : ['status' => 207, 'body' => '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote.php/dav/cal/</d:href></d:response></d:multistatus>']);

        $this->assertSame(['/remote.php/dav/cal/'], $client->listCalendars());
    }

    public function testMalformedXmlAndDtdAreErrors(): void
    {
        foreach (['<not-xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'] as $body) {
            $client = (new CalDAVClient('https://dav.example.com/cal'))
                ->withTransport(static fn () => ['status' => 207, 'body' => $body]);
            try {
                $client->listCalendars();
                $this->fail('Malformed XML / DTD must be rejected');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testResponseSizeLimit(): void
    {
        $client = (new CalDAVClient('https://dav.example.com/cal'))
            ->withMaxResponseSize(10)
            ->withTransport(static fn () => ['status' => 207, 'body' => str_repeat('x', 11)]);

        $this->expectException(\RuntimeException::class);
        $client->listCalendars();
    }

    public function testCredentialsAreNotSentOverPlainHttp(): void
    {
        $client = (new CalDAVClient('http://dav.example.com/cal'))
            ->authenticate('alice', 's3cret')
            ->withTransport(static fn () => ['status' => 207, 'body' => '']);

        try {
            $client->listCalendars();
            $this->fail('Credentials over http:// must be refused');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $client->allowInsecureHttp()->listCalendars());
    }

    public function testBaseUrlMustBeHttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CalDAVClient('file:///etc/passwd');
    }
}
