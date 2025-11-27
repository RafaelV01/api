<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reunion extends Model
{
    use HasFactory;
    protected $table = 'reuniones';
    protected $fillable = ['codigo', 'slug_acceso', 'tema', 'fecha', 'hora_inicio', 'hora_fin', 'dependencia_lugar', 'ciudad_municipio', 'tipo_evento', 'otro_evento', 'expositor', 'firma_creador_id', 'creador_id', 'qr_png_path', 'estado','firma_creador'];

    public function creador() { return $this->belongsTo(User::class, 'creador_id'); }
    public function asistentes() { return $this->hasMany(Asistente::class, 'reunion_id'); }
    public function firmaCreador() { return $this->belongsTo(FirmaDigital::class, 'firma_creador_id'); }
    public function ajustes() { return $this->hasOne(AjustesReunion::class, 'reunion_id'); }
}