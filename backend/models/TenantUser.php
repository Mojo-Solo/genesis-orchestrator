<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Carbon\Carbon;

class TenantUser extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'email',
        'name',
        'role',
        'permissions',
        'status',
        'invited_at',
        'accepted_at',
        'invited_by',
        'api_calls_today',
        'tokens_used_today',
        'last_active_at',
        'last_ip_address',
        'mfa_enabled',
        'password_changed_at',
        'failed_login_attempts',
        'locked_until',
        'preferences',
        'metadata'
    ];

    protected $casts = [
        'permissions' => 'array',
        'preferences' => 'array',
        'metadata' => 'array',
        'mfa_enabled' => 'boolean',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'last_active_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'locked_until' => 'datetime'
    ];

    protected $dates = [
        'invited_at',
        'accepted_at',
        'last_active_at',
        'password_changed_at',
        'locked_until',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    // Role hierarchy constants
    const ROLE_OWNER = 'owner';
    const ROLE_ADMIN = 'admin';
    const ROLE_MEMBER = 'member';
    const ROLE_VIEWER = 'viewer';

    const ROLE_HIERARCHY = [
        self::ROLE_OWNER => 4,
        self::ROLE_ADMIN => 3,
        self::ROLE_MEMBER => 2,
        self::ROLE_VIEWER => 1
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_INVITED = 'invited';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_DELETED = 'deleted';

    // Permission constants
    const PERMISSION_MANAGE_USERS = 'manage_users';
    const PERMISSION_MANAGE_BILLING = 'manage_billing';
    const PERMISSION_VIEW_ANALYTICS = 'view_analytics';
    const PERMISSION_MANAGE_ORCHESTRATIONS = 'manage_orchestrations';
    const PERMISSION_MANAGE_AGENTS = 'manage_agents';
    const PERMISSION_MANAGE_SECURITY = 'manage_security';
    const PERMISSION_EXPORT_DATA = 'export_data';
    const PERMISSION_MANAGE_INTEGRATIONS = 'manage_integrations';

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePendingInvitation($query)
    {
        return $query->where('status', self::STATUS_INVITED);
    }

    public function scopeRecentlyActive($query, $days = 30)
    {
        return $query->where('last_active_at', '>=', Carbon::now()->subDays($days));
    }

    public function scopeLocked($query)
    {
        return $query->where('locked_until', '>', Carbon::now());
    }

    // Status Methods
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInvited(): bool
    {
        return $this->status === self::STATUS_INVITED;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function canLogin(): bool
    {
        return $this->isActive() && !$this->isLocked();
    }

    // Role Management
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isMember(): bool
    {
        return $this->role === self::ROLE_MEMBER;
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    public function hasRoleOrHigher(string $role): bool
    {
        $userRoleLevel = self::ROLE_HIERARCHY[$this->role] ?? 0;
        $checkRoleLevel = self::ROLE_HIERARCHY[$role] ?? 0;
        
        return $userRoleLevel >= $checkRoleLevel;
    }

    public function canManageUser(TenantUser $targetUser): bool
    {
        // Owners can manage everyone except other owners
        if ($this->isOwner()) {
            return !$targetUser->isOwner() || $this->id === $targetUser->id;
        }

        // Admins can manage members and viewers
        if ($this->isAdmin()) {
            return $targetUser->isMember() || $targetUser->isViewer();
        }

        // Members and viewers can only manage themselves
        return $this->id === $targetUser->id;
    }

    // Permission Management
    public function hasPermission(string $permission): bool
    {
        // Owners have all permissions
        if ($this->isOwner()) {
            return true;
        }

        // Check role-based permissions
        $rolePermissions = $this->getRolePermissions();
        if (in_array($permission, $rolePermissions)) {
            return true;
        }

        // Check custom permissions
        $customPermissions = $this->permissions ?? [];
        return in_array($permission, $customPermissions);
    }

    public function grantPermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->permissions = $permissions;
            $this->save();
        }
    }

    public function revokePermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        $this->permissions = array_values(array_diff($permissions, [$permission]));
        $this->save();
    }

    public function getRolePermissions(): array
    {
        $rolePermissions = [
            self::ROLE_OWNER => [
                self::PERMISSION_MANAGE_USERS,
                self::PERMISSION_MANAGE_BILLING,
                self::PERMISSION_VIEW_ANALYTICS,
                self::PERMISSION_MANAGE_ORCHESTRATIONS,
                self::PERMISSION_MANAGE_AGENTS,
                self::PERMISSION_MANAGE_SECURITY,
                self::PERMISSION_EXPORT_DATA,
                self::PERMISSION_MANAGE_INTEGRATIONS
            ],
            self::ROLE_ADMIN => [
                self::PERMISSION_MANAGE_USERS,
                self::PERMISSION_VIEW_ANALYTICS,
                self::PERMISSION_MANAGE_ORCHESTRATIONS,
                self::PERMISSION_MANAGE_AGENTS,
                self::PERMISSION_EXPORT_DATA
            ],
            self::ROLE_MEMBER => [
                self::PERMISSION_VIEW_ANALYTICS,
                self::PERMISSION_MANAGE_ORCHESTRATIONS,
                self::PERMISSION_MANAGE_AGENTS
            ],
            self::ROLE_VIEWER => [
                self::PERMISSION_VIEW_ANALYTICS
            ]
        ];

        return $rolePermissions[$this->role] ?? [];
    }

    public function getAllPermissions(): array
    {
        $rolePermissions = $this->getRolePermissions();
        $customPermissions = $this->permissions ?? [];
        
        return array_unique(array_merge($rolePermissions, $customPermissions));
    }

    // Security Methods
    public function recordFailedLogin(): void
    {
        $this->increment('failed_login_attempts');
        
        // Lock account after 5 failed attempts for 30 minutes
        if ($this->failed_login_attempts >= 5) {
            $this->locked_until = Carbon::now()->addMinutes(30);
            $this->save();
        }
    }

    public function recordSuccessfulLogin(string $ipAddress = null): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_active_at' => Carbon::now(),
            'last_ip_address' => $ipAddress
        ]);
    }

    public function unlock(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null
        ]);
    }

    // Usage Tracking
    public function incrementApiCalls(int $count = 1): void
    {
        $this->increment('api_calls_today', $count);
    }

    public function incrementTokensUsed(int $count): void
    {
        $this->increment('tokens_used_today', $count);
    }

    public function resetDailyUsage(): void
    {
        $this->update([
            'api_calls_today' => 0,
            'tokens_used_today' => 0
        ]);
    }

    // Activity Tracking
    public function updateActivity(string $ipAddress = null): void
    {
        $this->update([
            'last_active_at' => Carbon::now(),
            'last_ip_address' => $ipAddress
        ]);
    }

    public function isRecentlyActive(int $minutes = 15): bool
    {
        return $this->last_active_at && 
               $this->last_active_at->isAfter(Carbon::now()->subMinutes($minutes));
    }

    // Invitation Management
    public function sendInvitation(string $invitedBy): void
    {
        $this->update([
            'status' => self::STATUS_INVITED,
            'invited_at' => Carbon::now(),
            'invited_by' => $invitedBy
        ]);

        // Here you would typically send an email invitation
        // This is a placeholder for the actual invitation logic
    }

    public function acceptInvitation(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'accepted_at' => Carbon::now()
        ]);
    }

    public function isInvitationExpired(int $expirationDays = 7): bool
    {
        return $this->invited_at && 
               $this->invited_at->isBefore(Carbon::now()->subDays($expirationDays));
    }

    // Preferences Management
    public function getPreference(string $key, $default = null)
    {
        $preferences = $this->preferences ?? [];
        return $preferences[$key] ?? $default;
    }

    public function setPreference(string $key, $value): void
    {
        $preferences = $this->preferences ?? [];
        $preferences[$key] = $value;
        $this->preferences = $preferences;
        $this->save();
    }

    // Utility Methods
    public function getDisplayName(): string
    {
        return $this->name ?: $this->email;
    }

    public function getRoleBadgeColor(): string
    {
        $colors = [
            self::ROLE_OWNER => 'purple',
            self::ROLE_ADMIN => 'blue',
            self::ROLE_MEMBER => 'green',
            self::ROLE_VIEWER => 'gray'
        ];

        return $colors[$this->role] ?? 'gray';
    }

    public function getStatusBadgeColor(): string
    {
        $colors = [
            self::STATUS_ACTIVE => 'green',
            self::STATUS_INVITED => 'yellow',
            self::STATUS_SUSPENDED => 'red',
            self::STATUS_DELETED => 'gray'
        ];

        return $colors[$this->status] ?? 'gray';
    }

    // Model Events
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenantUser) {
            // Set default permissions based on role
            if (empty($tenantUser->permissions)) {
                $tenantUser->permissions = [];
            }
        });

        static::created(function ($tenantUser) {
            // Increment tenant user count
            $tenantUser->tenant->incrementUsage('users');
        });

        static::deleted(function ($tenantUser) {
            // Decrement tenant user count
            $tenantUser->tenant->decrementUsage('users');
        });
    }
}