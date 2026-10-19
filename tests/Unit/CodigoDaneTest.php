<?php

namespace Tests\Unit;

use App\Models\CaracterizacionAsistente;
use App\Models\CaracterizacionPersona;
use App\Support\CodigoDane;
use PHPUnit\Framework\TestCase;

class CodigoDaneTest extends TestCase
{
    public function test_el_codigo_sale_del_municipio_sin_importar_tildes_ni_mayusculas(): void
    {
        $this->assertSame('85230', CodigoDane::deMunicipio(' Orocué '));
        $this->assertSame('85230', CodigoDane::deMunicipio('OROCUE'));
        $this->assertSame('85001', CodigoDane::deMunicipio('yopal'));
        $this->assertSame('85325', CodigoDane::deMunicipio('San Luis de Palenque'));
    }

    public function test_un_municipio_de_otro_departamento_no_tiene_codigo(): void
    {
        $this->assertNull(CodigoDane::deMunicipio('Bogotá'));
        $this->assertNull(CodigoDane::deMunicipio(null));
    }

    public function test_los_19_municipios_de_casanare_tienen_codigo_unico(): void
    {
        $codigos = array_values(CodigoDane::todos());
        $this->assertCount(19, $codigos);
        $this->assertCount(19, array_unique($codigos));
        foreach ($codigos as $c) {
            $this->assertMatchesRegularExpression('/^85\d{3}$/', $c);
        }
    }

    public function test_grupo_etareo_sigue_la_formula_del_excel(): void
    {
        $this->assertSame('', CaracterizacionAsistente::calcularGrupoEtareo(0));
        $this->assertSame('Primera infancia', CaracterizacionAsistente::calcularGrupoEtareo(5));
        $this->assertSame('Infancia', CaracterizacionAsistente::calcularGrupoEtareo(11));
        $this->assertSame('Adolescencia', CaracterizacionAsistente::calcularGrupoEtareo(17));
        $this->assertSame('Jóvenes', CaracterizacionAsistente::calcularGrupoEtareo(28));
        $this->assertSame('Adultos', CaracterizacionAsistente::calcularGrupoEtareo(60));
        $this->assertSame('Mayores', CaracterizacionAsistente::calcularGrupoEtareo(61));
    }

    public function test_la_clave_de_persona_ignora_mayusculas_y_espacios(): void
    {
        $this->assertSame('C.C|123', CaracterizacionPersona::clave(' c.c ', ' 123 '));
        $this->assertSame(CaracterizacionPersona::clave('C.C', '123'), CaracterizacionPersona::clave('c.c', '123'));
    }
}
