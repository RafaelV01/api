<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FirmaDigital extends Model
{
    use HasFactory;

    protected $table = 'firmas_digitales';

    // Le decimos a Laravel que este modelo solo gestiona `created_at`
    const UPDATED_AT = null;

    protected $fillable = [
        'owner_tipo',
        'owner_id',
        'formato',
        'data_base64',
        'hash_integridad',
    ];
}