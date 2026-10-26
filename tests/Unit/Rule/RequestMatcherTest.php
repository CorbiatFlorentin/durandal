<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Rule;

use Durandal\Http\RequestInfo;
use Durandal\Rule\RequestMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestMatcher::class)]
final class RequestMatcherTest extends TestCase
{
    public function testAnyMatchesEverything(): void
    {
        self::assertTrue(RequestMatcher::any()->matches(self::request()));
    }

    public function testBuilderReturnsNewInstances(): void
    {
        $any = RequestMatcher::any();

        self::assertNotSame($any, $any->method('GET'));
        self::assertNotSame($any, $any->host('x'));
        self::assertNotSame($any, $any->path('/'));
        self::assertNotSame($any, $any->header('x'));
        self::assertTrue($any->matches(self::request(method: 'DELETE')), 'l’original ne doit pas changer');
    }

    public function testMethodIsCaseInsensitiveAndAcceptsSeveralValues(): void
    {
        $matcher = RequestMatcher::any()->method('post', 'Put');

        self::assertTrue($matcher->matches(self::request(method: 'POST')));
        self::assertTrue($matcher->matches(self::request(method: 'put')));
        self::assertFalse($matcher->matches(self::request(method: 'GET')));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function hostCases(): iterable
    {
        yield 'identique' => ['repo.packagist.org', 'repo.packagist.org', true];
        yield 'casse ignorée' => ['Repo.Packagist.ORG', 'repo.packagist.org', true];
        yield 'joker de sous-domaine' => ['*.packagist.org', 'repo.packagist.org', true];
        yield 'point littéral' => ['repo.packagist.org', 'repoxpackagist.org', false];
        yield 'ancré au début' => ['packagist.org', 'repo.packagist.org', false];
        yield 'ancré à la fin' => ['packagist.org', 'packagist.org.evil', false];
        yield 'pas de saut de ligne final' => ['packagist.org', "packagist.org\n", false];
    }

    #[DataProvider('hostCases')]
    public function testHostPattern(string $pattern, string $host, bool $expected): void
    {
        self::assertSame($expected, RequestMatcher::any()->host($pattern)->matches(self::request(host: $host)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function pathCases(): iterable
    {
        yield 'identique' => ['/v1/orders', '/v1/orders', true];
        yield 'joker' => ['/v1/*', '/v1/orders/42', true];
        yield 'casse respectée' => ['/v1/orders', '/V1/orders', false];
        yield 'préfixe insuffisant' => ['/v1', '/v1/orders', false];
        yield 'jokers consécutifs' => ['/v1/**', '/v1/orders/42', true];
        yield 'joker sur un saut de ligne' => ['/v1/*', "/v1/a\nb", true];
    }

    #[DataProvider('pathCases')]
    public function testPathPattern(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, RequestMatcher::any()->path($pattern)->matches(self::request(path: $path)));
    }

    public function testHeaderPresence(): void
    {
        $matcher = RequestMatcher::any()->header('Idempotency-Key');

        self::assertTrue($matcher->matches(self::request(headers: ['idempotency-key' => ['abc']])));
        self::assertFalse($matcher->matches(self::request()));
    }

    public function testHeaderValueMustBeOneOfTheSentValues(): void
    {
        $matcher = RequestMatcher::any()->header('Accept', 'application/json');

        self::assertTrue($matcher->matches(self::request(headers: ['Accept' => ['text/html', 'application/json']])));
        self::assertFalse($matcher->matches(self::request(headers: ['Accept' => ['text/html']])));
        self::assertFalse($matcher->matches(self::request()));
    }

    public function testAllCriteriaMustMatch(): void
    {
        $matcher = RequestMatcher::any()->method('POST')->host('api.example.com')->path('/orders')->header('X-Retry');
        $headers = ['X-Retry' => ['1']];

        self::assertTrue($matcher->matches(self::request('POST', 'api.example.com', '/orders', $headers)));
        self::assertFalse($matcher->matches(self::request('GET', 'api.example.com', '/orders', $headers)));
        self::assertFalse($matcher->matches(self::request('POST', 'other.example.com', '/orders', $headers)));
        self::assertFalse($matcher->matches(self::request('POST', 'api.example.com', '/users', $headers)));
        self::assertFalse($matcher->matches(self::request('POST', 'api.example.com', '/orders')));
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function request(
        string $method = 'GET',
        string $host = 'api.example.com',
        string $path = '/orders',
        array $headers = [],
    ): RequestInfo {
        return new RequestInfo($method, $host, $path, $headers);
    }
}
