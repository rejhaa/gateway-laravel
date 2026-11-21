<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A JSON-2 call that did not succeed. The message is safe to show to a person.
 * It never holds Odoo's debug field, a key or a header.
 */
abstract class OdooException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $model,
        public readonly string $method,
        public readonly ?int $status = null,
        public readonly ?string $odooError = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'model' => $this->model,
            'method' => $this->method,
            'status' => $this->status,
            'odoo_error' => $this->odooError,
        ];
    }
}
