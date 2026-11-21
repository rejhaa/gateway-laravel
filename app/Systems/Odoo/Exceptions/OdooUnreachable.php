<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

/**
 * The connection failed or timed out. For a write the outcome is unknown,
 * because Odoo may have committed it before the answer was lost.
 */
final class OdooUnreachable extends OdooException {}
