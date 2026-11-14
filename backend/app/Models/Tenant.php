<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'name', 'slug', 'database', 'domain', 'aktiv', 'settings',
        'plan', 'trial_ends_at', 'subscription_ends_at', 'onboarding_completed',
        'company_email', 'company_phone', 'company_address',
        'company_city', 'company_zip', 'company_country',
        'logo_path', 'signature_path', 'stamp_path',
        'max_workers', 'max_customers', 'max_users',
        // German legal company information
        'rechtsform', 'geschaeftsfuehrer',
        'handelsregister_nr', 'registergericht',
        'ust_id', 'steuernummer',
        'bank_name', 'iban', 'bic', 'website',
    ];

    protected $casts = [
        'aktiv' => 'boolean',
        'settings' => 'array',
        'onboarding_completed' => 'boolean',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
    ];

    public function centralUsers()
    {
        return $this->hasMany(CentralUser::class);
    }

    public function isTrialActive(): bool
    {
        return $this->plan === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isSubscriptionActive(): bool
    {
        if ($this->isTrialActive()) {
            return true;
        }

        return $this->plan !== 'trial'
            && $this->subscription_ends_at
            && $this->subscription_ends_at->isFuture();
    }

    public function trialDaysRemaining(): int
    {
        if (!$this->trial_ends_at || $this->plan !== 'trial') {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->trial_ends_at, false));
    }

    public function getPlanConfig(): array
    {
        return config("plans.plans.{$this->plan}", config('plans.plans.trial'));
    }

    public function hasFeature(string $feature): bool
    {
        $plan = $this->getPlanConfig();
        return in_array($feature, $plan['features'] ?? []);
    }

    public function isWithinLimit(string $resource, int $currentCount): bool
    {
        $plan = $this->getPlanConfig();
        $limit = $plan["max_{$resource}"] ?? 0;

        if ($limit === -1) {
            return true; // unlimited
        }

        return $currentCount < $limit;
    }
}
