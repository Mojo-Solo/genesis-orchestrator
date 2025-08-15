<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class PrivacySetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'setting_key',
        'setting_value',
        'setting_description',
        'user_configurable',
        'requires_consent',
        'allowed_values',
        'last_updated_at',
        'updated_by',
        'update_reason'
    ];

    protected $casts = [
        'user_configurable' => 'boolean',
        'requires_consent' => 'boolean',
        'allowed_values' => 'array',
        'last_updated_at' => 'datetime'
    ];

    // Common privacy settings
    const SETTING_DATA_PROCESSING = 'data_processing';
    const SETTING_MARKETING_EMAILS = 'marketing_emails';
    const SETTING_ANALYTICS_TRACKING = 'analytics_tracking';
    const SETTING_COOKIES_FUNCTIONAL = 'cookies_functional';
    const SETTING_COOKIES_ANALYTICS = 'cookies_analytics';
    const SETTING_COOKIES_MARKETING = 'cookies_marketing';
    const SETTING_DATA_SHARING = 'data_sharing';
    const SETTING_PROFILE_VISIBILITY = 'profile_visibility';
    const SETTING_LOCATION_TRACKING = 'location_tracking';
    const SETTING_BEHAVIORAL_ANALYSIS = 'behavioral_analysis';

    // Setting values
    const VALUE_ENABLED = 'enabled';
    const VALUE_DISABLED = 'disabled';
    const VALUE_LIMITED = 'limited';
    const VALUE_OPT_IN = 'opt_in';
    const VALUE_OPT_OUT = 'opt_out';

    // Update reasons
    const REASON_USER_REQUEST = 'user_request';
    const REASON_POLICY_CHANGE = 'policy_change';
    const REASON_CONSENT_WITHDRAWAL = 'consent_withdrawal';
    const REASON_LEGAL_REQUIREMENT = 'legal_requirement';
    const REASON_SYSTEM_DEFAULT = 'system_default';

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'updated_by');
    }

    /**
     * Scopes
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeGlobal($query)
    {
        return $query->whereNull('user_id');
    }

    public function scopeUserConfigurable($query)
    {
        return $query->where('user_configurable', true);
    }

    public function scopeRequiresConsent($query)
    {
        return $query->where('requires_consent', true);
    }

    public function scopeBySetting($query, $settingKey)
    {
        return $query->where('setting_key', $settingKey);
    }

    /**
     * Boot method to set defaults
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($setting) {
            $setting->last_updated_at = Carbon::now();
        });

        static::updating(function ($setting) {
            $setting->last_updated_at = Carbon::now();
        });
    }

    /**
     * Update setting value with audit trail
     */
    public function updateValue(string $newValue, string $reason, $updatedBy = null): self
    {
        $oldValue = $this->setting_value;

        $this->update([
            'setting_value' => $newValue,
            'update_reason' => $reason,
            'updated_by' => $updatedBy,
            'last_updated_at' => Carbon::now()
        ]);

        // Log the change
        ComplianceAuditLog::logEvent(
            'privacy_setting_changed',
            'privacy',
            'info',
            $this->user_id,
            'tenant_user',
            "Privacy setting '{$this->setting_key}' changed from '{$oldValue}' to '{$newValue}'",
            [
                'setting_id' => $this->id,
                'setting_key' => $this->setting_key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'reason' => $reason,
                'updated_by' => $updatedBy
            ],
            $this->tenant_id
        );

        return $this;
    }

    /**
     * Check if setting value is enabled
     */
    public function isEnabled(): bool
    {
        return in_array($this->setting_value, [self::VALUE_ENABLED, self::VALUE_OPT_IN]);
    }

    /**
     * Check if setting value is disabled
     */
    public function isDisabled(): bool
    {
        return in_array($this->setting_value, [self::VALUE_DISABLED, self::VALUE_OPT_OUT]);
    }

    /**
     * Check if setting requires user consent before enabling
     */
    public function needsConsent(): bool
    {
        return $this->requires_consent && !$this->hasValidConsent();
    }

    /**
     * Check if there's valid consent for this setting
     */
    public function hasValidConsent(): bool
    {
        if (!$this->requires_consent || !$this->user_id) {
            return true;
        }

        $consent = ConsentRecord::byDataSubject($this->user_id)
            ->byTenant($this->tenant_id)
            ->byConsentType($this->setting_key)
            ->active()
            ->first();

        return $consent && $consent->isValid();
    }

    /**
     * Get or create setting for user
     */
    public static function getOrCreateForUser(string $tenantId, string $userId, string $settingKey, string $defaultValue = null): self
    {
        $setting = static::byTenant($tenantId)
            ->byUser($userId)
            ->bySetting($settingKey)
            ->first();

        if (!$setting) {
            // Look for global default
            $globalSetting = static::byTenant($tenantId)
                ->global()
                ->bySetting($settingKey)
                ->first();

            $setting = static::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'setting_key' => $settingKey,
                'setting_value' => $defaultValue ?? ($globalSetting?->setting_value ?? self::VALUE_DISABLED),
                'setting_description' => $globalSetting?->setting_description ?? "User setting for {$settingKey}",
                'user_configurable' => $globalSetting?->user_configurable ?? true,
                'requires_consent' => $globalSetting?->requires_consent ?? false,
                'allowed_values' => $globalSetting?->allowed_values ?? [self::VALUE_ENABLED, self::VALUE_DISABLED],
                'update_reason' => self::REASON_SYSTEM_DEFAULT
            ]);

            ComplianceAuditLog::logEvent(
                'privacy_setting_created',
                'privacy',
                'info',
                $userId,
                'tenant_user',
                "Privacy setting '{$settingKey}' created for user",
                [
                    'setting_id' => $setting->id,
                    'setting_key' => $settingKey,
                    'initial_value' => $setting->setting_value
                ],
                $tenantId
            );
        }

        return $setting;
    }

    /**
     * Apply global privacy settings to all users in tenant
     */
    public static function applyGlobalSettings(string $tenantId): array
    {
        $globalSettings = static::byTenant($tenantId)->global()->get();
        $users = TenantUser::where('tenant_id', $tenantId)->get();
        $applied = [];

        foreach ($users as $user) {
            foreach ($globalSettings as $globalSetting) {
                $userSetting = static::getOrCreateForUser(
                    $tenantId,
                    $user->id,
                    $globalSetting->setting_key,
                    $globalSetting->setting_value
                );
                $applied[] = $userSetting;
            }
        }

        return $applied;
    }

    /**
     * Get privacy dashboard for user
     */
    public static function getPrivacyDashboard(string $tenantId, string $userId): array
    {
        $settings = static::byTenant($tenantId)
            ->byUser($userId)
            ->userConfigurable()
            ->get()
            ->keyBy('setting_key');

        $consentRequiredSettings = $settings->filter(fn($s) => $s->requires_consent);
        $enabledSettings = $settings->filter(fn($s) => $s->isEnabled());

        return [
            'user_id' => $userId,
            'total_settings' => $settings->count(),
            'enabled_settings' => $enabledSettings->count(),
            'consent_required' => $consentRequiredSettings->count(),
            'settings' => $settings->map(function ($setting) {
                return [
                    'key' => $setting->setting_key,
                    'value' => $setting->setting_value,
                    'description' => $setting->setting_description,
                    'enabled' => $setting->isEnabled(),
                    'user_configurable' => $setting->user_configurable,
                    'requires_consent' => $setting->requires_consent,
                    'has_consent' => $setting->hasValidConsent(),
                    'allowed_values' => $setting->allowed_values,
                    'last_updated' => $setting->last_updated_at
                ];
            })->toArray()
        ];
    }

    /**
     * Get default privacy settings templates
     */
    public static function getDefaultSettings(): array
    {
        return [
            self::SETTING_DATA_PROCESSING => [
                'description' => 'Allow processing of personal data for service provision',
                'default_value' => self::VALUE_ENABLED,
                'user_configurable' => false,
                'requires_consent' => true,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED]
            ],
            self::SETTING_MARKETING_EMAILS => [
                'description' => 'Receive marketing emails and promotional content',
                'default_value' => self::VALUE_DISABLED,
                'user_configurable' => true,
                'requires_consent' => true,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED]
            ],
            self::SETTING_ANALYTICS_TRACKING => [
                'description' => 'Allow analytics tracking for service improvement',
                'default_value' => self::VALUE_ENABLED,
                'user_configurable' => true,
                'requires_consent' => true,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED, self::VALUE_LIMITED]
            ],
            self::SETTING_COOKIES_FUNCTIONAL => [
                'description' => 'Essential cookies for site functionality',
                'default_value' => self::VALUE_ENABLED,
                'user_configurable' => false,
                'requires_consent' => false,
                'allowed_values' => [self::VALUE_ENABLED]
            ],
            self::SETTING_COOKIES_ANALYTICS => [
                'description' => 'Analytics cookies for usage statistics',
                'default_value' => self::VALUE_DISABLED,
                'user_configurable' => true,
                'requires_consent' => true,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED]
            ],
            self::SETTING_COOKIES_MARKETING => [
                'description' => 'Marketing cookies for personalized content',
                'default_value' => self::VALUE_DISABLED,
                'user_configurable' => true,
                'requires_consent' => true,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED]
            ],
            self::SETTING_DATA_SHARING => [
                'description' => 'Share data with trusted partners',
                'default_value' => self::VALUE_DISABLED,
                'user_configurable' => true,
                'requires_consent' => true,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED, self::VALUE_LIMITED]
            ],
            self::SETTING_PROFILE_VISIBILITY => [
                'description' => 'Profile visibility to other users',
                'default_value' => self::VALUE_LIMITED,
                'user_configurable' => true,
                'requires_consent' => false,
                'allowed_values' => [self::VALUE_ENABLED, self::VALUE_DISABLED, self::VALUE_LIMITED]
            ]
        ];
    }
}