<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\CentralUser;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class TenantController extends Controller
{
    public function index()
    {
        return response()->json(Tenant::where('aktiv', true)->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:200',
            'admin_email' => 'required|email|unique:central_users,email',
            'admin_password' => 'required|string|min:6',
            'admin_full_name' => 'required|string|max:100',
        ]);

        $slug = Str::slug($request->name);
        $database = 'hms_tenant_' . $slug;

        $tenant = Tenant::create([
            'name' => $request->name,
            'slug' => $slug,
            'database' => $database,
            'aktiv' => true,
        ]);

        TenantService::createTenantDatabase($tenant);

        TenantService::seedTenantAdmin(
            $tenant,
            'admin',
            $request->admin_password,
            $request->admin_full_name
        );

        $centralUser = CentralUser::create([
            'tenant_id' => $tenant->id,
            'email' => $request->admin_email,
            'password' => Hash::make($request->admin_password),
            'full_name' => $request->admin_full_name,
            'role' => 'tenant_admin',
            'aktiv' => true,
        ]);

        return response()->json([
            'success' => true,
            'tenant' => $tenant,
            'user' => $centralUser->only(['id', 'email', 'full_name', 'role']),
        ], 201);
    }

    public function show(Tenant $tenant)
    {
        return response()->json($tenant);
    }

    public function destroy(Tenant $tenant)
    {
        TenantService::deleteTenantDatabase($tenant);
        $tenant->centralUsers()->delete();
        $tenant->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Manage a tenant's subscription (super_admin only).
     * PUT /api/tenants/{tenant}/subscription
     *
     * Body (all optional, but at least one required):
     *  - plan: one of config('plans.plans') keys -> switches the plan and
     *    applies its resource limits.
     *  - extend_days: int -> pushes subscription_ends_at this many days into
     *    the future (counted from the later of now / current end date), so
     *    renewing an already-active sub stacks instead of shrinking it.
     *  - lifetime: bool -> never expires (used for internal/system tenants).
     */
    public function updateSubscription(Request $request, Tenant $tenant)
    {
        $plans = array_keys(config('plans.plans', []));

        $data = $request->validate([
            'plan'        => 'sometimes|string|in:' . implode(',', $plans),
            'extend_days' => 'sometimes|integer|min:1|max:3650',
            'lifetime'    => 'sometimes|boolean',
        ]);

        if (empty($data)) {
            return response()->json([
                'error' => 'Nichts zu aendern: plan, extend_days oder lifetime angeben.',
            ], 422);
        }

        $attrs = [];

        // Plan change -> apply the plan's limits and leave the trial behind.
        if (!empty($data['plan'])) {
            $planConfig = config("plans.plans.{$data['plan']}");
            $attrs['plan']         = $data['plan'];
            $attrs['trial_ends_at'] = null;
            $attrs['max_workers']   = $planConfig['max_workers'];
            $attrs['max_customers'] = $planConfig['max_customers'];
            $attrs['max_users']     = $planConfig['max_users'];
        }

        // Lifetime -> far-future end date (~75 years). Wins over extend_days.
        if (!empty($data['lifetime'])) {
            $attrs['subscription_ends_at'] = \Illuminate\Support\Carbon::parse('2037-12-31 23:59:59');
            $attrs['trial_ends_at']        = null;
        } elseif (!empty($data['extend_days'])) {
            $base = $tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture()
                ? $tenant->subscription_ends_at
                : now();
            $attrs['subscription_ends_at'] = $base->copy()->addDays($data['extend_days']);
            $attrs['trial_ends_at']        = null;
        }

        $tenant->update($attrs);
        $tenant->refresh();

        return response()->json([
            'success' => true,
            'tenant'  => [
                'id'                   => $tenant->id,
                'name'                 => $tenant->name,
                'plan'                 => $tenant->plan,
                'trial_ends_at'        => $tenant->trial_ends_at?->toISOString(),
                'subscription_ends_at' => $tenant->subscription_ends_at?->toISOString(),
                'is_active'            => $tenant->isSubscriptionActive(),
            ],
        ]);
    }
}
