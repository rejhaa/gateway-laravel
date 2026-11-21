<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

/**
 * Odoo answered 404 in JSON, for an unknown model, method or record.
 */
final class OdooNotFound extends OdooException {}
