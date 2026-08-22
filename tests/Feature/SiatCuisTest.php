<?php

namespace Tests\Feature;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\SiatSoapClientFactory;
use App\Services\Siat\SiatWsdlRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiatCuisTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuis_menu_and_history_page_are_available_to_company_users(): void
    {
        $user = $this->companyUser([
            'dashboard.view',
            'siat-cuis.view',
            'siat-cuis.request',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('SIAT')
            ->assertSee('CUIS')
            ->assertSee(route('siat.cuis.index'));

        $this
            ->actingAs($user)
            ->get(route('siat.cuis.index'))
            ->assertOk()
            ->assertSee('CUIS actual')
            ->assertSee('Historico CUIS')
            ->assertSee('Configuracion incompleta');
    }

    public function test_company_user_can_request_cuis_and_store_successful_result(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
        ]);
        [$apiToken, $authorization, $pointOfSale] = $this->siatConfiguration($user);

        $this->fakeSoapClient(new class
        {
            public function cuis(array $params): object
            {
                return (object) [
                    'RespuestaCuis' => (object) [
                        'codigo' => 'CUIS-OK-123456',
                        'transaccion' => true,
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.cuis.request'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('CUIS-OK-123456')
            ->assertSee('Generado');

        $this->assertDatabaseHas('sin_cuis', [
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'tax_id' => '123456789',
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'transaccion' => true,
            'cuis_code' => 'CUIS-OK-123456',
        ]);
    }

    public function test_cuis_request_uses_codes_wsdl_even_when_token_stores_another_service_url(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
        ]);
        [$apiToken, , $pointOfSale] = $this->siatConfiguration($user);
        $apiToken->update(['wsdl_url' => SiatWsdlRegistry::OPERATIONS]);

        $factory = new class extends SiatSoapClientFactory
        {
            public array $wsdlUrls = [];

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                $this->wsdlUrls[] = $wsdlUrl;

                return new class
                {
                    public function cuis(array $params): object
                    {
                        return (object) [
                            'RespuestaCuis' => (object) [
                                'codigo' => 'CUIS-CODES-WSDL-123',
                                'transaccion' => true,
                            ],
                        ];
                    }
                };
            }
        };

        $this->instance(SiatSoapClientFactory::class, $factory);

        $this
            ->actingAs($user)
            ->post(route('siat.cuis.request'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertRedirect(route('siat.cuis.index'));

        $this->assertSame([SiatWsdlRegistry::CODES], $factory->wsdlUrls);
        $this->assertDatabaseHas('sin_cuis', [
            'company_id' => $user->company_id,
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'cuis_code' => 'CUIS-CODES-WSDL-123',
        ]);
    }

    public function test_replacing_token_or_system_code_uses_the_new_values_and_retires_existing_codes(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
            'sin-api-tokens.manage',
            'sin-authorizations.manage',
        ]);
        [$apiToken, $authorization, $pointOfSale] = $this->siatConfiguration($user);
        $previousCuis = SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'transaccion' => true,
            'cuis_code' => 'CUIS-ANTERIOR',
        ]);
        $previousCufd = SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $previousCuis->id,
        ]);

        $this->actingAs($user)->put(route('sin-api-token.update'), [
            'api_token' => 'TOKEN-NUEVO-654321',
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('sin-api-token.index'));

        $this->actingAs($user)->put(route('parameters.authorization.update'), [
            'tax_id' => '123456789',
            'legal_name' => 'Empresa Demo SRL',
            'system_code' => 'SISTEMA-NUEVO-456',
            'environment_code' => SiatEnvironment::TestingAndPilot->value,
            'modality_code' => SiatModality::ComputerizedOnline->value,
            'branch_code' => 0,
            'point_of_sale_code' => 0,
        ])->assertRedirect(route('parameters.authorization.index'));

        $factory = new class extends SiatSoapClientFactory
        {
            public ?string $token = null;

            public array $payload = [];

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                $this->token = $apiToken;

                return new class($this)
                {
                    public function __construct(private readonly object $factory) {}

                    public function cuis(array $params): object
                    {
                        $this->factory->payload = $params;

                        return (object) ['RespuestaCuis' => (object) [
                            'codigo' => 'CUIS-NUEVO-123',
                            'transaccion' => true,
                        ]];
                    }
                };
            }
        };
        $this->instance(SiatSoapClientFactory::class, $factory);

        $this->actingAs($user)->post(route('siat.cuis.request'), [
            'sin_point_of_sale_id' => $pointOfSale->id,
        ])->assertRedirect(route('siat.cuis.index'));

        $this->assertSame('TOKEN-NUEVO-654321', $factory->token);
        $this->assertSame('SISTEMA-NUEVO-456', $factory->payload['SolicitudCuis']['codigoSistema']);
        $this->assertDatabaseHas('sin_cuis', [
            'id' => $previousCuis->id,
            'invalidation_reason' => 'Reemplazado al actualizar el token API.',
        ]);
        $this->assertDatabaseHas('sin_cufds', [
            'id' => $previousCufd->id,
            'invalidation_reason' => 'Reemplazado al actualizar el token API.',
        ]);
        $this->assertDatabaseHas('sin_cuis', [
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'cuis_code' => 'CUIS-NUEVO-123',
            'invalidated_at' => null,
        ]);
    }

    public function test_later_false_cuis_response_is_stored_without_replacing_current_cuis(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
        ]);
        [, , $pointOfSale] = $this->siatConfiguration($user);
        SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'transaccion' => true,
            'cuis_code' => 'CURRENT-CUIS-999',
            'requested_at' => now()->subHour(),
        ]);

        $this->fakeSoapClient(new class
        {
            public function cuis(array $params): object
            {
                return (object) [
                    'RespuestaCuis' => (object) [
                        'mensajesList' => (object) [
                            'codigo' => 980,
                            'descripcion' => 'CUIS ya fue generado para estos parametros.',
                        ],
                        'transaccion' => false,
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.cuis.request'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('CURRENT-CUIS-999')
            ->assertSee('CUIS ya fue generado para estos parametros.')
            ->assertSee('Observado');

        $this->assertSame(2, SinCuis::query()->count());
        $this->assertDatabaseHas('sin_cuis', [
            'company_id' => $user->company_id,
            'transaccion' => false,
            'cuis_code' => null,
            'message' => 'CUIS ya fue generado para estos parametros.',
        ]);
        $this->assertSame('CURRENT-CUIS-999', SinCuis::query()->successful()->latest('requested_at')->first()?->cuis_code);
    }

    public function test_already_generated_response_recovers_codigo_cuis_and_preserves_full_response(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
        ]);
        [, , $pointOfSale] = $this->siatConfiguration($user);

        $this->fakeSoapClient(new class
        {
            public function cuis(array $params): object
            {
                return (object) [
                    'RespuestaCuis' => (object) [
                        'codigoCUIS' => 'CUIS-RECOVERED-123',
                        'fechaVigencia' => '2027-08-03T23:59:59.000',
                        'mensajesList' => (object) [
                            'codigo' => 980,
                            'descripcion' => 'CUIS ya fue generado para estos parametros.',
                        ],
                        'transaccion' => false,
                    ],
                ];
            }
        });

        $this
            ->actingAs($user)
            ->followingRedirects()
            ->post(route('siat.cuis.request'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertSee('CUIS-RECOVERED-123')
            ->assertSee('CUIS ya fue generado para estos parametros.');

        $attempt = SinCuis::query()->latest('requested_at')->firstOrFail();

        $this->assertTrue($attempt->transaccion);
        $this->assertSame('CUIS-RECOVERED-123', $attempt->cuis_code);
        $this->assertSame('2027-08-03T23:59:59.000', $attempt->response['RespuestaCuis']['fechaVigencia']);
        $this->assertSame(980, $attempt->response['RespuestaCuis']['mensajesList']['codigo']);
        $this->assertFalse($attempt->response['RespuestaCuis']['transaccion']);
    }

    public function test_existing_cuis_can_be_imported_when_new_database_has_no_local_copy(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
        ]);
        [$apiToken, $authorization, $pointOfSale] = $this->siatConfiguration($user);

        $this->actingAs($user)
            ->post(route('siat.cuis.import'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
                'cuis_code' => 'abc123def456',
            ])
            ->assertRedirect(route('siat.cuis.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sin_cuis', [
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'cuis_code' => 'ABC123DEF456',
            'transaccion' => true,
            'message' => 'CUIS existente importado durante la recuperación de la base de datos.',
        ]);
    }

    public function test_false_response_recovers_direct_alphanumeric_codigo_but_not_numeric_message_code(): void
    {
        $user = $this->companyUser(['siat-cuis.request']);
        [, , $pointOfSale] = $this->siatConfiguration($user);

        $this->fakeSoapClient(new class
        {
            public function cuis(array $params): object
            {
                return (object) ['RespuestaCuis' => (object) [
                    'codigo' => 'CUISDIRECT123',
                    'mensajesList' => (object) ['codigo' => 980],
                    'transaccion' => false,
                ]];
            }
        });

        $this->actingAs($user)->post(route('siat.cuis.request'), [
            'sin_point_of_sale_id' => $pointOfSale->id,
        ]);

        $this->assertDatabaseHas('sin_cuis', [
            'cuis_code' => 'CUISDIRECT123',
            'transaccion' => true,
        ]);
    }

    public function test_cuis_request_requires_token_and_authorization_configuration(): void
    {
        $user = $this->companyUser([
            'siat-cuis.view',
            'siat-cuis.request',
        ]);

        $this
            ->actingAs($user)
            ->from(route('siat.cuis.index'))
            ->post(route('siat.cuis.request'))
            ->assertRedirect(route('siat.cuis.index'))
            ->assertSessionHasErrors(['sin_point_of_sale_id']);
    }

    public function test_viewer_can_open_cuis_page_but_cannot_request_cuis(): void
    {
        $user = $this->companyUser(['siat-cuis.view']);

        $this
            ->actingAs($user)
            ->get(route('siat.cuis.index'))
            ->assertOk()
            ->assertDontSee(route('siat.cuis.request'));

        $this
            ->actingAs($user)
            ->post(route('siat.cuis.request'))
            ->assertForbidden();
    }

    public function test_role_seeder_registers_cuis_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::findByName('admin');
        $manager = Role::findByName('manager');
        $viewer = Role::findByName('viewer');
        $cashier = Role::findByName('cashier');

        $this->assertTrue($admin->hasPermissionTo('siat-cuis.request'));
        $this->assertTrue($manager->hasPermissionTo('siat-cuis.request'));
        $this->assertTrue($viewer->hasPermissionTo('siat-cuis.view'));
        $this->assertFalse($viewer->hasPermissionTo('siat-cuis.request'));
        $this->assertFalse($cashier->hasPermissionTo('siat-cuis.view'));
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
     * @return array{SinApiToken, SinAuthorization, SinPointOfSale}
     */
    private function siatConfiguration(User $user): array
    {
        $branch = SinBranch::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => 7,
            'name' => 'Sucursal Centro',
        ]);
        $pointOfSale = SinPointOfSale::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 3,
            'name' => 'Caja 3',
            'is_active' => true,
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

        return [$apiToken, $authorization, $pointOfSale];
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
