<?php

/**
 * Checks the web services and the tools of the MCP server over HTTP, in the LOCAL development site:
 * report, references, scan, quarantine, restore, and what a request without a token gets.
 *
 * Every request goes to 127.0.0.1, whatever the name of the site resolves to.
 *
 * The requests are made with the API token of a Super User of the local site. The token is worked
 * out from what the site has stored, used in the headers of the requests, and never printed. When
 * the user has no token, one is made for the time of the test and removed again.
 *
 * Usage: php verify-api.php [--root=/path/to/site] [--domain=site.test]
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'domain::']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');
$domain  = $options['domain'] ?? (getenv('MEDIAAUDIT_DOMAIN') ?: 'localhost');

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

require_once $root . '/configuration.php';

$config        = new JConfig();
[$host, $port] = array_pad(explode(':', $config->host), 2, 3306);
$db            = new mysqli($host, $config->user, $config->password, $config->db, (int) $port);
$prefix        = $config->dbprefix;

$db->set_charset('utf8mb4');

$failed = 0;
$check  = static function (string $what, bool $ok, string $detail = '') use (&$failed): void {
    $failed += $ok ? 0 : 1;
    echo $ok ? 'ok      ' : 'FAILED  ', $what, $detail === '' ? '' : '  (' . $detail . ')', PHP_EOL;
};
$one = static function (string $sql) use ($db, $prefix) {
    $row = $db->query(str_replace('#__', $prefix, $sql))->fetch_row();

    return $row[0] ?? null;
};

// ---------------------------------------------------------------------------------------------
// The token
// ---------------------------------------------------------------------------------------------

$userId  = (int) $one('SELECT MIN(u.id) FROM #__users u INNER JOIN #__user_usergroup_map m ON m.user_id = u.id WHERE m.group_id = 8 AND u.block = 0');
$seed    = $one("SELECT profile_value FROM #__user_profiles WHERE user_id = $userId AND profile_key = 'joomlatoken.token'");
$enabled = $one("SELECT profile_value FROM #__user_profiles WHERE user_id = $userId AND profile_key = 'joomlatoken.enabled'");
$made    = false;

if ($seed === null || (string) $enabled !== '1') {
    // For the time of the test only
    $made = true;
    $seed = base64_encode(random_bytes(32));

    $db->query(str_replace('#__', $prefix, "DELETE FROM #__user_profiles WHERE user_id = $userId AND profile_key IN ('joomlatoken.token', 'joomlatoken.enabled')"));
    $db->query(str_replace('#__', $prefix, "INSERT INTO #__user_profiles (user_id, profile_key, profile_value, ordering) VALUES ($userId, 'joomlatoken.token', '" . $db->real_escape_string($seed) . "', 1), ($userId, 'joomlatoken.enabled', '1', 2)"));
}

$token = base64_encode('sha256:' . $userId . ':' . hash_hmac('sha256', base64_decode((string) $seed), $config->secret));

/**
 * @return  array{0: int, 1: mixed, 2: string}  Status, decoded body, type of the content
 */
$request = static function (string $method, string $path, ?array $body = null, bool $withToken = true) use ($domain, $token): array {
    $handle  = curl_init('https://' . $domain . '/api/index.php/' . ltrim($path, '/'));
    $headers = ['Accept: application/json, text/event-stream'];

    if ($withToken) {
        $headers[] = 'X-Joomla-Token: ' . $token;
    }

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    }

    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
        // This machine, and nothing else
        CURLOPT_RESOLVE        => [$domain . ':443:127.0.0.1'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $text   = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $type   = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);

    if ((string) curl_getinfo($handle, CURLINFO_PRIMARY_IP) !== '127.0.0.1') {
        fwrite(STDERR, "The request did not go to this machine.\n");
        exit(1);
    }

    curl_close($handle);

    return [$status, json_decode($text, true) ?? $text, $type];
};

$folder = 'images/_mediaaudit_fixtures';
$orphan = $folder . '/orphan.jpg';
$sha1   = sha1_file($root . '/' . $orphan);
$mcp    = null;

try {
    // -----------------------------------------------------------------------------------------
    echo "--- without a token\n";

    foreach ([['GET', 'v1/mediaaudit/report?type=orphan', null], ['POST', 'v1/mediaaudit/quarantine', ['paths' => [$orphan]]], ['POST', 'v1/mediaaudit/scan', null]] as [$method, $path, $body]) {
        [$status, $answer] = $request($method, $path, $body, false);

        $check("$method $path is refused", \in_array($status, [401, 403], true) && !isset($answer['data']), 'HTTP ' . $status);
    }

    $check('the file is where it was', is_file($root . '/' . $orphan));

    // -----------------------------------------------------------------------------------------
    echo "--- scan\n";

    [$status, $answer] = $request('POST', 'v1/mediaaudit/scan');
    $started           = time();

    $check('POST v1/mediaaudit/scan', \in_array($status, [200, 202], true), 'HTTP ' . $status . ', ' . ($answer['data']['status'] ?? json_encode($answer)));

    // The scan goes on after the answer
    for ($i = 0, $fresh = null; $i < 60; $i++) {
        [, $answer] = $request('GET', 'v1/mediaaudit/scan');
        $fresh      = $answer['data']['scanned_at'] ?? null;

        if ($fresh !== null && strtotime($fresh) >= $started - 1 && ($answer['data']['source'] ?? '') === 'api') {
            break;
        }

        usleep(500000);
    }

    $check('GET v1/mediaaudit/scan tells of the new scan', $fresh !== null && strtotime($fresh) >= $started - 1, (string) $fresh);

    // -----------------------------------------------------------------------------------------
    echo "--- report\n";

    [$status, $answer, $type] = $request('GET', 'v1/mediaaudit/summary');

    $check('GET v1/mediaaudit/summary', $status === 200 && str_starts_with($type, 'application/json') && ($answer['data']['summary']['files'] ?? 0) > 0, 'HTTP ' . $status);

    $paths = static fn ($answer, string $key = 'path'): array => array_column($answer['data']['items'] ?? [], $key);

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=orphan&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('type=orphan', $status === 200 && $paths($answer) === [$orphan, $folder . '/photo.jpg'], 'HTTP ' . $status . ', ' . implode(' ', array_map('basename', $paths($answer))));
    $check('the file that is referred to in another case', ($answer['data']['items'][1]['referenced_in_another_case_as'] ?? null) === $folder . '/Photo.JPG');

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=semi&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('type=semi', $status === 200 && $paths($answer) === [$folder . '/g.jpg', $folder . '/h.jpg'] && ($answer['data']['items'][0]['sources'][0]['state'] ?? '') === 'trashed');

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=broken&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('type=broken', $status === 200 && $paths($answer, 'target') === [$folder . '/Photo.JPG', $folder . '/missing.jpg']);

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=duplicate&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('type=duplicate', $status === 200 && array_column($answer['data']['items'][0]['files'] ?? [], 'path') === [$folder . '/dup1.jpg', $folder . '/dup2.jpg']);

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=orphan&limit=1&offset=1&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('limit and offset', $status === 200 && $paths($answer) === [$folder . '/photo.jpg'] && ($answer['data']['total'] ?? 0) === 2);

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=everything');

    $check('a type that is not known', $status === 400 && isset($answer['error']), 'HTTP ' . $status);

    // -----------------------------------------------------------------------------------------
    if ($one('SELECT COUNT(*) FROM #__mediaaudit_urls WHERE local_path IS NOT NULL')) {
        echo "--- copies of external images\n";

        [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=localized&limit=3');

        $copy = $answer['data']['items'][0] ?? [];

        $check(
            'type=localized',
            $status === 200 && ($answer['data']['total'] ?? 0) === (int) $one('SELECT COUNT(*) FROM #__mediaaudit_urls WHERE local_path IS NOT NULL')
            && str_starts_with($copy['local_path'] ?? '', 'images/') && str_starts_with($copy['url'] ?? '', 'http') && is_file($root . '/' . ($copy['local_path'] ?? '-')),
            'HTTP ' . $status . ', ' . ($answer['data']['total'] ?? '?') . ' copies'
        );

        $used = $one("SELECT u.local_path FROM #__mediaaudit_urls u INNER JOIN #__mediaaudit_refs r ON r.path_hash = SHA1(u.local_path) WHERE u.local_path IS NOT NULL LIMIT 1");

        [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=localized&search=' . rawurlencode(basename((string) $used)));

        $check(
            'a copy names the sources that use it',
            $status === 200 && ($answer['data']['items'][0]['local_path'] ?? '') === $used && ($answer['data']['items'][0]['used_by'][0]['source_id'] ?? 0) > 0
            && ($answer['data']['items'][0]['file'] ?? '') === 'active'
        );

        [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=localized&state=failed&limit=500');

        $check(
            'state=failed',
            $status === 200 && ($answer['data']['total'] ?? -1) === (int) $one("SELECT COUNT(*) FROM #__mediaaudit_urls WHERE local_path IS NULL AND error <> ''")
            && array_filter($answer['data']['items'] ?? [], static fn (array $item): bool => $item['local_path'] !== null || $item['error'] === '') === [],
            ($answer['data']['total'] ?? '?') . ' images'
        );

        [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=rewrites&kind=remove_lost&limit=500');

        $check(
            'type=rewrites',
            $status === 200 && ($answer['data']['total'] ?? -1) === (int) $one("SELECT COUNT(*) FROM #__mediaaudit_rewrites WHERE kind = 'remove_lost' AND reverted_at IS NULL")
            && array_unique(array_column($answer['data']['items'] ?? [], 'kind')) === (($answer['data']['total'] ?? 0) ? ['remove_lost'] : []),
            ($answer['data']['total'] ?? '?') . ' changes'
        );
    }

    // -----------------------------------------------------------------------------------------
    echo "--- references\n";

    [$status, $answer] = $request('GET', 'v1/mediaaudit/refs?path=' . rawurlencode('/' . $folder . '/a.jpg?v=2'));

    $check(
        'GET v1/mediaaudit/refs',
        $status === 200 && ($answer['data']['path'] ?? '') === $folder . '/a.jpg' && ($answer['data']['class'] ?? '') === 'in_use'
        && ($answer['data']['references'][0]['source_type'] ?? '') === 'article' && str_contains($answer['data']['references'][0]['edit_link'] ?? '', 'task=article.edit'),
        'HTTP ' . $status
    );

    [$status, $answer] = $request('GET', 'v1/mediaaudit/refs?path=' . rawurlencode($folder . '/Photo.JPG'));

    $check('a reference in the wrong case', $status === 200 && ($answer['data']['class'] ?? '') === 'broken' && ($answer['data']['other_case'] ?? []) === [$folder . '/photo.jpg']);

    // -----------------------------------------------------------------------------------------
    echo "--- quarantine and back\n";

    [$status, $answer] = $request('POST', 'v1/mediaaudit/quarantine', ['paths' => [$orphan, $folder . '/a.jpg', 'configuration.php'], 'note' => 'verify-api.php']);

    $moved   = $answer['data']['moved'] ?? [];
    $reasons = array_column($answer['data']['refused'] ?? [], 'reason', 'path');

    $check('POST v1/mediaaudit/quarantine', $status === 200 && array_column($moved, 'path') === [$orphan], 'HTTP ' . $status);
    $check('what is referred to is refused', ($reasons[$folder . '/a.jpg'] ?? '') === 'referenced' && is_file($root . '/' . $folder . '/a.jpg'));
    $check('what is not an image is refused', ($reasons['configuration.php'] ?? '') === 'not_an_image' && is_file($root . '/configuration.php'));
    $check('the file is in the quarantine', !file_exists($root . '/' . $orphan) && isset($moved[0]) && sha1_file($root . '/' . $moved[0]['quarantine_path']) === $sha1);

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=orphan&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('the report has it no more', $status === 200 && !\in_array($orphan, $paths($answer), true));

    [$status, $answer] = $request('GET', 'v1/mediaaudit/report?type=quarantine&search=' . rawurlencode('_mediaaudit_fixtures/'));

    $check('type=quarantine has it', $status === 200 && ($answer['data']['items'][0]['id'] ?? 0) === ($moved[0]['id'] ?? -1) && ($answer['data']['items'][0]['note'] ?? '') === 'verify-api.php');

    [$status, $answer] = $request('POST', 'v1/mediaaudit/restore', ['ids' => [$moved[0]['id'] ?? 0]]);

    $check('POST v1/mediaaudit/restore', $status === 200 && array_column($answer['data']['restored'] ?? [], 'path') === [$orphan], 'HTTP ' . $status);
    $check('the file is where it was, unchanged', is_file($root . '/' . $orphan) && sha1_file($root . '/' . $orphan) === $sha1);

    [$status, $answer] = $request('POST', 'v1/mediaaudit/quarantine', ['paths' => []]);

    $check('no paths at all', $status === 400, 'HTTP ' . $status);

    // -----------------------------------------------------------------------------------------
    if ($one("SELECT COUNT(*) FROM #__extensions WHERE type = 'component' AND element = 'com_mcp' AND enabled = 1")) {
        echo "--- MCP server\n";

        // Moving files away is off on a new server: on for the time of the test
        $mcp     = (string) $one("SELECT params FROM #__extensions WHERE type = 'component' AND element = 'com_mcp'");
        $changed = json_encode(['allow_delete' => 1, 'read_only' => 0, 'enabled' => 1] + (json_decode($mcp, true) ?: []));

        $db->query(str_replace('#__', $prefix, "UPDATE #__extensions SET params = '" . $db->real_escape_string($changed) . "' WHERE type = 'component' AND element = 'com_mcp'"));

        $call = static function (string $method, array $params = []) use ($request): array {
            static $id = 0;

            [$status, $answer] = $request('POST', 'v1/mcp', ['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]);

            return [$status, \is_array($answer) ? $answer : ['raw' => $answer]];
        };

        $call('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'verify-api', 'version' => '1']]);

        [$status, $answer] = $call('tools/list');

        $tools = array_column($answer['result']['tools'] ?? [], null, 'name');
        // The MCP server has tools of its own whose names start the same way: ours are these four
        $ours  = array_values(array_intersect(['media_audit_refs', 'media_audit_report', 'media_quarantine', 'media_restore'], array_keys($tools)));

        $check('the tools are listed', $status === 200 && \count($ours) === 4, implode(' ', array_keys($tools)));
        $check(
            'reading tools say that they only read, and the quarantine that it takes away',
            ($tools['media_audit_report']['annotations']['readOnlyHint'] ?? null) === true
            && ($tools['media_quarantine']['annotations']['destructiveHint'] ?? null) === true
            && ($tools['media_quarantine']['annotations']['readOnlyHint'] ?? null) === false
        );

        $tool = static function (string $name, array $arguments) use ($call): array {
            [$status, $answer] = $call('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);

            $result = $answer['result'] ?? [];
            $data   = $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? '', true);

            return [$status, $data, (bool) ($result['isError'] ?? false), $result['content'][0]['text'] ?? json_encode($answer)];
        };

        [$status, $data, $error] = $tool('media_audit_report', []);

        $check('media_audit_report, the summary', $status === 200 && !$error && ($data['summary']['files'] ?? 0) > 0);

        [$status, $data, $error] = $tool('media_audit_report', ['type' => 'orphan', 'search' => '_mediaaudit_fixtures/']);

        $check('media_audit_report, type=orphan', !$error && array_column($data['items'] ?? [], 'path') === [$orphan, $folder . '/photo.jpg']);

        [$status, $data, $error] = $tool('media_audit_refs', ['path' => $folder . '/g.jpg']);

        $check('media_audit_refs', !$error && ($data['class'] ?? '') === 'semi_orphan' && ($data['references'][0]['state'] ?? '') === 'trashed');

        [$status, $data, $error] = $tool('media_quarantine', ['paths' => [$orphan, $folder . '/a.jpg'], 'note' => 'verify-api.php, MCP']);

        $id = $data['moved'][0]['id'] ?? 0;

        $check('media_quarantine', !$error && array_column($data['moved'] ?? [], 'path') === [$orphan] && !file_exists($root . '/' . $orphan));
        $check('media_quarantine refuses what is referred to', ($data['refused'][0]['reason'] ?? '') === 'referenced' && is_file($root . '/' . $folder . '/a.jpg'));

        [$status, $data, $error] = $tool('media_restore', ['ids' => [$id]]);

        $check('media_restore', !$error && array_column($data['restored'] ?? [], 'path') === [$orphan] && sha1_file($root . '/' . $orphan) === $sha1);

        [$status, $data, $error, $text] = $tool('media_audit_report', ['type' => 'everything']);

        $check('a mistake is told to the model', $error || $status !== 200 || str_contains($text, 'type'), mb_strimwidth($text, 0, 90, '…'));

        // With the quarantine not allowed by the server
        $db->query(str_replace('#__', $prefix, "UPDATE #__extensions SET params = '" . $db->real_escape_string(json_encode(['allow_delete' => 0] + json_decode($changed, true))) . "' WHERE type = 'component' AND element = 'com_mcp'"));

        [$status, $answer] = $call('tools/list');

        $names = array_column($answer['result']['tools'] ?? [], 'name');

        $check('a server that allows no deleting does not offer the quarantine', !\in_array('media_quarantine', $names, true) && \in_array('media_audit_report', $names, true));
    }
} finally {
    if ($mcp !== null) {
        $db->query(str_replace('#__', $prefix, "UPDATE #__extensions SET params = '" . $db->real_escape_string($mcp) . "' WHERE type = 'component' AND element = 'com_mcp'"));
    }

    if ($made) {
        $db->query(str_replace('#__', $prefix, "DELETE FROM #__user_profiles WHERE user_id = $userId AND profile_key IN ('joomlatoken.token', 'joomlatoken.enabled')"));
    }

    // Whatever went wrong on the way: the orphan belongs where it was
    if (!is_file($root . '/' . $orphan)) {
        $held = $one("SELECT quarantine_path FROM #__mediaaudit_quarantine WHERE original_path = '" . $db->real_escape_string($orphan) . "' AND restored_at IS NULL ORDER BY id DESC LIMIT 1");

        if ($held !== null && is_file($root . '/' . $held) && rename($root . '/' . $held, $root . '/' . $orphan)) {
            $db->query(str_replace('#__', $prefix, "UPDATE #__mediaaudit_quarantine SET restored_at = UTC_TIMESTAMP(), note = CONCAT(note, ' (put back by verify-api.php)') WHERE original_path = '" . $db->real_escape_string($orphan) . "' AND restored_at IS NULL"));
            $db->query(str_replace('#__', $prefix, "UPDATE #__mediaaudit_files SET status = 'active' WHERE path = '" . $db->real_escape_string($orphan) . "'"));
        }
    }
}

$check('the token of the test is gone', !$made || $one("SELECT COUNT(*) FROM #__user_profiles WHERE user_id = $userId AND profile_key LIKE 'joomlatoken.%'") === '0');

echo $failed ? "FAILED: $failed\n" : "OK\n";
exit($failed ? 1 : 0);
