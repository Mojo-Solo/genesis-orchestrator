<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MemoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'namespace',
        'value',
        'embedding',
        'metadata',
        'ttl_seconds',
        'access_count',
        'last_accessed_at',
        'expires_at'
    ];

    protected $casts = [
        'value' => 'array',
        'embedding' => 'array',
        'metadata' => 'array',
        'ttl_seconds' => 'integer',
        'access_count' => 'integer',
        'last_accessed_at' => 'datetime',
        'expires_at' => 'datetime'
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Set expiration on creation if TTL is provided
        static::creating(function ($model) {
            if ($model->ttl_seconds) {
                $model->expires_at = now()->addSeconds($model->ttl_seconds);
            }
        });
    }

    /**
     * Scope for non-expired items.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope for expired items.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now());
    }

    /**
     * Scope by namespace.
     */
    public function scopeInNamespace($query, $namespace)
    {
        return $query->where('namespace', $namespace);
    }

    /**
     * Get a memory item by key and namespace.
     */
    public static function retrieve($key, $namespace = 'default')
    {
        $item = self::active()
            ->where('key', $key)
            ->inNamespace($namespace)
            ->first();

        if ($item) {
            // Update access count and timestamp
            $item->increment('access_count');
            $item->update(['last_accessed_at' => now()]);
        }

        return $item;
    }

    /**
     * Store or update a memory item.
     */
    public static function store($key, $value, $namespace = 'default', $ttl = null, $metadata = [])
    {
        return self::updateOrCreate(
            [
                'key' => $key,
                'namespace' => $namespace
            ],
            [
                'value' => $value,
                'metadata' => $metadata,
                'ttl_seconds' => $ttl,
                'expires_at' => $ttl ? now()->addSeconds($ttl) : null,
                'access_count' => 0
            ]
        );
    }

    /**
     * Clean up expired items.
     */
    public static function cleanupExpired()
    {
        return self::expired()->delete();
    }

    /**
     * Get frequently accessed items.
     */
    public static function frequentlyAccessed($limit = 10)
    {
        return self::active()
            ->orderBy('access_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Search by embedding similarity (requires vector extension).
     */
    public function scopeSimilarTo($query, array $embedding, $threshold = 0.8)
    {
        // This would use vector similarity search if database supports it
        // For now, return empty collection
        return $query->whereRaw('1 = 0');
    }
}