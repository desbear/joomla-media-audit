<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpFetcherTest extends TestCase
{
    /**
     * @return  array<string, array{string, bool}>
     */
    public static function addresses(): array
    {
        return [
            'public'         => ['93.184.216.34', true],
            'loopback'       => ['127.0.0.1', false],
            'private 10'     => ['10.1.2.3', false],
            'private 192'    => ['192.168.1.10', false],
            'private 172'    => ['172.16.0.1', false],
            'link local'     => ['169.254.169.254', false],
            'zero'           => ['0.0.0.0', false],
            'loopback v6'    => ['::1', false],
            'private v6'     => ['fd00::1', false],
            'not an address' => ['example', false],
            'shared'                 => ['100.100.100.200', false],
            'benchmarking'           => ['198.18.0.1', false],
            'multicast'              => ['224.0.0.1', false],
            'next to shared'         => ['100.128.0.1', true],
            'IPv6 of the world'      => ['2606:4700:4700::1111', true],
            'mapped loopback'        => ['::ffff:127.0.0.1', false],
            'mapped, written long'   => ['0:0:0:0:0:ffff:7f00:1', false],
            'mapped link local'      => ['0:0:0:0:0:ffff:a9fe:a9fe', false],
            'mapped and public'      => ['::ffff:93.184.216.34', true],
            'translated loopback'    => ['0064:ff9b::7f00:1', false],
            'compatible'             => ['::7f00:1', false],
            'site local'             => ['fec0::1', false],
            'link local v6'          => ['fe80::1', false],
            'multicast v6'           => ['ff02::1', false],
            '6to4'                   => ['2002:7f00:1::', false],
            'documentation'          => ['2001:db8::1', false],
            'nothing'                => ['', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testIsPublicAddress(string $address, bool $expected): void
    {
        $this->assertSame($expected, HttpFetcher::isPublicAddress($address));
    }

    public function testHostsThatAreAddresses(): void
    {
        $this->assertFalse(HttpFetcher::isPublicHost('127.0.0.1'));
        $this->assertFalse(HttpFetcher::isPublicHost('[::1]'));
        $this->assertFalse(HttpFetcher::isPublicHost('localhost'));
        $this->assertFalse(HttpFetcher::isPublicHost(''));

        // Numbers that some resolvers read as addresses
        foreach (['2130706433', '0x7f000001', '0x7f.1', '127.1', '017700000001', '010.0.0.1', '0'] as $host) {
            $this->assertFalse(HttpFetcher::isPublicHost($host), $host);
        }
    }

    public function testUrlsThatAreNotAsked(): void
    {
        $handle = new \ReflectionMethod(HttpFetcher::class, 'handle');

        foreach (['http://example.com/a.png', 'https://example.com:8443/a.png', 'http://example.com:8080/a.png'] as $url) {
            $this->assertNotNull($handle->invoke(new HttpFetcher(), $url, 5, false), $url);
        }

        foreach ([
            'ftp://example.com/a.png', 'file:///etc/passwd', 'gopher://example.com/', 'http://user@example.com/', 'http://127.0.0.1/',
            'http://example.com@127.0.0.1/', 'http://127.0.0.1#@example.com/', 'http://example.com\\@127.0.0.1/', 'http://[::1]/',
            'http://[0:0:0:0:0:ffff:7f00:1]/', 'http://2130706433/', '//example.com/a.png', 'http:///a.png', 'http://exa mple.com/', 'http://localhost/',
            'http://example.com:22/', 'https://example.com:6379/a.png', 'http://example.com:25/',
        ] as $url) {
            $this->assertNull($handle->invoke(new HttpFetcher(), $url, 5, false), $url);
        }
    }

    public function testADownloadFromTheLocalNetworkIsRefused(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'ma');
        $result = (new HttpFetcher())->download('http://127.0.0.1:1/x.png', $target);

        $this->assertSame(0, $result['status']);
        $this->assertSame('host of the local network', $result['error']);

        unlink($target);
    }

    public function testWhatCanBeAnImage(): void
    {
        foreach (['image/png', 'image/svg+xml', 'application/octet-stream', 'binary/octet-stream', ''] as $mime) {
            $this->assertTrue(\Gss\Component\Mediaaudit\Administrator\Scanner\ExternalChecker::canBeImage($mime), $mime);
        }

        foreach (['text/html', 'text/plain', 'application/json', 'application/xml'] as $mime) {
            $this->assertFalse(\Gss\Component\Mediaaudit\Administrator\Scanner\ExternalChecker::canBeImage($mime), $mime);
        }
    }

    public function testHostOf(): void
    {
        $this->assertSame('faq.example.com', HttpFetcher::hostOf('https://FAQ.Example.com:8443/a?b=c'));
        $this->assertSame('', HttpFetcher::hostOf('images/a.jpg'));
    }
}
