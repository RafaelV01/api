<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asistente extends Model
{
    use HasFactory;
    protected $table = 'asistentes';
    protected $fillable = ['reunion_id', 'usuario_id', 'nombre_completo', 'cargo', 'dependencia', 'email', 'telefono', 'firma_id', 'validado', 'observado_por', 'observacion', 'creado_via', 'ip_origen', 'user_agent'];

    public function reunion() { return $this->belongsTo(Reunion::class, 'reunion_id'); }
    public function usuario() { return $this->belongsTo(User::class, 'usuario_id'); }
    public function firma() { return $this->belongsTo(FirmaDigital::class, 'firma_id'); }
    public function observador() { return $this->belongsTo(User::class, 'observado_por'); }
}