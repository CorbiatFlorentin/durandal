<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Http;

use Durandal\Http\RequestInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestInfo::class)]
final class RequestInfoTest extends TestCase
{
    public function testNormalizesMethodHostAndHeaderNames(): void
    {
        $request = new RequestInfo('post', 'API.Example.COM', '/Orders', ['X-Trace' => ['a', 'b']]);

        self::assertSame('POST', $request->method);
        self::assertSame('api.example.com', $request->host);
        self::assertSame('/Orders', $request->path);
        self::assertSame(['x-trace' => ['a', 'b']], $request->headers);
    }

    public function testEmptyPathBecomesRoot(): void
    {
        self::assertSame('/', (new RequestInfo('GET', 'example.com', ''))->path);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new RequestInfo('GET', 'example.com', '/', ['Idempotency-Key' => [3 => 'k1']]);

        self::assertSame(['k1'], $request->header('IDEMPOTENCY-KEY'));
        self::assertSame([], $request->header('Authorization'));
    }

    public function testNumericHeaderNamesCanBeLookedUp(): void
    {
        $request = new RequestInfo('GET', 'example.com', '/', [123 => ['v']]);

        self::assertSame(['v'], $request->header('123'));
    }
}
