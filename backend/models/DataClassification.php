<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DataClassification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'data_type',
        'classification',
        'sensitivity_level',
        'pii_categories',
        'special_categories',
        'table_name',
        'column_name',
        'data_source',
        'retention_days',
        'requires_encryption',
        'requires_anonymization',
        'cross_border_restricted',
        'metadata'
    ];

    protected $casts = [
        'pii_categories' => 'array',
        'special_categories' => 'array',
        'requires_encryption' => 'boolean',
        'requires_anonymization' => 'boolean',
        'cross_border_restricted' => 'boolean',
        'metadata' => 'array'
    ];

    // Classification levels
    const CLASSIFICATION_PUBLIC = 'public';
    const CLASSIFICATION_INTERNAL = 'internal';
    const CLASSIFICATION_CONFIDENTIAL = 'confidential';
    const CLASSIFICATION_RESTRICTED = 'restricted';

    // Sensitivity levels
    const SENSITIVITY_LOW = 'low';
    const SENSITIVITY_MEDIUM = 'medium';
    const SENSITIVITY_HIGH = 'high';
    const SENSITIVITY_CRITICAL = 'critical';

    // Data types
    const TYPE_USER_DATA = 'user_data';
    const TYPE_SYSTEM_DATA = 'system_data';
    const TYPE_BUSINESS_DATA = 'business_data';
    const TYPE_METADATA = 'metadata';

    // PII Categories
    const PII_NAME = 'name';
    const PII_EMAIL = 'email';
    const PII_PHONE = 'phone';
    const PII_SSN = 'ssn';
    const PII_CREDIT_CARD = 'credit_card';
    const PII_IP_ADDRESS = 'ip_address';
    const PII_LOCATION = 'location';
    const PII_BIOMETRIC = 'biometric';

    // Special categories (GDPR Article 9)
    const SPECIAL_HEALTH = 'health';
    const SPECIAL_BIOMETRIC = 'biometric';
    const SPECIAL_GENETIC = 'genetic';
    const SPECIAL_POLITICAL = 'political';
    const SPECIAL_RELIGIOUS = 'religious';
    const SPECIAL_SEXUAL = 'sexual';
    const SPECIAL_CRIMINAL = 'criminal';

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scopes
     */
    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByClassification($query, $classification)
    {
        return $query->where('classification', $classification);
    }

    public function scopeBySensitivity($query, $sensitivity)
    {
        return $query->where('sensitivity_level', $sensitivity);
    }

    public function scopeContainsPii($query)
    {
        return $query->whereNotNull('pii_categories');
    }

    public function scopeRequiresEncryption($query)
    {
        return $query->where('requires_encryption', true);
    }

    public function scopeSpecialCategory($query)
    {
        return $query->whereNotNull('special_categories');
    }

    /**
     * Check if data classification requires special handling
     */
    public function requiresSpecialHandling(): bool
    {
        return $this->classification === self::CLASSIFICATION_RESTRICTED ||
               $this->sensitivity_level === self::SENSITIVITY_CRITICAL ||
               !empty($this->special_categories) ||
               $this->requires_encryption;
    }

    /**
     * Get retention period in days
     */
    public function getRetentionPeriod(): int
    {
        if ($this->retention_days) {
            return $this->retention_days;
        }

        // Default retention based on classification
        return match($this->classification) {
            self::CLASSIFICATION_PUBLIC => 2555, // 7 years
            self::CLASSIFICATION_INTERNAL => 1095, // 3 years
            self::CLASSIFICATION_CONFIDENTIAL => 730, // 2 years
            self::CLASSIFICATION_RESTRICTED => 365, // 1 year
            default => 365
        };
    }

    /**
     * Check if data should be anonymized instead of deleted
     */
    public function shouldAnonymize(): bool
    {
        return $this->requires_anonymization || 
               in_array($this->data_type, [self::TYPE_BUSINESS_DATA, self::TYPE_METADATA]);
    }

    /**
     * Get risk score based on classification and PII content
     */
    public function getRiskScore(): int
    {
        $score = 0;

        // Base score by classification
        $score += match($this->classification) {
            self::CLASSIFICATION_PUBLIC => 1,
            self::CLASSIFICATION_INTERNAL => 3,
            self::CLASSIFICATION_CONFIDENTIAL => 6,
            self::CLASSIFICATION_RESTRICTED => 9,
            default => 1
        };

        // Additional score for sensitivity
        $score += match($this->sensitivity_level) {
            self::SENSITIVITY_LOW => 1,
            self::SENSITIVITY_MEDIUM => 3,
            self::SENSITIVITY_HIGH => 6,
            self::SENSITIVITY_CRITICAL => 9,
            default => 1
        };

        // Extra points for PII categories
        if (!empty($this->pii_categories)) {
            $score += count($this->pii_categories) * 2;
        }

        // Extra points for special categories
        if (!empty($this->special_categories)) {
            $score += count($this->special_categories) * 5;
        }

        return min($score, 100); // Cap at 100
    }

    /**
     * Classify data automatically based on content analysis
     */
    public static function classifyData(string $content, array $context = []): array
    {
        $classification = [
            'data_type' => self::TYPE_USER_DATA,
            'classification' => self::CLASSIFICATION_INTERNAL,
            'sensitivity_level' => self::SENSITIVITY_MEDIUM,
            'pii_categories' => [],
            'special_categories' => [],
            'requires_encryption' => false,
            'requires_anonymization' => false
        ];

        // Detect PII categories
        $piiPatterns = [
            self::PII_EMAIL => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            self::PII_PHONE => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
            self::PII_SSN => '/\b\d{3}-\d{2}-\d{4}\b/',
            self::PII_CREDIT_CARD => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
            self::PII_IP_ADDRESS => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'
        ];

        foreach ($piiPatterns as $category => $pattern) {
            if (preg_match($pattern, $content)) {
                $classification['pii_categories'][] = $category;
            }
        }

        // Detect special categories
        $specialPatterns = [
            self::SPECIAL_HEALTH => '/\b(health|medical|diagnosis|treatment|medication|disease|illness)\b/i',
            self::SPECIAL_BIOMETRIC => '/\b(fingerprint|facial|retinal|biometric|dna)\b/i',
            self::SPECIAL_POLITICAL => '/\b(political|party|vote|election|campaign)\b/i',
            self::SPECIAL_RELIGIOUS => '/\b(religion|religious|faith|belief|worship)\b/i',
        ];

        foreach ($specialPatterns as $category => $pattern) {
            if (preg_match($pattern, $content)) {
                $classification['special_categories'][] = $category;
            }
        }

        // Adjust classification based on findings
        if (!empty($classification['special_categories'])) {
            $classification['classification'] = self::CLASSIFICATION_RESTRICTED;
            $classification['sensitivity_level'] = self::SENSITIVITY_CRITICAL;
            $classification['requires_encryption'] = true;
        } elseif (count($classification['pii_categories']) >= 3) {
            $classification['classification'] = self::CLASSIFICATION_CONFIDENTIAL;
            $classification['sensitivity_level'] = self::SENSITIVITY_HIGH;
            $classification['requires_encryption'] = true;
        } elseif (!empty($classification['pii_categories'])) {
            $classification['classification'] = self::CLASSIFICATION_CONFIDENTIAL;
            $classification['sensitivity_level'] = self::SENSITIVITY_MEDIUM;
        }

        return $classification;
    }

    /**
     * Get all available classification options
     */
    public static function getClassificationOptions(): array
    {
        return [
            self::CLASSIFICATION_PUBLIC => 'Public - No restrictions',
            self::CLASSIFICATION_INTERNAL => 'Internal - Organization only',
            self::CLASSIFICATION_CONFIDENTIAL => 'Confidential - Restricted access',
            self::CLASSIFICATION_RESTRICTED => 'Restricted - Highly sensitive'
        ];
    }

    /**
     * Get all available sensitivity levels
     */
    public static function getSensitivityOptions(): array
    {
        return [
            self::SENSITIVITY_LOW => 'Low - Minimal impact if disclosed',
            self::SENSITIVITY_MEDIUM => 'Medium - Limited impact if disclosed',
            self::SENSITIVITY_HIGH => 'High - Significant impact if disclosed',
            self::SENSITIVITY_CRITICAL => 'Critical - Severe impact if disclosed'
        ];
    }
}