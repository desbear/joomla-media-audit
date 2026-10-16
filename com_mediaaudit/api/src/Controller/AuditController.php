<?php

/**
 * @package     Media Audit
 * @subpackage  com_mediaaudit
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Component\Mediaaudit\Api\Controller;

use Gss\Component\Mediaaudit\Administrator\Service\AuditFacade;
use Joomla\CMS\MVC\Controller\BaseController;

\defined('_JEXEC') or die;

/**
 * The web services of Media Audit. Plain JSON: the answers are reports, not the items of Joomla
 * that JSON:API is made for.
 *
 * Every request needs the API token of a user, as the other web services of Joomla do. What the
 * user may do is decided by the permissions of the component.
 */
class AuditController extends BaseController
{
    /**
     * GET v1/mediaaudit/report?type=orphan|semi|broken|duplicate|quarantine|localized|rewrites
     */
    public function report(): void
    {
        $this->answer(function (AuditFacade $audit): array {
            $input = $this->input;

            return $audit->report($input->getCmd('type', ''), [
                'search'      => $input->getString('search', ''),
                'source_type' => $input->getCmd('source_type', ''),
                'kind'        => $input->getCmd('kind', ''),
                'folder'      => $input->getString('folder', ''),
                'state'       => $input->getCmd('state', ''),
                'host'        => $input->getString('host', ''),
                'include_derived' => $input->getBool('include_derived', false),
                'limit'       => $input->getInt('limit', 50),
                'offset'      => $input->getInt('offset', 0),
            ]);
        });
    }

    /**
     * GET v1/mediaaudit/summary
     */
    public function summary(): void
    {
        $this->answer(static fn (AuditFacade $audit): array => $audit->summary());
    }

    /**
     * GET v1/mediaaudit/refs?path=
     */
    public function refs(): void
    {
        $this->answer(fn (AuditFacade $audit): array => $audit->references($this->input->getString('path', '')));
    }

    /**
     * POST v1/mediaaudit/quarantine  {"paths": ["images/a.jpg"], "note": ""}
     */
    public function quarantine(): void
    {
        $this->answer(function (AuditFacade $audit): array {
            $body = $this->body();

            return $audit->quarantine((array) ($body['paths'] ?? []), (string) ($body['note'] ?? ''));
        });
    }

    /**
     * POST v1/mediaaudit/restore  {"ids": [12]}
     */
    public function restore(): void
    {
        $this->answer(function (AuditFacade $audit): array {
            return $audit->restore((array) ($this->body()['ids'] ?? []));
        });
    }

    /**
     * GET v1/mediaaudit/scan: when the last scan was.
     */
    public function status(): void
    {
        $this->answer(static fn (AuditFacade $audit): array => $audit->lastScan());
    }

    /**
     * POST v1/mediaaudit/scan
     *
     * Answers at once with 202, and scans after the answer is sent. Where PHP cannot send the answer
     * before the work is done, the scan is done first, and the answer is 200 with the result.
     */
    public function scan(): void
    {
        $this->authenticate();

        $audit = AuditFacade::create();

        if (!$audit->canRead()) {
            $this->send(403, ['error' => 'This user is not allowed to scan.']);
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        ignore_user_abort(true);

        if (!\function_exists('fastcgi_finish_request')) {
            $this->answer(static fn (AuditFacade $audit): array => ['status' => 'done'] + $audit->scan('api'));
        }

        $this->write(202, ['data' => ['status' => 'accepted', 'started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'poll' => 'v1/mediaaudit/scan']]);

        // The session is of no use after the answer, and its lock would hold other requests up
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        fastcgi_finish_request();

        try {
            $audit->scan('api');
        } catch (\Throwable $e) {
            // Nobody is there to tell: the time of the last scan says that it did not end
        }

        $this->app->close();
    }

    /**
     * @param  callable(AuditFacade): array  $work
     */
    private function answer(callable $work): never
    {
        $this->authenticate();

        try {
            $this->send(200, ['data' => $work(AuditFacade::create())]);
        } catch (\InvalidArgumentException $e) {
            $this->send(400, ['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            $this->send($e->getCode() === 403 ? 403 : 500, ['error' => $e->getCode() === 403 ? $e->getMessage() : 'The request failed.']);
        }
    }

    /**
     * Signs the user of the token in, the way the application does for the routes that it guards.
     */
    private function authenticate(): void
    {
        $user = $this->app->getIdentity();

        if ($user && $user->id) {
            return;
        }

        try {
            $signedIn = $this->app->login(['username' => ''], ['silent' => true, 'action' => 'core.login.api']);
        } catch (\Throwable $e) {
            $signedIn = false;
        }

        $user = $this->app->getIdentity();

        if ($signedIn !== true || !$user || !$user->id) {
            if (!headers_sent()) {
                header('WWW-Authenticate: Bearer realm="Joomla API"');
            }

            $this->send(401, ['error' => 'Authentication required: send the API token of a user as "Authorization: Bearer <token>" or "X-Joomla-Token: <token>".']);
        }
    }

    /**
     * @return  array<string, mixed>
     */
    private function body(): array
    {
        $body = json_decode((string) file_get_contents('php://input'), true);

        if (!\is_array($body)) {
            throw new \InvalidArgumentException('The body has to be a JSON object.');
        }

        return $body;
    }

    private function send(int $status, array $payload): never
    {
        $this->write($status, $payload);
        $this->app->close();
    }

    private function write(int $status, array $payload): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
