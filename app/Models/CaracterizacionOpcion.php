<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CaracterizacionOpcion extends Model
{
    use HasFactory;

    protected $table = 'caracterizacion_opciones';
    protected $fillable = ['categoria', 'grupo', 'valor', 'orden', 'activo'];

    public function scopeCategoria($query, string $categoria)
    {
        return $query->where('categoria', $categoria)->where('activo', true)->orderBy('orden');
    }
}
