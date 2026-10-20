<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->when(CompanyContext::hasSelection(), fn ($query) => $query->whereRaw("(data::jsonb ->> 'company_id') = ?", [(string) CompanyContext::operatingCompanyId()]))->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return redirect()->route('billing.contingencies.index', array_filter([
            'company_id' => $item->data['company_id'] ?? null,
            'branch_id' => $item->data['branch_id'] ?? null,
            'point_of_sale_id' => $item->data['point_of_sale_id'] ?? null,
        ]));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->when(CompanyContext::hasSelection(), fn ($query) => $query->whereRaw("(data::jsonb ->> 'company_id') = ?", [(string) CompanyContext::operatingCompanyId()]))->update(['read_at' => now()]);

        return back()->with('success', 'Notificaciones marcadas como leídas.');
    }
}
