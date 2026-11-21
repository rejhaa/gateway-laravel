<?php

declare(strict_types=1);

namespace App\Systems\Odoo\Exceptions;

/**
 * Odoo answered 401, or the keys file has no key for the user. Usually a reseed
 * rotated the key.
 */
final class OdooAuthenticationFailed extends OdooException {}
