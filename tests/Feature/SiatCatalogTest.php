<?php

namespace Tests\Feature;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCatalogItem;
use App\Models\SinCatalogSync;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\SiatSoapClientFactory;
use App\Services\Siat\SiatWsdlRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiatCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_menu_and_index_show_available_siat_catalogs(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $this->siatConfiguration($user);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SIAT')
            ->assertSee('Catalogos SIAT')
            ->assertSee(route('siat.catalogs.index'));

        $this
            ->actingAs($user)
            ->get(route('siat.catalogs.index'))
            ->assertOk()
            ->assertDontSee('Datos de sincronizacion')
            ->assertDontSee('CUIS-CURRENT-123')
            ->assertSee('Sucursal / PV')
            ->assertSee('Catalogos disponibles')
            ->assertSee('Actividades economicas')
            ->assertSee('Fecha y hora actual')
            ->assertSee('Tipos documento identidad')
            ->assertSee(route('siat.catalogs.sync-all'), false)
            ->assertSee('Sincronizar todos')
            ->assertDontSee('sincronizarParametricaUnidadMedida');
    }

    public function test_company_user_can_sync_all_catalogs_with_one_request(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        $client = new class
        {
            public int $calls = 0;

            public function __call(string $name, array $arguments): object
            {
                $this->calls++;

                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'transaccion' => true,
                        'listaCodigos' => [
                            (object) ['codigoClasificador' => 1, 'descripcion' => $name],
                        ],
                    ],
                ];
            }
        };
        $this->fakeSoapClient($client);

        $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync-all'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertRedirect(route('siat.catalogs.index'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Catalogos: 18')
                && str_contains($message, 'Exitosos: 18')
                && str_contains($message, 'Observados: 0'));

        $this->assertSame(18, $client->calls);
        $this->assertSame(18, SinCatalogSync::query()
            ->where('company_id', $user->company_id)
            ->count());
        $this->assertSame(18, SinCatalogSync::query()
            ->where('company_id', $user->company_id)
            ->distinct('catalog_key')
            ->count('catalog_key'));
    }

    public function test_company_user_can_sync_catalog_and_view_stored_items(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        $this->fakeSoapClient(new class
        {
            public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
            {
                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'transaccion' => true,
                        'listaCodigos' => [
                            (object) ['codigoClasificador' => 1, 'descripcion' => 'CI - CEDULA DE IDENTIDAD'],
                            (object) ['codigoClasificador' => 5, 'descripcion' => 'NIT'],
                        ],
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('data-datatable', false)
            ->assertSee(route('datatables.siat-catalog-items', 'tipos_documento_identidad'), false);

        $this->assertCatalogDataTableContains($user, 'tipos_documento_identidad', [
            'CI - CEDULA DE IDENTIDAD',
            'NIT',
        ]);

        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'operation' => 'sincronizarParametricaTipoDocumentoIdentidad',
            'transaccion' => true,
            'items_count' => 2,
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'classifier_code' => '1',
            'description' => 'CI - CEDULA DE IDENTIDAD',
        ]);
    }

    public function test_invoice_legends_with_same_activity_are_stored_as_distinct_items(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        $this->fakeSoapClient(new class
        {
            public function sincronizarListaLeyendasFactura(array $params): object
            {
                return (object) [
                    'RespuestaListaParametricasLeyendas' => (object) [
                        'transaccion' => true,
                        'listaLeyendas' => [
                            (object) [
                                'codigoActividad' => '4761100',
                                'descripcionLeyenda' => 'Ley N° 453: Puedes acceder a la reclamación cuando tus derechos han sido vulnerados. ',
                            ],
                            (object) [
                                'codigoActividad' => '4761100',
                                'descripcionLeyenda' => 'Ley N° 453: El proveedor debe brindar atención sin discriminación.',
                            ],
                        ],
                    ],
                ];
            }
        });

        $this->actingAs($user)
            ->post(route('siat.catalogs.sync', 'leyendas_factura'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'leyendas_factura'));

        $items = SinCatalogItem::query()
            ->where('catalog_key', 'leyendas_factura')
            ->orderBy('description')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame(['4761100'], $items->pluck('classifier_code')->unique()->values()->all());
        $this->assertCount(2, $items->pluck('item_key')->unique());
        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'leyendas_factura',
            'items_count' => 2,
            'transaccion' => true,
        ]);
    }

    public function test_company_user_can_sync_catalog_multiple_times_for_sin_approval(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        $client = new class
        {
            public int $calls = 0;

            public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
            {
                $this->calls++;

                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'transaccion' => true,
                        'listaCodigos' => [
                            (object) ['codigoClasificador' => 1, 'descripcion' => 'CI'],
                        ],
                    ],
                ];
            }
        };
        $this->fakeSoapClient($client);

        $response = $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
                'sync_count' => 3,
            ]);

        $response
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Sincronizacion ejecutada 3 veces')
                && str_contains($message, 'Exitosas: 3'));

        $this
            ->actingAs($user)
            ->get(route('siat.catalogs.show', 'tipos_documento_identidad'))
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertSame(3, $client->calls);
        $this->assertSame(3, SinCatalogSync::query()
            ->where('company_id', $user->company_id)
            ->where('catalog_key', 'tipos_documento_identidad')
            ->count());
        $this->assertSame(1, SinCatalogItem::query()
            ->where('company_id', $user->company_id)
            ->where('catalog_key', 'tipos_documento_identidad')
            ->count());
    }

    public function test_company_user_can_sync_current_siat_date_time(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);

        $this->fakeSoapClient(new class
        {
            public function sincronizarFechaHora(array $params): object
            {
                return (object) [
                    'RespuestaFechaHora' => (object) [
                        'transaccion' => true,
                        'fechaHora' => '2026-07-30T16:30:45.000',
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.catalogs.sync', 'fecha_hora'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertCatalogDataTableContains($user, 'fecha_hora', [
            '2026-07-30T16:30:45.000',
            'fechaHora',
        ]);

        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'fecha_hora',
            'operation' => 'sincronizarFechaHora',
            'wsdl_url' => SiatWsdlRegistry::SYNCHRONIZATION,
            'transaccion' => true,
            'items_count' => 1,
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'fecha_hora',
            'item_key' => 'fechaHora',
            'classifier_code' => null,
            'description' => '2026-07-30T16:30:45.000',
        ]);
    }

    public function test_catalog_sync_accepts_legacy_cuis_without_point_of_sale_link(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        $legacyCuis = SinCuis::query()
            ->where('company_id', $user->company_id)
            ->firstOrFail();
        $legacyCuis->update([
            'sin_branch_id' => null,
            'sin_point_of_sale_id' => null,
            'cuis_code' => 'LEGACY-CUIS-123',
        ]);

        $this->fakeSoapClient(new class
        {
            public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
            {
                if (($params['SolicitudSincronizacion']['cuis'] ?? null) !== 'LEGACY-CUIS-123') {
                    throw new RuntimeException('No se uso el CUIS historico.');
                }

                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'transaccion' => true,
                        'listaCodigos' => [
                            (object) ['codigoClasificador' => 1, 'descripcion' => 'CI'],
                        ],
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'classifier_code' => '1',
            'description' => 'CI',
        ]);

        $this->assertDatabaseHas('sin_cuis', [
            'id' => $legacyCuis->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'cuis_code' => 'LEGACY-CUIS-123',
        ]);
    }

    public function test_catalog_sync_stores_activity_document_sector_combinations_with_repeated_activity_codes(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);

        $this->fakeSoapClient(new class
        {
            public function sincronizarListaActividadesDocumentoSector(array $params): object
            {
                return (object) [
                    'RespuestaListaActividadesDocumentoSector' => (object) [
                        'transaccion' => true,
                        'listaActividadesDocumentoSector' => [
                            (object) [
                                'codigoActividad' => 550000,
                                'codigoDocumentoSector' => 1,
                                'tipoDocumentoSector' => 'FCV',
                            ],
                            (object) [
                                'codigoActividad' => 550000,
                                'codigoDocumentoSector' => 2,
                                'tipoDocumentoSector' => 'NCD',
                            ],
                            (object) [
                                'codigoActividad' => 550000,
                                'codigoDocumentoSector' => 1,
                                'tipoDocumentoSector' => 'FCV',
                            ],
                        ],
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.catalogs.sync', 'actividades_documento_sector'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertCatalogDataTableContains($user, 'actividades_documento_sector', [
            'codigoActividad',
            'codigoDocumentoSector',
            'tipoDocumentoSector',
            'FCV',
            'NCD',
        ]);

        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades_documento_sector',
            'transaccion' => true,
            'items_count' => 2,
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades_documento_sector',
            'item_key' => 'codigoActividad:550000|codigoDocumentoSector:1',
            'classifier_code' => '550000',
            'description' => null,
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades_documento_sector',
            'item_key' => 'codigoActividad:550000|codigoDocumentoSector:2',
            'classifier_code' => '550000',
            'description' => null,
        ]);
        $this->assertEquals([
            'codigoActividad' => 550000,
            'codigoDocumentoSector' => 1,
            'tipoDocumentoSector' => 'FCV',
        ], SinCatalogItem::query()
            ->where('catalog_key', 'actividades_documento_sector')
            ->where('item_key', 'codigoActividad:550000|codigoDocumentoSector:1')
            ->firstOrFail()
            ->raw_data);
        $this->assertSame(2, SinCatalogItem::query()
            ->where('catalog_key', 'actividades_documento_sector')
            ->where('classifier_code', '550000')
            ->count());
    }

    public function test_activity_document_sector_view_shows_raw_fields_when_description_is_empty(): void
    {
        $user = $this->companyUser(['siat-catalogs.view']);
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades_documento_sector',
            'item_key' => 'codigoActividad:9511000|codigoDocumentoSector:47',
            'classifier_code' => '9511000',
            'description' => null,
            'raw_data' => [
                'codigoActividad' => '9511000',
                'codigoDocumentoSector' => 47,
                'tipoDocumentoSector' => 'NCDDE',
            ],
        ]);

        $this
            ->actingAs($user)
            ->get(route('siat.catalogs.show', 'actividades_documento_sector'))
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertCatalogDataTableContains($user, 'actividades_documento_sector', [
            '9511000',
            'NCDDE',
            'codigoDocumentoSector',
            '47',
        ], 'NCDDE');
    }

    public function test_company_user_can_enable_and_disable_catalog_items_individually(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $item = SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => '1',
            'classifier_code' => '1',
            'description' => 'CI',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->patch(route('siat.catalogs.items.update-status', ['tipos_documento_identidad', $item]), [
                'is_active' => false,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'));

        $this->assertFalse($item->refresh()->is_active);

        $this
            ->actingAs($user)
            ->get(route('siat.catalogs.show', 'tipos_documento_identidad'))
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertCatalogDataTableContains($user, 'tipos_documento_identidad', ['Inactivo']);
    }

    public function test_company_user_can_bulk_enable_and_disable_selected_or_all_catalog_items(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $first = SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => '1',
            'is_active' => true,
        ]);
        $second = SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => '2',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->patch(route('siat.catalogs.items.status', 'tipos_documento_identidad'), [
                'scope' => 'selected',
                'items' => [$first->id],
                'is_active' => false,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'));

        $this->assertFalse($first->refresh()->is_active);
        $this->assertTrue($second->refresh()->is_active);

        $this
            ->actingAs($user)
            ->patch(route('siat.catalogs.items.status', 'tipos_documento_identidad'), [
                'scope' => 'all',
                'is_active' => false,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'));

        $this->assertFalse($first->refresh()->is_active);
        $this->assertFalse($second->refresh()->is_active);
    }

    public function test_catalog_sync_preserves_existing_item_statuses(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => 'codigoClasificador:1',
            'classifier_code' => '1',
            'description' => 'CI anterior',
            'is_active' => false,
        ]);

        $this->fakeSoapClient(new class
        {
            public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
            {
                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'transaccion' => true,
                        'listaCodigos' => [
                            (object) ['codigoClasificador' => 1, 'descripcion' => 'CI actualizado'],
                            (object) ['codigoClasificador' => 2, 'descripcion' => 'Pasaporte'],
                        ],
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'));

        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => 'codigoClasificador:1',
            'description' => 'CI actualizado',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => 'codigoClasificador:2',
            'description' => 'Pasaporte',
            'is_active' => true,
        ]);
    }

    public function test_catalog_sync_always_uses_synchronization_wsdl_even_when_token_has_another_service_url(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        SinApiToken::query()
            ->where('company_id', $user->company_id)
            ->firstOrFail()
            ->update(['wsdl_url' => SiatWsdlRegistry::OPERATIONS]);

        $factory = new class extends SiatSoapClientFactory
        {
            public array $wsdlUrls = [];

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                $this->wsdlUrls[] = $wsdlUrl;

                return new class
                {
                    public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
                    {
                        return (object) [
                            'RespuestaListaParametricas' => (object) [
                                'transaccion' => true,
                                'listaCodigos' => [
                                    (object) ['codigoClasificador' => 1, 'descripcion' => 'CI'],
                                ],
                            ],
                        ];
                    }
                };
            }
        };

        $this->instance(SiatSoapClientFactory::class, $factory);

        $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'));

        $this->assertSame([SiatWsdlRegistry::SYNCHRONIZATION], $factory->wsdlUrls);
        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'wsdl_url' => SiatWsdlRegistry::SYNCHRONIZATION,
            'transaccion' => true,
        ]);
    }

    public function test_document_sector_catalog_sync_stores_items_from_raw_soap_xml_response(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);

        $this->fakeSoapClient(new class
        {
            public function sincronizarParametricaTipoDocumentoSector(array $params): string
            {
                return <<<'XML'
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
   <soap:Body>
      <ns2:sincronizarParametricaTipoDocumentoSectorResponse xmlns:ns2="https://siat.impuestos.gob.bo/">
         <RespuestaListaParametricas>
            <transaccion>true</transaccion>
            <listaCodigos>
               <codigoClasificador>43</codigoClasificador>
               <descripcion>FACTURA COMERCIAL DE EXPORTACIÓN HIDROCARBUROS</descripcion>
            </listaCodigos>
            <listaCodigos>
               <codigoClasificador>1</codigoClasificador>
               <descripcion>FACTURA COMPRA-VENTA</descripcion>
            </listaCodigos>
         </RespuestaListaParametricas>
      </ns2:sincronizarParametricaTipoDocumentoSectorResponse>
   </soap:Body>
</soap:Envelope>
XML;
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.catalogs.sync', 'tipos_documento_sector'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertCatalogDataTableContains($user, 'tipos_documento_sector', [
            'FACTURA COMERCIAL DE EXPORTACIÓN HIDROCARBUROS',
            'FACTURA COMPRA-VENTA',
        ]);

        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_sector',
            'operation' => 'sincronizarParametricaTipoDocumentoSector',
            'wsdl_url' => SiatWsdlRegistry::SYNCHRONIZATION,
            'transaccion' => true,
            'items_count' => 2,
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_sector',
            'item_key' => 'codigoClasificador:43',
            'classifier_code' => '43',
            'description' => 'FACTURA COMERCIAL DE EXPORTACIÓN HIDROCARBUROS',
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_sector',
            'item_key' => 'codigoClasificador:1',
            'classifier_code' => '1',
            'description' => 'FACTURA COMPRA-VENTA',
        ]);
    }

    public function test_catalog_items_are_isolated_by_company_when_sync_replaces_data(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $otherCompany = Company::factory()->create();
        $pointOfSale = $this->siatConfiguration($user);

        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => 'old',
            'classifier_code' => 'OLD',
            'description' => 'Dato antiguo',
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $otherCompany->id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => 'other',
            'classifier_code' => 'OTHER',
            'description' => 'Dato de otra empresa',
        ]);

        $this->fakeSoapClient(new class
        {
            public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
            {
                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'transaccion' => true,
                        'listaCodigos' => [
                            (object) ['codigoClasificador' => 9, 'descripcion' => 'Nuevo dato'],
                        ],
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertRedirect(route('siat.catalogs.show', 'tipos_documento_identidad'));

        $this->assertDatabaseMissing('sin_catalog_items', [
            'company_id' => $user->company_id,
            'classifier_code' => 'OLD',
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $otherCompany->id,
            'classifier_code' => 'OTHER',
        ]);
    }

    public function test_false_catalog_response_is_stored_without_removing_previous_items(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'item_key' => '1',
            'classifier_code' => '1',
            'description' => 'CI vigente',
        ]);

        $this->fakeSoapClient(new class
        {
            public function sincronizarParametricaTipoDocumentoIdentidad(array $params): object
            {
                return (object) [
                    'RespuestaListaParametricas' => (object) [
                        'mensajesList' => (object) ['descripcion' => 'CUIS invalido o vencido.'],
                        'transaccion' => false,
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('data-datatable', false);

        $this->assertCatalogDataTableContains($user, 'tipos_documento_identidad', ['CI vigente']);

        $this->assertDatabaseHas('sin_catalog_syncs', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'transaccion' => false,
            'items_count' => 0,
            'message' => 'CUIS invalido o vencido.',
        ]);
        $this->assertDatabaseHas('sin_catalog_items', [
            'company_id' => $user->company_id,
            'catalog_key' => 'tipos_documento_identidad',
            'classifier_code' => '1',
            'description' => 'CI vigente',
        ]);
    }

    public function test_catalog_sync_requires_point_of_sale_selection(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->from(route('siat.catalogs.index'))
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'))
            ->assertOk()
            ->assertSee('Selecciona la sucursal y punto de venta para sincronizar.');
    }

    public function test_catalog_sync_rejects_more_than_fifty_repetitions(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $pointOfSale = $this->siatConfiguration($user);

        $this
            ->actingAs($user)
            ->from(route('siat.catalogs.index'))
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
                'sync_count' => 51,
            ])
            ->assertRedirect(route('siat.catalogs.index'))
            ->assertSessionHasErrors('sync_count');
    }

    public function test_catalog_sync_requires_siat_configuration_and_cuis_for_selected_point(): void
    {
        $user = $this->companyUser([
            'siat-catalogs.view',
            'siat-catalogs.sync',
        ]);
        $branch = SinBranch::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => 4,
        ]);
        $pointOfSale = SinPointOfSale::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 2,
        ]);

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->from(route('siat.catalogs.index'))
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('Registra primero el token API.')
            ->assertSee('Registra primero la autorizacion SIN.')
            ->assertSee('Genera primero el CUIS para la sucursal y punto de venta seleccionados.');
    }

    public function test_viewer_can_view_catalogs_but_cannot_sync(): void
    {
        $user = $this->companyUser(['siat-catalogs.view']);

        $this
            ->actingAs($user)
            ->get(route('siat.catalogs.index'))
            ->assertOk()
            ->assertDontSee(route('siat.catalogs.sync', 'tipos_documento_identidad'))
            ->assertDontSee(route('siat.catalogs.sync-all'));

        $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync', 'tipos_documento_identidad'))
            ->assertForbidden();

        $this
            ->actingAs($user)
            ->post(route('siat.catalogs.sync-all'))
            ->assertForbidden();
    }

    public function test_role_seeder_registers_catalog_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('siat-catalogs.sync'));
        $this->assertTrue($manager->hasPermissionTo('siat-catalogs.sync'));
        $this->assertTrue($viewer->hasPermissionTo('siat-catalogs.view'));
        $this->assertFalse($viewer->hasPermissionTo('siat-catalogs.sync'));
        $this->assertFalse($cashier->hasPermissionTo('siat-catalogs.view'));
    }

    private function fakeSoapClient(object $client): void
    {
        $this->instance(SiatSoapClientFactory::class, new class($client) extends SiatSoapClientFactory
        {
            public function __construct(private readonly object $client) {}

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                return $this->client;
            }
        });
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function assertCatalogDataTableContains(User $user, string $catalog, array $needles, string $search = ''): void
    {
        $query = http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => [
                'value' => $search,
                'regex' => 'false',
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('datatables.siat-catalog-items', $catalog).'?'.$query)
            ->assertOk();

        $content = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, (string) $content);
        }
    }

    private function siatConfiguration(User $user): SinPointOfSale
    {
        $branch = SinBranch::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => 0,
            'name' => 'Casa matriz',
            'is_main' => true,
        ]);
        $pointOfSale = SinPointOfSale::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 0,
            'name' => 'Punto de venta 0',
            'is_default' => true,
        ]);
        $apiToken = SinApiToken::factory()->create([
            'company_id' => $user->company_id,
            'api_token' => 'TOKEN-API-123456',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);
        $authorization = SinAuthorization::factory()->create([
            'company_id' => $user->company_id,
            'tax_id' => '123456789',
            'system_code' => 'SYSTEM-CODE-123',
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'branch_code' => 0,
            'point_of_sale_code' => 0,
        ]);
        SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'tax_id' => '123456789',
            'branch_code' => 0,
            'point_of_sale_code' => 0,
            'transaccion' => true,
            'cuis_code' => 'CUIS-CURRENT-123',
            'requested_at' => now(),
        ]);

        return $pointOfSale;
    }

    private function companyUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
