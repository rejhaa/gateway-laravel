<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

/**
 * Odoo answered 422, a validation or user error. The message is Odoo's own.
 */
final class OdooRejected extends OdooException {}
