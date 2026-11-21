<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

/**
 * Odoo answered 403, an AccessError for the user the gateway acts as.
 */
final class OdooAccessDenied extends OdooException {}
