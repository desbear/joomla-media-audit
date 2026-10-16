<?php

/**
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Mediaaudit\Tests\Unit;

use Gss\Component\Mediaaudit\Administrator\Service\HttpFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Against a server of PHP on this machine. It is of the local network, so the host is named as
 * one that may be, the way an administrator names hosts in the options.
 */
final class HttpFetcherRedirectTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static int $port = 0;

    private string $target = '';

    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('curl_init') || !\function_exists('proc_open')) {
            return;
        }

        $socket     = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);

        fclose($socket);

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, \dirname(__DIR__) . '/Fixtures/redirects.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes
        );

        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);

            if ($connection) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        if (!\is_resource(self::$server)) {
            $this->markTestSkipped('No server of PHP to ask.');
        }

        $this->target = (string) tempnam(sys_get_temp_dir(), 'ma');
    }

    protected function tearDown(): void
    {
        if ($this->target !== '' && is_file($this->target)) {
            unlink($this->target);
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function testAnImageOfAHostThatWasNamedIsDownloaded(): void
    {
        $result = (new HttpFetcher())->download($this->url('/image'), $this->target, 1048576, 5, true);

        $this->assertSame(['status' => 200, 'error' => '', 'mime' => 'image/png'], array_intersect_key($result, ['status' => 0, 'error' => 0, 'mime' => 0]));
        $this->assertSame('image/png', getimagesize($this->target)['mime']);
    }

    public function testTheSameHostIsRefusedWhenItWasNotNamed(): void
    {
        $result = (new HttpFetcher())->download($this->url('/image'), $this->target, 1048576, 5, false);

        $this->assertSame(HttpFetcher::REFUSED, $result['error']);
        $this->assertSame(0, filesize($this->target));
    }

    public function testARedirectToTheSameHostIsFollowedAndWhatItSaidIsNotKept(): void
    {
        $result = (new HttpFetcher())->download($this->url('/to-self'), $this->target, 1048576, 5, true);

        $this->assertSame('', $result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('image/png', getimagesize($this->target)['mime']);
        $this->assertStringNotContainsString('redirect', (string) file_get_contents($this->target));
    }

    public function testARedirectToAnotherHostOfTheLocalNetworkIsRefused(): void
    {
        $result = (new HttpFetcher())->download($this->url('/to-other'), $this->target, 1048576, 5, true);

        $this->assertSame(HttpFetcher::REFUSED_REDIRECT, $result['error']);
        $this->assertSame(0, $result['size']);
        $this->assertSame(0, filesize($this->target));
    }

    public function testARedirectToAFileIsRefused(): void
    {
        $result = (new HttpFetcher())->download($this->url('/to-file'), $this->target, 1048576, 5, true);

        $this->assertNotSame('', $result['error']);
        $this->assertSame(0, filesize($this->target));
    }

    public function testWhatIsLargerUnpackedThanItMayBeIsCutOff(): void
    {
        $result = (new HttpFetcher())->download($this->url('/packed'), $this->target, 1048576, 10, true);

        $this->assertSame('larger than 1048576 bytes', $result['error']);
        $this->assertSame(0, filesize($this->target));
    }

    public function testRedirectsWithoutEndAreGivenUp(): void
    {
        $result = (new HttpFetcher())->download($this->url('/loop'), $this->target, 1048576, 5, true);

        $this->assertSame('too many redirects', $result['error']);
        $this->assertSame(0, filesize($this->target));
    }

    public function testCheckingFollowsAndRefusesTheSameWay(): void
    {
        $answers = [];
        $urls    = [$this->url('/image'), $this->url('/to-self'), $this->url('/to-other'), $this->url('/loop'), $this->url('/none')];

        (new HttpFetcher())->check(
            $urls,
            static function (string $url, int $status, string $error, string $mime) use (&$answers): void {
                $answers[(string) parse_url($url, PHP_URL_PATH)] = [$status, $error];
            },
            2,
            5,
            0,
            ['127.0.0.1']
        );

        $this->assertSame([200, ''], $answers['/image']);
        $this->assertSame([200, ''], $answers['/to-self']);
        $this->assertSame([0, HttpFetcher::REFUSED_REDIRECT], $answers['/to-other']);
        $this->assertSame([0, 'too many redirects'], $answers['/loop']);
        $this->assertSame(404, $answers['/none'][0]);
    }

    public function testCheckingAsksNoHostOfTheLocalNetworkThatWasNotNamed(): void
    {
        $answers = [];

        (new HttpFetcher())->check(
            [$this->url('/image'), 'http://169.254.169.254/latest/meta-data/', 'http://localhost:' . self::$port . '/image'],
            static function (string $url, int $status, string $error) use (&$answers): void {
                $answers[] = [$status, $error];
            },
            2,
            2,
            0
        );

        $this->assertSame([[0, HttpFetcher::REFUSED], [0, HttpFetcher::REFUSED], [0, HttpFetcher::REFUSED]], $answers);
    }
}
