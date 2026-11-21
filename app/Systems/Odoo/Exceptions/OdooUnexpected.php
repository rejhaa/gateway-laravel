<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

/**
 * Odoo answered 500, any other error status, or a body that is not the JSON
 * the call expects. Odoo's debug field, or the start of a body that is not
 * JSON, goes into the log context and never into the message.
 */
final class OdooUnexpected extends OdooException
{
    public const DEBUG_LIMIT = 2000;

    public readonly ?string $debug;

    public function __construct(
        string $message,
        string $model,
        string $method,
        ?int $status = null,
        ?string $odooError = null,
        ?string $debug = null,
    ) {
        parent::__construct($message, $model, $method, $status, $odooError);

        $this->debug = $debug === null ? null : mb_substr($debug, 0, self::DEBUG_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [...parent::context(), 'debug' => $this->debug];
    }
}
