<?php

namespace App\Services\Siat;

use Illuminate\Support\Collection;

class SiatCatalogRegistry
{
    /**
     * @return Collection<string, array{key: string, name: string, operation: string, wsdl_url: string, hint: string|null}>
     */
    public function all(): Collection
    {
        return collect([
            $this->catalog('actividades', 'Actividades economicas', 'sincronizarActividades'),
            $this->catalog(
                'fecha_hora',
                'Fecha y hora actual',
                'sincronizarFechaHora',
                'Devuelve la fecha y hora actual del SIAT para verificar sincronizacion temporal.'
            ),
            $this->catalog(
                'actividades_documento_sector',
                'Actividades por documento sector',
                'sincronizarListaActividadesDocumentoSector',
                'Relaciona codigoActividad con codigoDocumentoSector; SIAT no devuelve descripcion en este catalogo.'
            ),
            $this->catalog('leyendas_factura', 'Leyendas factura', 'sincronizarListaLeyendasFactura'),
            $this->catalog('mensajes_servicios', 'Mensajes servicios', 'sincronizarListaMensajesServicios'),
            $this->catalog('productos_servicios', 'Productos servicios', 'sincronizarListaProductosServicios'),
            $this->catalog('eventos_significativos', 'Eventos significativos', 'sincronizarParametricaEventosSignificativos'),
            $this->catalog('motivos_anulacion', 'Motivos anulacion', 'sincronizarParametricaMotivoAnulacion'),
            $this->catalog('paises_origen', 'Paises origen', 'sincronizarParametricaPaisOrigen'),
            $this->catalog('tipos_documento_identidad', 'Tipos documento identidad', 'sincronizarParametricaTipoDocumentoIdentidad'),
            $this->catalog(
                'tipos_documento_sector',
                'Tipos documento sector',
                'sincronizarParametricaTipoDocumentoSector',
                'Devuelve codigoClasificador y descripcion del documento sector.'
            ),
            $this->catalog('tipos_emision', 'Tipos emision', 'sincronizarParametricaTipoEmision'),
            $this->catalog('tipos_habitacion', 'Tipos habitacion', 'sincronizarParametricaTipoHabitacion'),
            $this->catalog('tipos_metodo_pago', 'Tipos metodo pago', 'sincronizarParametricaTipoMetodoPago'),
            $this->catalog('tipos_moneda', 'Tipos moneda', 'sincronizarParametricaTipoMoneda'),
            $this->catalog('tipos_punto_venta', 'Tipos punto venta', 'sincronizarParametricaTipoPuntoVenta'),
            $this->catalog('tipos_factura', 'Tipos factura', 'sincronizarParametricaTiposFactura'),
            $this->catalog('unidades_medida', 'Unidades medida', 'sincronizarParametricaUnidadMedida'),
        ])->keyBy('key');
    }

    /**
     * @return array{key: string, name: string, operation: string, wsdl_url: string, hint: string|null}
     */
    public function find(string $key): array
    {
        $catalog = $this->all()->get($key);

        abort_if($catalog === null, 404);

        return $catalog;
    }

    /**
     * @return array{key: string, name: string, operation: string, wsdl_url: string, hint: string|null}
     */
    private function catalog(string $key, string $name, string $operation, ?string $hint = null): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'operation' => $operation,
            'wsdl_url' => SiatWsdlRegistry::SYNCHRONIZATION,
            'hint' => $hint,
        ];
    }
}
