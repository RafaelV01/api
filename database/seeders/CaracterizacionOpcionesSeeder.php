<?php

namespace Database\Seeders;

use App\Models\CaracterizacionOpcion;
use Illuminate\Database\Seeder;

/**
 * Semilla de todas las listas desplegables del formulario FO-PDD-19, transcritas
 * verbatim desde las columnas auxiliares (BQ..CE) de la hoja "FO-PDD-19" del Excel
 * fuente ("FO-PDD-19 yanory Caracterizacion Ciudadania OK (1).xlsx").
 * Idempotente: usa updateOrCreate por (categoria, valor), se puede correr varias veces.
 */
class CaracterizacionOpcionesSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrar('tipo_documento', ['C.C', 'T.I', 'R.C', 'C.E', 'PASAPORTE', 'NIT']);

        $this->sembrar('municipio', [
            'AGUAZUL', 'CHAMEZA', 'HATO COROZAL', 'LA SALINA', 'MANI', 'MONTERREY', 'NUNCHIA',
            'OROCUE', 'PAZ DE ARIPORO', 'PORE', 'RECETOR', 'SABANALARGA', 'SACAMA',
            'SAN LUIS DE PALENQUE', 'TAMARA', 'TAURAMENA', 'TRINIDAD', 'VILLANUEVA', 'YOPAL',
        ]);

        // Ubicación (item 7): el catálogo depende de Zona + Municipio (ver
        // $municipiosConVereda/$municipiosConResguardo en PublicController y
        // RegistrarCiudadano.jsx). "Resguardo" solo se ofrece donde realmente hay
        // un resguardo indígena constituido — no en los 13 municipios de Casanare
        // sin resguardo.
        $this->sembrar('ubicacion_urbana', ['Barrio', 'Comuna']);
        $this->sembrar('ubicacion_rural_estandar', ['Corregimiento']);
        // "Resguardo" ya no aplica a ubicacion_rural_estandar (se movió a la nueva
        // categoría con_resguardo) — se desactiva la entrada vieja sin borrarla.
        CaracterizacionOpcion::where('categoria', 'ubicacion_rural_estandar')->where('valor', 'Resguardo')->update(['activo' => false]);
        $this->sembrar('ubicacion_rural_con_resguardo', ['Corregimiento', 'Resguardo']);
        $this->sembrar('ubicacion_rural_especial', ['Corregimiento', 'Resguardo', 'Vereda']);
        // Catálogo anterior: se desactiva (no se borra) para no perder el histórico de quién lo usó.
        CaracterizacionOpcion::where('categoria', 'ubicacion_tipo')->update(['activo' => false]);

        $this->sembrar('contacto_tipo', ['Celular']);
        CaracterizacionOpcion::where('categoria', 'contacto_tipo')->where('valor', 'Teléfono')->update(['activo' => false]);

        // Sector de la Organización (item 12): antes texto libre, ahora catálogo con código.
        $this->sembrar('sector_organizacion', [
            '1 - Educación', '2 - Cultura', '3 - Ambiental', '4 - Salud',
            '5 - Infraestructura', '6 - Vivienda', '7 - Gobierno', '8 - Institucional',
        ]);

        $this->sembrar('genero', ['Mujer', 'Hombre', 'No Binario', 'Transgénero', 'LGBTIQ+ OSIGD']);

        $this->sembrar('etnico', ['Indígenas', 'Comun. Negras - Afro- Raizales - Palenqueras', 'Pueblo Rom o Gitano']);

        $this->sembrar('problematica', ['Institucional', 'Ambiental', 'Salud', 'Cultural', 'Educativa']);

        $this->sembrar('clasificacion_organizacion', ['Con Ánimo de Lucro', 'Sin Ánimo de Lucro']);

        $this->sembrar('enfoque_diferencial', ['Discapacidad', 'Cabeza de Hogar', 'Víctimas de Conflicto', 'Habitante de la calle']);

        // tamano_familia ya no usa catálogo: item16 pasa a ser un número libre.
        CaracterizacionOpcion::where('categoria', 'tamano_familia')->update(['activo' => false]);

        // Ítems 17 (Canal de Comunicación) y 18 (Idiomas/Lenguas/Dialectos) fueron eliminados
        // del formulario — se desactivan sus catálogos en vez de borrarlos.
        CaracterizacionOpcion::whereIn('categoria', ['canal_comunicacion', 'idioma_lengua_dialecto'])->update(['activo' => false]);

        $this->sembrar('ods', [
            '1. Fin de la pobreza.', '2. Hambre cero', '3. Salud y Bienestar',
            '4. Educación de Calidad', '5. Igualdad de Género', '6. Agua limpia y saneamiento',
            '7. Energía asequible y no contaminante.', '8. trabajo decente y crecimiento económico',
            '9. Industria, innovación e infraestructura.', '10. Reducción de las desigualdades.',
            '11. Ciudades y Comunidades sostenibles.', '12. Producción y consumo responsables.',
            '13. Acción por el clima.', '14. Vida Submarina.', '15. Vida de Ecosistemas terrestres.',
            '16. Paz, justicia e instituciones sólidas.', '17. Alianzas para lograr los objetivos.',
            'N/A',
        ]);

        $this->sembrar('ddhh', [
            '1. Todos los seres humanos nacen libres e iguales.',
            '2. Todas las personas tienen los derechos proclamados en esta carta.',
            '3. Todo individuo tiene derecho a la vida, la libertad y la seguridad.',
            '4. Nadie será sometido a esclavitud ni a servidumbre.',
            '5. Nadie será sometido a penas, torturas ni tratos crueles o inhumanos.',
            '6. Todo ser humano tiene derecho al reconocimiento de su personalidad jurídica.',
            '7. Todos tienen derecho a la protección contra la discriminación.',
            '8. Toda persona tiene derecho a un recurso efectivo ante los tribunales.',
            '9. Nadie podrá ser detenido, desterrado ni preso arbitrariamente.',
            '10. Toda persona tiene derecho a un tribunal independiente e imparcial.',
            '11. Toda persona tiene derecho a la presunción de inocencia y a penas justas.',
            '12. Toda persona tiene derecho a la privacidad, la honra y la reputación.',
            '13. Toda persona tiene derecho a la libre circulación y a elegir libremente su residencia.',
            '14. Toda persona tiene derecho al asilo en cualquier país.',
            '15. Toda persona tiene derecho a una nacionalidad y a cambiar de nacionalidad.',
            '16. Todos los individuos tienen derecho a un matrimonio libre y a la protección de la familia.',
            '17. Toda persona tiene derecho a la propiedad individual o colectiva.',
            '18. Toda persona tiene derecho a la libertad de pensamiento, conciencia y religión.',
            '19. Todo individuo tiene derecho a la libertad de opinión y de expresión.',
            '20. Toda persona tiene derecho a la libertad de reunión y asociación.',
            '21. Toda persona tiene derecho a participar, directa o indirectamente, en el gobierno de su país.',
            '22. Toda persona tiene derecho a la seguridad social.',
            '23. Toda persona tiene derecho al trabajo y la protección contra el desempleo.',
            '24. Toda persona tiene derecho al descanso y al disfrute del tiempo libre.',
            '25. Toda persona tiene derecho al bienestar: alimentación, vivienda, asistencia médica, vestido y otros servicios sociales básicos.',
            '26. Toda persona tiene derecho a la educación y al libre desarrollo de la personalidad.',
            '27. Toda persona tiene derecho a tomar parte en la vida cultural de su comunidad.',
            '28. Toda persona tiene derecho a un orden social que garantice los derechos de esta carta.',
            '29. Toda persona tiene deberes con respecto a su comunidad.',
            'No aplica',
        ]);

        $this->sembrar('pilares_paz', [
            '1. Lucha contra el crimen organizado.', '2. Apoyo al proceso de paz', '3. Desarrollo Rural',
            '4. Erradicación de minas antipersonas', 'N/A',
        ]);

        $this->sembrar('politica_publica', [
            'PP. Discapacidad e inclusión social.', 'PP. Primera infancia, Infancia, Adolescencia.',
            'PP. De Juventud.', 'PP. Para la Gestión de la salud mental y la prevención del consumo de Drogas.',
            'PP. NAL. De Equidad de Género', 'PP. Equidad de género para las mujeres de Casanare.',
            'PP Envejecimiento y Vejez.', 'PP. Comunal', 'PP. Libertad Religiosa, de Culto y Conciencia.',
            'PP. NAL. De Víctimas del conflicto armado.',
            'PP. NAL. Garantía del goce efectivo de los Derechos de las personas LGTBI.',
            'POL. NAL. De Reintegración social y económica para personas y grupos armados ilegales.',
            'N/A',
        ]);

        $this->sembrar('politica_mipg', [
            'Planeación Institucional', 'Gestión presupuestal y eficiencia del gasto público',
            'Gestión del Talento Humano', 'Integridad',
            'Transparencia, acceso a la información pública y lucha contra la corrupción',
            'Fortalecimiento Organizacional y simplificación de procesos', 'Servicio al Ciudadano',
            'Participación ciudadana en la Gestión Pública', 'Racionalización de trámites',
            'Gestión Documental', 'Gobierno digital', 'Seguridad Digital',
            'Gestión del Conocimiento y la Innovación', 'Seguimiento y evaluación del desempeño institucional',
            'Control Interno', 'Mejora normativa', 'Gestión de la Información Estadística',
            'Compras y contratación pública', 'Defensa Jurídica',
        ]);

        $this->sembrar('acto_tipo', ['CTO', 'CV', 'CA', 'RES']);
    }

    private function sembrar(string $categoria, array $valores): void
    {
        foreach ($valores as $orden => $valor) {
            CaracterizacionOpcion::updateOrCreate(
                ['categoria' => $categoria, 'valor' => (string) $valor],
                ['orden' => $orden, 'activo' => true]
            );
        }
    }

    private function sembrarAgrupado(string $categoria, string $grupo, array $valores): void
    {
        foreach ($valores as $orden => $valor) {
            CaracterizacionOpcion::updateOrCreate(
                ['categoria' => $categoria, 'valor' => $valor],
                ['grupo' => $grupo, 'orden' => $orden, 'activo' => true]
            );
        }
    }
}
