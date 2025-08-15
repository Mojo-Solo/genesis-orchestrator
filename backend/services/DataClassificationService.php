<?php

namespace App\Services;

use App\Models\DataClassification;
use App\Models\Tenant;
use App\Models\ComplianceAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DataClassificationService
{
    private const CONFIDENCE_THRESHOLD = 0.7;
    private const BATCH_SIZE = 100;

    // Enhanced PII detection patterns with confidence scoring
    private array $piiPatterns = [
        // High confidence patterns
        DataClassification::PII_EMAIL => [
            'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'confidence' => 0.95,
            'weight' => 10
        ],
        DataClassification::PII_SSN => [
            'pattern' => '/\b\d{3}-\d{2}-\d{4}\b/',
            'confidence' => 0.98,
            'weight' => 15
        ],
        DataClassification::PII_CREDIT_CARD => [
            'pattern' => '/\b(?:\d{4}[-\s]?){3}\d{4}\b/',
            'confidence' => 0.90,
            'weight' => 15
        ],
        DataClassification::PII_PHONE => [
            'pattern' => '/\b(?:\+?1[-.\s]?)?\(?[2-9][0-8][0-9]\)?[-.\s]?[2-9][0-9]{2}[-.\s]?\d{4}\b/',
            'confidence' => 0.85,
            'weight' => 8
        ],
        DataClassification::PII_IP_ADDRESS => [
            'pattern' => '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/',
            'confidence' => 0.80,
            'weight' => 5
        ],
        
        // Medium confidence patterns
        DataClassification::PII_NAME => [
            'pattern' => '/\b(?:mr|mrs|ms|dr|prof)\.?\s+[a-z]{2,}\s+[a-z]{2,}\b/i',
            'confidence' => 0.70,
            'weight' => 6
        ],
        DataClassification::PII_LOCATION => [
            'pattern' => '/\b\d+\s+[a-z\s]+(?:street|st|avenue|ave|road|rd|lane|ln|drive|dr|way|circle|cir|court|ct|place|pl)\b/i',
            'confidence' => 0.65,
            'weight' => 4
        ],
        
        // Context-dependent patterns
        'date_of_birth' => [
            'pattern' => '/\b(?:0[1-9]|1[0-2])\/(?:0[1-9]|[12]\d|3[01])\/(?:19|20)\d{2}\b/',
            'confidence' => 0.75,
            'weight' => 8
        ],
        'bank_account' => [
            'pattern' => '/\b\d{8,17}\b/',
            'confidence' => 0.60,
            'weight' => 7
        ]
    ];

    // Special category detection patterns (GDPR Article 9)
    private array $specialCategoryPatterns = [
        DataClassification::SPECIAL_HEALTH => [
            'patterns' => [
                '/\b(?:health|medical|diagnosis|treatment|medication|disease|illness|hospital|clinic|doctor|patient|therapy|prescription|surgery|symptoms?|condition)\b/i',
                '/\b(?:covid|diabetes|cancer|depression|anxiety|HIV|AIDS|mental|psychiatric|psychological)\b/i'
            ],
            'confidence' => 0.80,
            'weight' => 20
        ],
        DataClassification::SPECIAL_BIOMETRIC => [
            'patterns' => [
                '/\b(?:fingerprint|facial|retinal|iris|biometric|dna|genetic|voiceprint|palm)\b/i',
                '/\b(?:biometry|physiological|biological|unique identifier)\b/i'
            ],
            'confidence' => 0.85,
            'weight' => 25
        ],
        DataClassification::SPECIAL_POLITICAL => [
            'patterns' => [
                '/\b(?:political|party|vote|voting|election|campaign|candidate|republican|democrat|conservative|liberal)\b/i',
                '/\b(?:political opinion|political affiliation|political view)\b/i'
            ],
            'confidence' => 0.75,
            'weight' => 18
        ],
        DataClassification::SPECIAL_RELIGIOUS => [
            'patterns' => [
                '/\b(?:religion|religious|faith|belief|worship|christian|muslim|jewish|hindu|buddhist|atheist|agnostic)\b/i',
                '/\b(?:church|mosque|temple|synagogue|prayer|god|deity|spiritual)\b/i'
            ],
            'confidence' => 0.75,
            'weight' => 18
        ],
        DataClassification::SPECIAL_SEXUAL => [
            'patterns' => [
                '/\b(?:sexual orientation|gay|lesbian|bisexual|transgender|heterosexual|homosexual|lgbtq)\b/i',
                '/\b(?:gender identity|sex life|sexual behavior)\b/i'
            ],
            'confidence' => 0.80,
            'weight' => 20
        ],
        DataClassification::SPECIAL_CRIMINAL => [
            'patterns' => [
                '/\b(?:criminal|conviction|offense|arrest|charge|guilty|sentence|prison|jail|court|legal proceeding)\b/i',
                '/\b(?:criminal record|criminal history|background check)\b/i'
            ],
            'confidence' => 0.75,
            'weight' => 18
        ]
    ];

    // Context keywords that increase confidence
    private array $contextKeywords = [
        'personal' => 0.1,
        'private' => 0.1,
        'confidential' => 0.15,
        'sensitive' => 0.2,
        'restricted' => 0.2,
        'user' => 0.05,
        'customer' => 0.05,
        'profile' => 0.1,
        'account' => 0.05,
        'identity' => 0.15
    ];

    /**
     * Enhanced ML-based data classification
     */
    public function classifyData(string $content, array $context = [], string $tenantId = null): array
    {
        $classification = $this->initializeClassification();
        
        // Step 1: Content-based analysis
        $contentAnalysis = $this->analyzeContent($content);
        
        // Step 2: Context-based analysis
        $contextAnalysis = $this->analyzeContext($context);
        
        // Step 3: Combine analyses with confidence scoring
        $finalClassification = $this->combineAnalyses($contentAnalysis, $contextAnalysis, $context);
        
        // Step 4: Apply tenant-specific rules if available
        if ($tenantId) {
            $finalClassification = $this->applyTenantRules($finalClassification, $tenantId);
        }
        
        // Step 5: Log classification for learning
        $this->logClassification($content, $context, $finalClassification, $tenantId);
        
        return $finalClassification;
    }

    /**
     * Initialize default classification structure
     */
    private function initializeClassification(): array
    {
        return [
            'data_type' => DataClassification::TYPE_USER_DATA,
            'classification' => DataClassification::CLASSIFICATION_INTERNAL,
            'sensitivity_level' => DataClassification::SENSITIVITY_MEDIUM,
            'pii_categories' => [],
            'special_categories' => [],
            'requires_encryption' => false,
            'requires_anonymization' => false,
            'cross_border_restricted' => false,
            'confidence_score' => 0.0,
            'classification_details' => [
                'pii_matches' => [],
                'special_category_matches' => [],
                'context_indicators' => [],
                'confidence_factors' => []
            ]
        ];
    }

    /**
     * Analyze content for PII and special categories
     */
    private function analyzeContent(string $content): array
    {
        $analysis = [
            'pii_matches' => [],
            'special_category_matches' => [],
            'confidence_score' => 0.0
        ];

        // Detect PII patterns
        foreach ($this->piiPatterns as $category => $config) {
            if (preg_match_all($config['pattern'], $content, $matches)) {
                $matchCount = count($matches[0]);
                $analysis['pii_matches'][] = [
                    'category' => $category,
                    'matches' => $matchCount,
                    'confidence' => $config['confidence'],
                    'weight' => $config['weight'],
                    'examples' => array_slice($matches[0], 0, 3) // Store up to 3 examples
                ];
                $analysis['confidence_score'] += $config['confidence'] * $config['weight'];
            }
        }

        // Detect special categories
        foreach ($this->specialCategoryPatterns as $category => $config) {
            $categoryMatches = 0;
            foreach ($config['patterns'] as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    $categoryMatches += count($matches[0]);
                }
            }
            
            if ($categoryMatches > 0) {
                $analysis['special_category_matches'][] = [
                    'category' => $category,
                    'matches' => $categoryMatches,
                    'confidence' => $config['confidence'],
                    'weight' => $config['weight']
                ];
                $analysis['confidence_score'] += $config['confidence'] * $config['weight'];
            }
        }

        // Normalize confidence score
        if ($analysis['confidence_score'] > 0) {
            $maxPossibleScore = array_sum(array_column($this->piiPatterns, 'weight')) + 
                               array_sum(array_column($this->specialCategoryPatterns, 'weight'));
            $analysis['confidence_score'] = min($analysis['confidence_score'] / $maxPossibleScore, 1.0);
        }

        return $analysis;
    }

    /**
     * Analyze context for additional indicators
     */
    private function analyzeContext(array $context): array
    {
        $analysis = [
            'context_indicators' => [],
            'confidence_boost' => 0.0
        ];

        // Analyze table and column names
        if (isset($context['table_name'], $context['column_name'])) {
            $tableName = strtolower($context['table_name']);
            $columnName = strtolower($context['column_name']);
            
            // Check for sensitive table/column indicators
            $sensitiveIndicators = [
                'users' => 0.15,
                'profiles' => 0.15,
                'personal' => 0.2,
                'private' => 0.2,
                'confidential' => 0.25,
                'sensitive' => 0.25,
                'secure' => 0.15,
                'restricted' => 0.25
            ];

            foreach ($sensitiveIndicators as $indicator => $boost) {
                if (str_contains($tableName, $indicator) || str_contains($columnName, $indicator)) {
                    $analysis['context_indicators'][] = "Sensitive {$indicator} indicator in schema";
                    $analysis['confidence_boost'] += $boost;
                }
            }

            // Specific column name patterns
            $columnPatterns = [
                'email' => ['boost' => 0.3, 'pii' => DataClassification::PII_EMAIL],
                'phone' => ['boost' => 0.25, 'pii' => DataClassification::PII_PHONE],
                'ssn' => ['boost' => 0.4, 'pii' => DataClassification::PII_SSN],
                'social_security' => ['boost' => 0.4, 'pii' => DataClassification::PII_SSN],
                'credit_card' => ['boost' => 0.35, 'pii' => DataClassification::PII_CREDIT_CARD],
                'address' => ['boost' => 0.2, 'pii' => DataClassification::PII_LOCATION],
                'location' => ['boost' => 0.2, 'pii' => DataClassification::PII_LOCATION],
                'first_name' => ['boost' => 0.2, 'pii' => DataClassification::PII_NAME],
                'last_name' => ['boost' => 0.2, 'pii' => DataClassification::PII_NAME],
                'full_name' => ['boost' => 0.25, 'pii' => DataClassification::PII_NAME],
                'ip_address' => ['boost' => 0.15, 'pii' => DataClassification::PII_IP_ADDRESS],
                'date_of_birth' => ['boost' => 0.3, 'pii' => 'date_of_birth'],
                'dob' => ['boost' => 0.3, 'pii' => 'date_of_birth']
            ];

            foreach ($columnPatterns as $pattern => $config) {
                if (str_contains($columnName, $pattern)) {
                    $analysis['context_indicators'][] = "Column name suggests {$config['pii']} data";
                    $analysis['confidence_boost'] += $config['boost'];
                }
            }
        }

        // Check data source context
        if (isset($context['data_source'])) {
            $sourceIndicators = [
                'user_input' => 0.2,
                'form_submission' => 0.25,
                'registration' => 0.3,
                'profile_update' => 0.25,
                'payment' => 0.35,
                'external_api' => 0.15
            ];

            $dataSource = strtolower($context['data_source']);
            foreach ($sourceIndicators as $source => $boost) {
                if (str_contains($dataSource, $source)) {
                    $analysis['context_indicators'][] = "Data source suggests personal data: {$source}";
                    $analysis['confidence_boost'] += $boost;
                }
            }
        }

        return $analysis;
    }

    /**
     * Combine content and context analyses
     */
    private function combineAnalyses(array $contentAnalysis, array $contextAnalysis, array $context): array
    {
        $classification = $this->initializeClassification();
        
        // Extract PII categories
        $piiCategories = [];
        foreach ($contentAnalysis['pii_matches'] as $match) {
            if ($match['confidence'] >= self::CONFIDENCE_THRESHOLD) {
                $piiCategories[] = $match['category'];
            }
        }

        // Extract special categories
        $specialCategories = [];
        foreach ($contentAnalysis['special_category_matches'] as $match) {
            if ($match['confidence'] >= self::CONFIDENCE_THRESHOLD) {
                $specialCategories[] = $match['category'];
            }
        }

        // Calculate final confidence score
        $finalConfidence = $contentAnalysis['confidence_score'] + $contextAnalysis['confidence_boost'];
        $finalConfidence = min($finalConfidence, 1.0);

        // Determine classification level
        if (!empty($specialCategories)) {
            $classification['classification'] = DataClassification::CLASSIFICATION_RESTRICTED;
            $classification['sensitivity_level'] = DataClassification::SENSITIVITY_CRITICAL;
            $classification['requires_encryption'] = true;
            $classification['cross_border_restricted'] = true;
        } elseif (count($piiCategories) >= 3 || $finalConfidence >= 0.8) {
            $classification['classification'] = DataClassification::CLASSIFICATION_CONFIDENTIAL;
            $classification['sensitivity_level'] = DataClassification::SENSITIVITY_HIGH;
            $classification['requires_encryption'] = true;
        } elseif (!empty($piiCategories) || $finalConfidence >= 0.5) {
            $classification['classification'] = DataClassification::CLASSIFICATION_CONFIDENTIAL;
            $classification['sensitivity_level'] = DataClassification::SENSITIVITY_MEDIUM;
        } else {
            $classification['classification'] = DataClassification::CLASSIFICATION_INTERNAL;
            $classification['sensitivity_level'] = DataClassification::SENSITIVITY_LOW;
        }

        // Set anonymization requirement
        if ($classification['sensitivity_level'] === DataClassification::SENSITIVITY_CRITICAL ||
            !empty($specialCategories)) {
            $classification['requires_anonymization'] = true;
        }

        // Populate final classification
        $classification['pii_categories'] = $piiCategories;
        $classification['special_categories'] = $specialCategories;
        $classification['confidence_score'] = $finalConfidence;
        $classification['classification_details'] = [
            'pii_matches' => $contentAnalysis['pii_matches'],
            'special_category_matches' => $contentAnalysis['special_category_matches'],
            'context_indicators' => $contextAnalysis['context_indicators'],
            'confidence_factors' => [
                'content_confidence' => $contentAnalysis['confidence_score'],
                'context_boost' => $contextAnalysis['confidence_boost'],
                'final_confidence' => $finalConfidence
            ]
        ];

        return $classification;
    }

    /**
     * Apply tenant-specific classification rules
     */
    private function applyTenantRules(array $classification, string $tenantId): array
    {
        // Check for existing tenant-specific rules
        $tenantRules = $this->getTenantClassificationRules($tenantId);
        
        foreach ($tenantRules as $rule) {
            if ($this->ruleApplies($rule, $classification)) {
                $classification = $this->applyRule($rule, $classification);
            }
        }

        return $classification;
    }

    /**
     * Get tenant-specific classification rules
     */
    private function getTenantClassificationRules(string $tenantId): array
    {
        // This could be stored in a separate table or tenant configuration
        // For now, return default industry-specific rules
        $tenant = Tenant::find($tenantId);
        
        $industryRules = [
            'healthcare' => [
                [
                    'condition' => ['contains_health_keywords' => true],
                    'action' => [
                        'classification' => DataClassification::CLASSIFICATION_RESTRICTED,
                        'requires_encryption' => true,
                        'cross_border_restricted' => true
                    ]
                ]
            ],
            'financial' => [
                [
                    'condition' => ['contains_financial_data' => true],
                    'action' => [
                        'classification' => DataClassification::CLASSIFICATION_CONFIDENTIAL,
                        'requires_encryption' => true,
                        'retention_days' => 2555 // 7 years for financial data
                    ]
                ]
            ],
            'education' => [
                [
                    'condition' => ['contains_educational_records' => true],
                    'action' => [
                        'classification' => DataClassification::CLASSIFICATION_CONFIDENTIAL,
                        'requires_encryption' => true
                    ]
                ]
            ]
        ];

        return $industryRules[$tenant?->industry ?? 'default'] ?? [];
    }

    /**
     * Check if a rule applies to the current classification
     */
    private function ruleApplies(array $rule, array $classification): bool
    {
        $conditions = $rule['condition'] ?? [];
        
        foreach ($conditions as $key => $value) {
            switch ($key) {
                case 'contains_health_keywords':
                    if ($value && !in_array(DataClassification::SPECIAL_HEALTH, $classification['special_categories'])) {
                        return false;
                    }
                    break;
                case 'contains_financial_data':
                    if ($value && !in_array(DataClassification::PII_CREDIT_CARD, $classification['pii_categories']) &&
                        !in_array('bank_account', $classification['pii_categories'])) {
                        return false;
                    }
                    break;
                case 'min_confidence':
                    if ($classification['confidence_score'] < $value) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Apply a rule to modify classification
     */
    private function applyRule(array $rule, array $classification): array
    {
        $actions = $rule['action'] ?? [];
        
        foreach ($actions as $key => $value) {
            if (array_key_exists($key, $classification)) {
                $classification[$key] = $value;
            }
        }

        return $classification;
    }

    /**
     * Log classification for machine learning improvement
     */
    private function logClassification(string $content, array $context, array $classification, ?string $tenantId): void
    {
        try {
            $logData = [
                'content_hash' => hash('sha256', $content),
                'content_length' => strlen($content),
                'context' => $context,
                'classification_result' => $classification,
                'confidence_score' => $classification['confidence_score'],
                'pii_count' => count($classification['pii_categories']),
                'special_category_count' => count($classification['special_categories']),
                'classified_at' => Carbon::now()->toISOString()
            ];

            if ($tenantId) {
                ComplianceAuditLog::logEvent(
                    'data_classification_performed',
                    'privacy',
                    'info',
                    null,
                    null,
                    'Automated data classification performed',
                    $logData,
                    $tenantId,
                    [],
                    null,
                    true
                );
            }

            Log::info('Data classification performed', $logData);
        } catch (\Exception $e) {
            Log::error('Failed to log data classification', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);
        }
    }

    /**
     * Bulk classify table data with enhanced analysis
     */
    public function bulkClassifyTableData(string $tenantId, string $tableName, ?string $columnName = null): array
    {
        $results = [];
        $startTime = microtime(true);

        try {
            // Get table structure for context
            $tableStructure = $this->getTableStructure($tableName);
            
            if ($columnName) {
                // Classify specific column
                $results[] = $this->classifyTableColumn($tenantId, $tableName, $columnName, $tableStructure);
            } else {
                // Classify all text/varchar columns
                $textColumns = $this->getTextColumns($tableName);
                foreach ($textColumns as $column) {
                    $results[] = $this->classifyTableColumn($tenantId, $tableName, $column, $tableStructure);
                }
            }

            $executionTime = microtime(true) - $startTime;

            ComplianceAuditLog::logEvent(
                'bulk_data_classification',
                'privacy',
                'info',
                null,
                null,
                "Bulk data classification completed for {$tableName}",
                [
                    'table_name' => $tableName,
                    'column_name' => $columnName,
                    'classifications_created' => count($results),
                    'execution_time_seconds' => round($executionTime, 2)
                ],
                $tenantId,
                [],
                null,
                true
            );

        } catch (\Exception $e) {
            Log::error('Bulk classification failed', [
                'table' => $tableName,
                'column' => $columnName,
                'tenant' => $tenantId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }

        return $results;
    }

    /**
     * Classify a specific table column
     */
    private function classifyTableColumn(string $tenantId, string $tableName, string $columnName, array $tableStructure): DataClassification
    {
        // Sample data from the column
        $sampleData = $this->sampleColumnData($tableName, $columnName, $tenantId);
        
        // Prepare context
        $context = [
            'table_name' => $tableName,
            'column_name' => $columnName,
            'data_source' => 'database_table',
            'column_type' => $tableStructure[$columnName]['type'] ?? 'unknown',
            'sample_size' => count($sampleData)
        ];

        // Combine sample data for analysis
        $combinedContent = implode(' ', array_filter($sampleData));
        
        // Perform classification
        $classificationResult = $this->classifyData($combinedContent, $context, $tenantId);
        
        // Create or update classification record
        $existing = DataClassification::where('tenant_id', $tenantId)
            ->where('table_name', $tableName)
            ->where('column_name', $columnName)
            ->first();

        if ($existing) {
            $existing->update($classificationResult);
            return $existing;
        } else {
            $classificationResult['tenant_id'] = $tenantId;
            $classificationResult['table_name'] = $tableName;
            $classificationResult['column_name'] = $columnName;
            return DataClassification::create($classificationResult);
        }
    }

    /**
     * Sample data from a table column
     */
    private function sampleColumnData(string $tableName, string $columnName, string $tenantId): array
    {
        return DB::table($tableName)
            ->where('tenant_id', $tenantId)
            ->whereNotNull($columnName)
            ->where($columnName, '!=', '')
            ->limit(self::BATCH_SIZE)
            ->pluck($columnName)
            ->filter()
            ->take(10) // Analyze top 10 samples
            ->toArray();
    }

    /**
     * Get table structure information
     */
    private function getTableStructure(string $tableName): array
    {
        $columns = DB::select("DESCRIBE {$tableName}");
        $structure = [];
        
        foreach ($columns as $column) {
            $structure[$column->Field] = [
                'type' => $column->Type,
                'null' => $column->Null,
                'key' => $column->Key,
                'default' => $column->Default,
                'extra' => $column->Extra
            ];
        }
        
        return $structure;
    }

    /**
     * Get text columns from a table
     */
    private function getTextColumns(string $tableName): array
    {
        $structure = $this->getTableStructure($tableName);
        $textColumns = [];
        
        foreach ($structure as $columnName => $info) {
            $type = strtolower($info['type']);
            if (str_contains($type, 'varchar') || 
                str_contains($type, 'text') || 
                str_contains($type, 'char')) {
                $textColumns[] = $columnName;
            }
        }
        
        return $textColumns;
    }

    /**
     * Generate classification insights and recommendations
     */
    public function getClassificationInsights(string $tenantId): array
    {
        $classifications = DataClassification::byTenant($tenantId)->get();
        
        $insights = [
            'total_classifications' => $classifications->count(),
            'classification_distribution' => $classifications->groupBy('classification')->map->count(),
            'sensitivity_distribution' => $classifications->groupBy('sensitivity_level')->map->count(),
            'encryption_required' => $classifications->where('requires_encryption', true)->count(),
            'high_risk_data' => $classifications->filter(fn($c) => $c->getRiskScore() >= 70)->count(),
            'special_categories' => $classifications->whereNotNull('special_categories')->count(),
            'recommendations' => []
        ];

        // Generate recommendations
        $highRiskCount = $insights['high_risk_data'];
        if ($highRiskCount > 0) {
            $insights['recommendations'][] = [
                'priority' => 'high',
                'category' => 'security',
                'title' => 'Review high-risk data classifications',
                'description' => "You have {$highRiskCount} high-risk data classifications that may require additional security measures."
            ];
        }

        $unencryptedSensitive = $classifications->filter(function($c) {
            return !$c->requires_encryption && 
                   in_array($c->sensitivity_level, [DataClassification::SENSITIVITY_HIGH, DataClassification::SENSITIVITY_CRITICAL]);
        })->count();

        if ($unencryptedSensitive > 0) {
            $insights['recommendations'][] = [
                'priority' => 'medium',
                'category' => 'encryption',
                'title' => 'Consider encryption for sensitive data',
                'description' => "{$unencryptedSensitive} sensitive data classifications may benefit from encryption."
            ];
        }

        return $insights;
    }

    /**
     * Update classification rules based on feedback
     */
    public function updateClassificationFromFeedback(string $classificationId, array $corrections): void
    {
        $classification = DataClassification::findOrFail($classificationId);
        
        // Log the feedback for machine learning improvement
        ComplianceAuditLog::logEvent(
            'classification_feedback',
            'privacy',
            'info',
            null,
            null,
            'Classification feedback received',
            [
                'classification_id' => $classificationId,
                'original_classification' => $classification->toArray(),
                'corrections' => $corrections,
                'feedback_provided_at' => Carbon::now()->toISOString()
            ],
            $classification->tenant_id
        );

        // Apply corrections
        $classification->update($corrections);

        // TODO: Use this feedback to improve ML models
        $this->improvePatternsFromFeedback($classification, $corrections);
    }

    /**
     * Improve classification patterns based on feedback
     */
    private function improvePatternsFromFeedback(DataClassification $classification, array $corrections): void
    {
        // This would implement machine learning feedback loop
        // For now, we'll log the feedback for future ML model training
        
        Log::info('Classification feedback for ML improvement', [
            'table_name' => $classification->table_name,
            'column_name' => $classification->column_name,
            'original_classification' => $classification->classification,
            'corrected_classification' => $corrections['classification'] ?? null,
            'original_pii_categories' => $classification->pii_categories,
            'corrected_pii_categories' => $corrections['pii_categories'] ?? null,
            'confidence_score' => $classification->confidence_score,
            'tenant_id' => $classification->tenant_id
        ]);
    }
}