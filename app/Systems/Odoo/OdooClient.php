<?php

declare(strict_types=1);

namespace App\Systems\Odoo;

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Gateway\AuditOutcome;
use App\Systems\Odoo\Exceptions\OdooAccessDenied;
use App\Systems\Odoo\Exceptions\OdooAuthenticationFailed;
use App\Systems\Odoo\Exceptions\OdooException;
use App\Systems\Odoo\Exceptions\OdooNotFound;
use App\Systems\Odoo\Exceptions\OdooRejected;
use App\Systems\Odoo\Exceptions\OdooUnexpected;
use App\Systems\Odoo\Exceptions\OdooUnreachable;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls Odoo's JSON-2 API as the configured user and writes an audit row per call.
 *
 * Every method takes the Actor the audit row names. The call itself always runs
 * as the user in gateway.user.odoo_login, whose key is read from the keys file
 * on each call because a reseed replaces it.
 */
final class OdooClient
{
    /** Milliseconds to wait before the second and the third attempt. */
    private const RETRY_DELAYS = [200, 600];

    private const RETRYABLE_STATUSES = [502, 503, 504];

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<mixed>  $domain
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function searchRead(Actor $actor, string $model, array $domain, array $fields, int $limit): array
    {
        return $this->call(
            $actor,
            $model,
            'search_read',
            ['domain' => $domain, 'fields' => $fields, 'limit' => $limit],
            ['fields' => $fields],
            self::isRecordList(...),
        );
    }

    /**
     * Reads records by id. Odoo answers with an error when one of them does not exist.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function read(Actor $actor, string $model, array $ids, array $fields, ?int $proposalId = null): array
    {
        return $this->call(
            $actor,
            $model,
            'read',
            ['ids' => $ids, 'fields' => $fields],
            ['ids' => $ids, 'fields' => $fields],
            self::isRecordList(...),
            $proposalId,
        );
    }

    /**
     * Writes the values to one record. The request is never sent twice.
     *
     * @param  array<string, mixed>  $values
     */
    public function write(Actor $actor, string $model, int $id, array $values, ?int $proposalId = null): void
    {
        $this->call(
            $actor,
            $model,
            'write',
            ['ids' => [$id], 'vals' => $values],
            ['ids' => [$id], 'fields' => array_keys($values)],
            fn (mixed $result): bool => $result === true,
            $proposalId,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $detail  ids and field names for the audit row
     * @param  Closure(mixed): bool  $isExpected
     */
    private function call(
        Actor $actor,
        string $model,
        string $method,
        array $arguments,
        array $detail,
        Closure $isExpected,
        ?int $proposalId = null,
    ): mixed {
        $event = $method === 'write' ? AuditEvent::OdooWrite : AuditEvent::OdooRead;
        $detail = ['model' => $model, 'method' => $method, ...$detail];

        try {
            $result = $this->send($model, $method, $arguments);

            if (! $isExpected($result)) {
                throw new OdooUnexpected("Odoo answered {$method} on {$model} with a result of an unexpected shape.", $model, $method, 200);
            }
        } catch (OdooException $e) {
            $this->audit->record($event, $actor, self::outcomeOf($e), proposalId: $proposalId, detail: [
                ...$detail,
                'status' => $e->status,
                'odoo_error' => $e->odooError,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->audit->record($event, $actor, AuditOutcome::Ok, proposalId: $proposalId, detail: $detail);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function send(string $model, string $method, array $arguments): mixed
    {
        $url = (string) config('gateway.odoo.url');

        try {
            $response = Http::baseUrl($url)
                ->withToken($this->apiKey($model, $method), 'bearer')
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(30)
                ->retry(
                    self::RETRY_DELAYS,
                    when: $method === 'write' ? self::connectionNotOpened(...) : self::readMayBeRetried(...),
                    throw: false,
                )
                ->post("/json/2/{$model}/{$method}", $arguments);
        } catch (ConnectionException $e) {
            throw new OdooUnreachable("Odoo did not answer at {$url}.", $model, $method, previous: $e);
        }

        if (! $response->successful()) {
            throw self::failure($response, $model, $method);
        }

        if (! json_validate($response->body())) {
            throw new OdooUnexpected(
                "Odoo answered {$method} on {$model} with a body that is not JSON.",
                $model,
                $method,
                $response->status(),
                debug: $response->body(),
            );
        }

        return json_decode($response->body(), true);
    }

    private static function failure(Response $response, string $model, string $method): OdooException
    {
        $status = $response->status();
        $error = json_validate($response->body()) ? json_decode($response->body(), true) : null;

        if (! is_array($error) || ! is_string($error['message'] ?? null)) {
            return new OdooUnexpected(
                "Odoo answered {$status} to {$method} on {$model} with a body that is not a JSON error.",
                $model,
                $method,
                $status,
                debug: $response->body(),
            );
        }

        $message = $error['message'];
        $name = is_string($error['name'] ?? null) ? $error['name'] : null;

        return match ($status) {
            401 => new OdooAuthenticationFailed($message, $model, $method, $status, $name),
            403 => new OdooAccessDenied($message, $model, $method, $status, $name),
            404 => new OdooNotFound($message, $model, $method, $status, $name),
            422 => new OdooRejected($message, $model, $method, $status, $name),
            default => new OdooUnexpected(
                $message,
                $model,
                $method,
                $status,
                $name,
                is_string($error['debug'] ?? null) ? $error['debug'] : null,
            ),
        };
    }

    private function apiKey(string $model, string $method): string
    {
        $login = (string) config('gateway.user.odoo_login');
        $path = (string) config('gateway.odoo.keys_file');

        $keys = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $entry = is_array($keys) ? ($keys[$login] ?? null) : null;
        $key = is_array($entry) ? ($entry['api_key'] ?? null) : null;

        if (! is_string($key) || $key === '') {
            throw new OdooAuthenticationFailed("The keys file has no Odoo API key for the login {$login}.", $model, $method);
        }

        return $key;
    }

    private static function readMayBeRetried(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && in_array($e->response->status(), self::RETRYABLE_STATUSES, true));
    }

    /**
     * True only when the connection was never opened, so Odoo cannot have seen the write.
     * A timeout or a lost answer after the request went out is not retried.
     */
    private static function connectionNotOpened(Throwable $e): bool
    {
        return $e instanceof ConnectionException && $e->getPrevious() instanceof ConnectException;
    }

    private static function isRecordList(mixed $result): bool
    {
        return is_array($result) && array_is_list($result);
    }

    private static function outcomeOf(OdooException $e): AuditOutcome
    {
        return $e instanceof OdooUnreachable || $e instanceof OdooUnexpected
            ? AuditOutcome::Error
            : AuditOutcome::Refused;
    }
}
