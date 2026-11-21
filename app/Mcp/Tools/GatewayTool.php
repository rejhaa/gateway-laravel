<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Gateway\Actor;
use App\Gateway\Audit;
use App\Gateway\AuditEvent;
use App\Gateway\AuditOutcome;
use App\Gateway\InputError;
use App\Systems\Odoo\Exceptions\OdooException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Passport;
use Throwable;

/**
 * The base of every gateway tool (DESIGN.md, section 10). A tool implements
 * run(), and handle() turns its three outcomes into what the agent reads and
 * what the audit log records.
 *
 * Input the agent can correct comes back as its sentence and is audited as
 * input.rejected. Any other failure is reported, audited as tool.failed and
 * answered with a plain sentence, whatever APP_DEBUG is, so no stack trace,
 * key or Odoo debug field reaches the agent.
 */
abstract class GatewayTool extends Tool
{
    /**
     * Does the tool's work as the actor and shapes the answer with Response::structured().
     *
     * @throws InputError when the agent can correct the input
     * @throws ValidationException when the input does not have the tool's shape
     */
    abstract protected function run(Request $request, Actor $actor): ResponseFactory;

    final public function handle(Request $request, Audit $audit): Response|ResponseFactory
    {
        $actor = Actor::agent((string) config('gateway.user.odoo_login'), self::client($request));

        Audit::requestId();
        Context::add(['tool' => $this->name(), 'actor' => $actor->name, 'client' => $actor->client]);

        try {
            $response = $this->run($request, $actor);
        } catch (InputError|ValidationException $e) {
            $sentence = $e instanceof ValidationException
                ? implode(' ', $e->validator->errors()->all())
                : $e->getMessage();

            $audit->record(AuditEvent::InputRejected, $actor, AuditOutcome::Refused, $this->name(), detail: [
                'arguments' => $request->all(),
                'reason' => $sentence,
            ]);

            return Response::error($sentence);
        } catch (Throwable $e) {
            report($e);

            $audit->record(AuditEvent::ToolFailed, $actor, AuditOutcome::Error, $this->name(), detail: [
                'arguments' => $request->all(),
                'exception' => $e::class,
            ]);

            return Response::error('The gateway could not do this: '.self::plainReason($e));
        }

        $audit->record(AuditEvent::ToolCalled, $actor, tool: $this->name(), detail: ['arguments' => $request->all()]);

        return $response;
    }

    /**
     * The name of the OAuth client the agent connected through, from the
     * Passport token of the request, or null when there is none.
     */
    public static function client(Request $request): ?string
    {
        $user = $request->user();
        $token = $user instanceof OAuthenticatable ? $user->currentAccessToken() : null;

        if (! $token instanceof AccessToken) {
            return null;
        }

        return Passport::client()->newQuery()->find($token->oauth_client_id)?->name;
    }

    /**
     * An Odoo error's message is written to be shown to a person. Any other
     * message may hold internals, so the agent gets a fixed sentence.
     */
    private static function plainReason(Throwable $e): string
    {
        return $e instanceof OdooException
            ? rtrim($e->getMessage(), '.').'.'
            : 'something unexpected went wrong, and it is logged for the developers.';
    }
}
