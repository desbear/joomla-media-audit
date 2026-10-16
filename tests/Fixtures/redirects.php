<?php

/**
 * What the tests of HttpFetcher ask: php -S 127.0.0.1:<port> redirects.php
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$port = (int) $_SERVER['SERVER_PORT'];

switch ($path) {
    case '/image':
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        break;

    case '/to-self':
        header('Location: /image', true, 302);
        echo 'What a redirect says is no part of the image.';
        break;

    case '/to-other':
        // The same server by another name: another host, of the local network
        header('Location: http://localhost:' . $port . '/image', true, 302);
        break;

    case '/packed':
        // A few kilobytes on the wire, 20 MB when unpacked
        header('Content-Type: image/png');
        header('Content-Encoding: gzip');
        echo gzencode(str_repeat("\0", 20971520), 9);
        break;

    case '/loop':
        header('Location: /loop', true, 302);
        break;

    case '/to-file':
        header('Location: file:///etc/hosts', true, 302);
        break;

    default:
        http_response_code(404);
}
