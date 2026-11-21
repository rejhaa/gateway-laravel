<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * The one person the gateway acts for. primary() finds the row, or creates it
 * from config('gateway.user') on a fresh database. Claude connects as this
 * user through Passport.
 *
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** The one user the gateway acts for, created from config('gateway.user') when the row is missing. */
    public static function primary(): self
    {
        return self::query()->firstOrCreate(['name' => config('gateway.user.name')]);
    }
}
