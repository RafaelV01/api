<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenRevocado extends Model
{
    use HasFactory;

    protected $table = 'tokens_revocados';

    // Le decimos a Laravel que este modelo solo gestiona `created_at`
    const UPDATED_AT = null;

    protected $fillable = [
        'jti',
        'expira_en',
    ];
}