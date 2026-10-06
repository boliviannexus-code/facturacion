<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\SiatAlertSeverity;
use App\Enums\SiatAlertStatus;
use App\Enums\SiatAlertType;
use App\Enums\SiatScheduledProcess;
use App\Enums\SignificantEventStatus;
use App\Jobs\DispatchSiatAlertNotificationJob;
use App\Jobs\RunSiatScheduledProcessJob;
use App\Models\Company;
use App\Models\SinMonitoringAlert;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\Monitoring\SiatAlertDefinition;
use App\Services\Siat\Monitoring\SiatAlertManager;
use App\Services\Siat\Monitoring\SiatAlertMonitorService;
use App\Services\Siat\Monitoring\SiatAlertRecipientResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SiatMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'siat.monitoring.channels.internal' => true,
            'siat.monitoring.channels.mail' => false,
            'siat.monitoring.channels.panel' => true,
            'siat.monitoring.channels.log' => false,
        ]);
    }

    public function test_same_active_condition_creates_one_alert_and_one_notification_job(): void
    {
        Queue::fake();
        $company = Company::factory()->create();
        SinSignificantEvent::factory()->create(['company_id' => $company->id]);
        $monitor = app(SiatAlertMonitorService::class);

        $monitor->scanOperationalAlerts();
        $monitor->scanOperationalAlerts();

        self::assertSame(1, SinMonitoringAlert::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('alert_type', SiatAlertType::ContingencyStarted)
            ->count());
        $alert = SinMonitoringAlert::query()->withoutGlobalScope('company')->firstOrFail();
        self::assertSame(SiatAlertStatus::Active, $alert->alert_status);
        self::assertNotNull($alert->active_key);
        Queue::assertPushed(DispatchSiatAlertNotificationJob::class, 1);
    }

    public function test_failed_event_under_manual_review_is_not_reported_as_pending_registration(): void
    {
        Queue::fake();
        $company = Company::factory()->create();
        $event = SinSignificantEvent::factory()->create([
            'company_id' => $company->id,
            'event_status' => SignificantEventStatus::Failed,
            'manual_review_required' => true,
            'message' => 'EL EVENTO SIGNIFICATIVO NO CORRESPONDE AL CUFD DEL EVENTO REGISTRADO',
        ]);

        app(SiatAlertMonitorService::class)->scanOperationalAlerts();

        $alert = SinMonitoringAlert::query()->withoutGlobalScope('company')
            ->where('sin_significant_event_id', $event->id)
            ->active()
            ->sole();
        self::assertSame('Evento rechazado — revisión manual requerida', $alert->title);
        self::assertStringContainsString('no admite reintento automático', $alert->message);
        self::assertDatabaseMissing('sin_monitoring_alerts', [
            'sin_significant_event_id' => $event->id,
            'title' => 'Evento pendiente de registro',
            'alert_status' => SiatAlertStatus::Active->value,
        ]);
    }

    public function test_internal_notification_channel_is_idempotent_when_job_runs_twice(): void
    {
        Queue::fake();
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        Role::findOrCreate('tax_responsible');
        $user->assignRole('tax_responsible');
        $alert = app(SiatAlertManager::class)->record(new SiatAlertDefinition(
            companyId: (int) $company->id,
            type: SiatAlertType::InvoicesPendingSend,
            severity: SiatAlertSeverity::Warning,
            scopeKey: 'company:'.$company->id,
            title: 'Facturas pendientes de envío',
            message: 'Existen facturas pendientes de regularización.',
        ));
        $job = new DispatchSiatAlertNotificationJob((int) $alert->id);
        $resolver = app(SiatAlertRecipientResolver::class);

        $job->handle($resolver);
        $job->handle($resolver);

        self::assertSame(1, $user->notifications()->count());
        self::assertNotNull($alert->refresh()->internal_notified_at);

        $notification = $user->notifications()->firstOrFail();
        $this->actingAs($user)->post(route('notifications.read', $notification->id))->assertRedirect();
        self::assertNotNull($notification->refresh()->read_at);
    }

    public function test_resolved_condition_can_alert_again_in_a_new_cycle(): void
    {
        Queue::fake();
        $company = Company::factory()->create();
        $manager = app(SiatAlertManager::class);
        $definition = new SiatAlertDefinition(
            companyId: (int) $company->id,
            type: SiatAlertType::CufdExpired,
            severity: SiatAlertSeverity::Critical,
            scopeKey: 'location:1:1',
            title: 'CUFD vencido',
            message: 'El CUFD activo está vencido.',
        );

        $first = $manager->record($definition);
        $manager->resolveMissing((int) $company->id, [SiatAlertType::CufdExpired], []);
        $second = $manager->record($definition);

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, SinMonitoringAlert::query()->withoutGlobalScope('company')->count());
        self::assertSame(1, SinMonitoringAlert::query()->withoutGlobalScope('company')->active()->count());
        Queue::assertPushed(DispatchSiatAlertNotificationJob::class, 2);
    }

    public function test_scheduler_defines_every_process_without_overlap_and_on_one_server(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_starts_with((string) $event->description, 'siat:monitoring:'));

        self::assertCount(count(SiatScheduledProcess::cases()), $events);
        $events->each(function ($event): void {
            self::assertTrue($event->withoutOverlapping);
            self::assertTrue($event->onOneServer);
        });
    }

    public function test_duplicate_scheduled_process_job_is_suppressed_by_unique_lock(): void
    {
        Queue::fake();

        RunSiatScheduledProcessJob::dispatch(SiatScheduledProcess::VerifyCufd);
        RunSiatScheduledProcessJob::dispatch(SiatScheduledProcess::VerifyCufd);

        Queue::assertPushed(RunSiatScheduledProcessJob::class, 1);
        $job = new RunSiatScheduledProcessJob(SiatScheduledProcess::VerifyCufd);
        self::assertInstanceOf(ShouldBeUnique::class, $job);
        self::assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
    }
}
