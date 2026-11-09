<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Un archivo Excel subido para sacar estadísticas aparte del sistema (sus filas viven en caracterizacion_carga_registros). */
class CaracterizacionCargaExcel extends Model
{
    use Auditable;

    protected $table = 'caracterizacion_cargas_excel';

    protected $fillable = ['usuario_id', 'nombre', 'archivo_original', 'archivo_ruta', 'total_filas', 'filas_omitidas', 'omitidas_detalle'];

    protected $casts = ['omitidas_detalle' => 'array'];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
