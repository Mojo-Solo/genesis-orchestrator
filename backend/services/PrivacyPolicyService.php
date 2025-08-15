<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\DataClassification;
use App\Models\ConsentRecord;
use App\Models\DataRetentionPolicy;
use App\Models\PrivacyImpactAssessment;
use App\Models\ComplianceAuditLog;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PrivacyPolicyService
{
    /**
     * Generate automated privacy policy
     */
    public function generatePrivacyPolicy(string $tenantId, array $options = []): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        // Analyze tenant's data processing activities
        $dataAnalysis = $this->analyzeDataProcessing($tenantId);
        
        // Generate policy sections
        $policy = [
            'meta' => $this->generateMetadata($tenant, $options),
            'sections' => [
                'introduction' => $this->generateIntroduction($tenant),
                'data_controller' => $this->generateDataControllerInfo($tenant),
                'data_collected' => $this->generateDataCollectedSection($dataAnalysis),
                'legal_bases' => $this->generateLegalBasesSection($dataAnalysis),
                'data_processing' => $this->generateDataProcessingSection($dataAnalysis),
                'data_sharing' => $this->generateDataSharingSection($dataAnalysis),
                'data_retention' => $this->generateDataRetentionSection($tenantId),
                'your_rights' => $this->generateYourRightsSection(),
                'international_transfers' => $this->generateInternationalTransfersSection($dataAnalysis),
                'security_measures' => $this->generateSecurityMeasuresSection($dataAnalysis),
                'cookies_tracking' => $this->generateCookiesSection($dataAnalysis),
                'changes_to_policy' => $this->generateChangesToPolicySection(),
                'contact_information' => $this->generateContactInformationSection($tenant)
            ]
        ];

        // Store policy
        $policyPath = $this->storePolicy($tenantId, $policy);
        
        // Log policy generation
        ComplianceAuditLog::logEvent(
            'privacy_policy_generated',
            'gdpr',
            'info',
            null,
            null,
            'Automated privacy policy generated',
            [
                'tenant_id' => $tenantId,
                'policy_path' => $policyPath,
                'data_types_analyzed' => count($dataAnalysis['data_types']),
                'generated_at' => Carbon::now()->toISOString()
            ],
            $tenantId,
            [],
            null,
            true
        );

        return [
            'policy' => $policy,
            'policy_path' => $policyPath,
            'generated_at' => Carbon::now(),
            'next_review_date' => Carbon::now()->addYear()
        ];
    }

    /**
     * Generate Data Processing Agreement (DPA)
     */
    public function generateDPA(string $tenantId, array $processorInfo, array $options = []): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $dataAnalysis = $this->analyzeDataProcessing($tenantId);

        $dpa = [
            'meta' => [
                'document_title' => 'Data Processing Agreement',
                'controller' => $tenant->name,
                'processor' => $processorInfo['name'],
                'effective_date' => $options['effective_date'] ?? Carbon::now()->format('Y-m-d'),
                'jurisdiction' => $options['jurisdiction'] ?? 'European Union',
                'generated_at' => Carbon::now()->toISOString()
            ],
            'sections' => [
                'definitions' => $this->generateDPADefinitions(),
                'subject_matter' => $this->generateDPASubjectMatter($processorInfo),
                'duration' => $this->generateDPADuration($options),
                'nature_purpose' => $this->generateDPANaturePurpose($dataAnalysis),
                'personal_data_categories' => $this->generateDPADataCategories($dataAnalysis),
                'data_subject_categories' => $this->generateDPADataSubjectCategories($dataAnalysis),
                'processor_obligations' => $this->generateDPAProcessorObligations(),
                'security_measures' => $this->generateDPASecurityMeasures($dataAnalysis),
                'sub_processing' => $this->generateDPASubProcessing($options),
                'data_subject_rights' => $this->generateDPADataSubjectRights(),
                'breach_notification' => $this->generateDPABreachNotification(),
                'international_transfers' => $this->generateDPAInternationalTransfers($dataAnalysis),
                'termination' => $this->generateDPATermination(),
                'liability' => $this->generateDPALiability(),
                'contact_details' => $this->generateDPAContactDetails($tenant, $processorInfo)
            ]
        ];

        // Store DPA
        $dpaPath = $this->storeDPA($tenantId, $dpa, $processorInfo['name']);

        // Log DPA generation
        ComplianceAuditLog::logEvent(
            'dpa_generated',
            'gdpr',
            'info',
            null,
            null,
            "Data Processing Agreement generated with {$processorInfo['name']}",
            [
                'tenant_id' => $tenantId,
                'processor' => $processorInfo['name'],
                'dpa_path' => $dpaPath,
                'generated_at' => Carbon::now()->toISOString()
            ],
            $tenantId
        );

        return [
            'dpa' => $dpa,
            'dpa_path' => $dpaPath,
            'processor_info' => $processorInfo,
            'generated_at' => Carbon::now()
        ];
    }

    /**
     * Generate consent notice
     */
    public function generateConsentNotice(string $tenantId, array $processingContext): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        $notice = [
            'meta' => [
                'title' => 'Data Processing Consent Notice',
                'organization' => $tenant->name,
                'context' => $processingContext['context'] ?? 'general',
                'generated_at' => Carbon::now()->toISOString()
            ],
            'sections' => [
                'introduction' => $this->generateConsentIntroduction($tenant, $processingContext),
                'data_to_process' => $this->generateConsentDataDescription($processingContext),
                'processing_purposes' => $this->generateConsentPurposes($processingContext),
                'legal_basis' => $this->generateConsentLegalBasis($processingContext),
                'recipients' => $this->generateConsentRecipients($processingContext),
                'retention_period' => $this->generateConsentRetention($processingContext),
                'your_rights' => $this->generateConsentRights(),
                'withdrawal' => $this->generateConsentWithdrawal($tenant),
                'consequences' => $this->generateConsentConsequences($processingContext)
            ]
        ];

        return $notice;
    }

    /**
     * Analyze tenant's data processing activities
     */
    private function analyzeDataProcessing(string $tenantId): array
    {
        // Get data classifications
        $classifications = DataClassification::byTenant($tenantId)->get();
        
        // Get consent records to understand processing purposes
        $consents = ConsentRecord::byTenant($tenantId)->get();
        
        // Get retention policies
        $retentionPolicies = DataRetentionPolicy::byTenant($tenantId)->active()->get();

        // Analyze data types
        $dataTypes = [];
        $piiCategories = [];
        $specialCategories = [];
        $requiresEncryption = false;
        $crossBorderRestricted = false;

        foreach ($classifications as $classification) {
            $dataTypes[] = $classification->data_type;
            
            if ($classification->pii_categories) {
                $piiCategories = array_merge($piiCategories, $classification->pii_categories);
            }
            
            if ($classification->special_categories) {
                $specialCategories = array_merge($specialCategories, $classification->special_categories);
            }
            
            if ($classification->requires_encryption) {
                $requiresEncryption = true;
            }
            
            if ($classification->cross_border_restricted) {
                $crossBorderRestricted = true;
            }
        }

        // Analyze processing purposes
        $processingPurposes = $consents->pluck('processing_purpose')->unique()->values()->toArray();
        
        // Analyze legal bases
        $legalBases = $consents->pluck('consent_type')->unique()->values()->toArray();
        $retentionBases = $retentionPolicies->pluck('legal_basis')->unique()->values()->toArray();

        return [
            'data_types' => array_unique($dataTypes),
            'pii_categories' => array_unique($piiCategories),
            'special_categories' => array_unique($specialCategories),
            'processing_purposes' => $processingPurposes,
            'legal_bases' => array_unique(array_merge($legalBases, $retentionBases)),
            'requires_encryption' => $requiresEncryption,
            'cross_border_restricted' => $crossBorderRestricted,
            'retention_periods' => $retentionPolicies->pluck('retention_period_days')->unique()->values()->toArray()
        ];
    }

    /**
     * Generate policy metadata
     */
    private function generateMetadata(Tenant $tenant, array $options): array
    {
        return [
            'document_title' => 'Privacy Policy',
            'organization' => $tenant->name,
            'website' => $options['website'] ?? $tenant->website ?? 'https://example.com',
            'effective_date' => $options['effective_date'] ?? Carbon::now()->format('Y-m-d'),
            'last_updated' => Carbon::now()->format('Y-m-d'),
            'version' => $options['version'] ?? '1.0',
            'jurisdiction' => $options['jurisdiction'] ?? 'European Union',
            'language' => $options['language'] ?? 'en',
            'generated_by' => 'GENESIS Privacy Compliance System',
            'generated_at' => Carbon::now()->toISOString()
        ];
    }

    /**
     * Generate introduction section
     */
    private function generateIntroduction(Tenant $tenant): array
    {
        return [
            'title' => 'Introduction',
            'content' => [
                "Welcome to {$tenant->name}. We are committed to protecting your privacy and personal data.",
                "This Privacy Policy explains how we collect, use, store, and protect your personal information when you use our services.",
                "We comply with applicable data protection laws, including the General Data Protection Regulation (GDPR) and other relevant privacy regulations.",
                "By using our services, you agree to the collection and use of information in accordance with this policy."
            ]
        ];
    }

    /**
     * Generate data controller information
     */
    private function generateDataControllerInfo(Tenant $tenant): array
    {
        return [
            'title' => 'Data Controller',
            'content' => [
                "organization" => $tenant->name,
                "address" => $tenant->address ?? "Please provide company address",
                "email" => $tenant->contact_email ?? "privacy@{$tenant->domain}",
                "phone" => $tenant->contact_phone ?? "Please provide contact phone",
                "dpo_email" => $tenant->dpo_email ?? "dpo@{$tenant->domain}",
                "description" => "We are the data controller responsible for your personal data and determining the purposes and means of processing."
            ]
        ];
    }

    /**
     * Generate data collected section
     */
    private function generateDataCollectedSection(array $dataAnalysis): array
    {
        $dataDescriptions = [
            'name' => 'Your name and contact information',
            'email' => 'Email addresses for communication and account management',
            'phone' => 'Phone numbers for contact and verification purposes',
            'ip_address' => 'IP addresses for security and analytics purposes',
            'location' => 'Location data for service personalization',
            'credit_card' => 'Payment information for transaction processing',
            'ssn' => 'Government identification numbers when legally required'
        ];

        $specialDescriptions = [
            'health' => 'Health-related information for specialized services',
            'biometric' => 'Biometric data for security and authentication',
            'political' => 'Political opinions when relevant to our services',
            'religious' => 'Religious beliefs when relevant to our services'
        ];

        $collectedData = [];
        
        foreach ($dataAnalysis['pii_categories'] as $category) {
            if (isset($dataDescriptions[$category])) {
                $collectedData[] = $dataDescriptions[$category];
            }
        }

        foreach ($dataAnalysis['special_categories'] as $category) {
            if (isset($specialDescriptions[$category])) {
                $collectedData[] = $specialDescriptions[$category];
            }
        }

        return [
            'title' => 'Information We Collect',
            'content' => [
                'description' => 'We collect the following types of personal information:',
                'data_types' => $collectedData,
                'collection_methods' => [
                    'Direct collection: Information you provide directly when using our services',
                    'Automatic collection: Information collected automatically through your use of our services',
                    'Third-party sources: Information received from partners and public sources where permitted'
                ]
            ]
        ];
    }

    /**
     * Generate legal bases section
     */
    private function generateLegalBasesSection(array $dataAnalysis): array
    {
        $legalBasesDescriptions = [
            'consent' => 'Your explicit consent for specific processing activities',
            'contract' => 'Processing necessary for the performance of a contract with you',
            'legal_obligation' => 'Processing required to comply with legal obligations',
            'legitimate_interest' => 'Processing necessary for our legitimate business interests',
            'vital_interests' => 'Processing necessary to protect vital interests',
            'public_task' => 'Processing necessary for public interest tasks'
        ];

        $applicableBases = [];
        foreach ($dataAnalysis['legal_bases'] as $basis) {
            if (isset($legalBasesDescriptions[$basis])) {
                $applicableBases[$basis] = $legalBasesDescriptions[$basis];
            }
        }

        return [
            'title' => 'Legal Basis for Processing',
            'content' => [
                'description' => 'We process your personal data based on the following legal grounds:',
                'legal_bases' => $applicableBases
            ]
        ];
    }

    /**
     * Generate data processing section
     */
    private function generateDataProcessingSection(array $dataAnalysis): array
    {
        $purposeDescriptions = [
            'service_provision' => 'To provide and maintain our services',
            'analytics' => 'To analyze usage patterns and improve our services',
            'marketing' => 'To send promotional communications and offers',
            'legal_compliance' => 'To comply with legal and regulatory requirements',
            'security' => 'To ensure the security and integrity of our systems',
            'research' => 'To conduct research and development activities'
        ];

        $applicablePurposes = [];
        foreach ($dataAnalysis['processing_purposes'] as $purpose) {
            if (isset($purposeDescriptions[$purpose])) {
                $applicablePurposes[] = $purposeDescriptions[$purpose];
            }
        }

        return [
            'title' => 'How We Use Your Information',
            'content' => [
                'description' => 'We use your personal information for the following purposes:',
                'purposes' => $applicablePurposes
            ]
        ];
    }

    /**
     * Generate data sharing section
     */
    private function generateDataSharingSection(array $dataAnalysis): array
    {
        return [
            'title' => 'Information Sharing and Disclosure',
            'content' => [
                'description' => 'We may share your personal information in the following circumstances:',
                'sharing_scenarios' => [
                    'Service providers: With trusted third-party service providers who assist us in operating our services',
                    'Legal requirements: When required by law, regulation, or legal process',
                    'Business transfers: In connection with mergers, acquisitions, or asset sales',
                    'Consent: With your explicit consent for specific sharing purposes'
                ],
                'safeguards' => [
                    'We ensure all third parties maintain appropriate security measures',
                    'Data sharing is limited to what is necessary for the specified purpose',
                    'We have contractual agreements in place to protect your data'
                ]
            ]
        ];
    }

    /**
     * Generate data retention section
     */
    private function generateDataRetentionSection(string $tenantId): array
    {
        $retentionPolicies = DataRetentionPolicy::byTenant($tenantId)->active()->get();
        
        $retentionInfo = [];
        foreach ($retentionPolicies as $policy) {
            $years = round($policy->retention_period_days / 365, 1);
            $retentionInfo[$policy->data_category] = [
                'period' => $years > 1 ? "{$years} years" : "{$policy->retention_period_days} days",
                'purpose' => $policy->policy_description,
                'action' => $policy->retention_action
            ];
        }

        return [
            'title' => 'Data Retention',
            'content' => [
                'description' => 'We retain your personal information for as long as necessary to fulfill the purposes outlined in this policy:',
                'retention_periods' => $retentionInfo,
                'deletion_process' => 'When retention periods expire, data is securely deleted or anonymized according to our data retention policies.'
            ]
        ];
    }

    /**
     * Generate your rights section
     */
    private function generateYourRightsSection(): array
    {
        return [
            'title' => 'Your Privacy Rights',
            'content' => [
                'description' => 'Under applicable data protection laws, you have the following rights:',
                'rights' => [
                    'Right of access: Request access to your personal data',
                    'Right to rectification: Request correction of inaccurate personal data',
                    'Right to erasure: Request deletion of your personal data ("right to be forgotten")',
                    'Right to restrict processing: Request limitation of how we process your data',
                    'Right to data portability: Request transfer of your data in a structured format',
                    'Right to object: Object to certain types of processing',
                    'Right to withdraw consent: Withdraw consent for consent-based processing'
                ],
                'exercise_rights' => 'To exercise these rights, please contact us using the information provided in this policy. We will respond to your request within the legally required timeframes.'
            ]
        ];
    }

    /**
     * Generate international transfers section
     */
    private function generateInternationalTransfersSection(array $dataAnalysis): array
    {
        $content = [
            'title' => 'International Data Transfers',
            'content' => []
        ];

        if ($dataAnalysis['cross_border_restricted']) {
            $content['content'] = [
                'description' => 'Your personal data may be transferred to and processed in countries outside your region.',
                'safeguards' => [
                    'We ensure adequate protection through appropriate safeguards',
                    'Transfers are based on adequacy decisions or standard contractual clauses',
                    'We implement additional security measures for international transfers'
                ],
                'your_rights' => 'You have the right to obtain information about international transfers and the safeguards in place.'
            ];
        } else {
            $content['content'] = [
                'description' => 'Your personal data is processed within your jurisdiction and is not transferred internationally without appropriate safeguards.'
            ];
        }

        return $content;
    }

    /**
     * Generate security measures section
     */
    private function generateSecurityMeasuresSection(array $dataAnalysis): array
    {
        $measures = [
            'Encryption of data in transit and at rest',
            'Access controls and authentication mechanisms',
            'Regular security assessments and audits',
            'Employee training on data protection',
            'Incident response and breach notification procedures'
        ];

        if ($dataAnalysis['requires_encryption']) {
            $measures[] = 'Enhanced encryption for sensitive personal data';
            $measures[] = 'Cryptographic key management';
        }

        if (!empty($dataAnalysis['special_categories'])) {
            $measures[] = 'Special security measures for special categories of data';
            $measures[] = 'Restricted access to sensitive personal data';
        }

        return [
            'title' => 'Security Measures',
            'content' => [
                'description' => 'We implement appropriate technical and organizational measures to protect your personal data:',
                'measures' => $measures,
                'note' => 'While we strive to protect your personal information, no method of transmission or storage is 100% secure. We continuously work to improve our security measures.'
            ]
        ];
    }

    /**
     * Generate cookies section
     */
    private function generateCookiesSection(array $dataAnalysis): array
    {
        return [
            'title' => 'Cookies and Tracking Technologies',
            'content' => [
                'description' => 'We use cookies and similar tracking technologies to enhance your experience:',
                'cookie_types' => [
                    'Essential cookies: Necessary for basic website functionality',
                    'Analytics cookies: Help us understand how you use our services',
                    'Marketing cookies: Used to deliver relevant advertisements',
                    'Preference cookies: Remember your settings and preferences'
                ],
                'control' => 'You can control cookie settings through your browser preferences or our cookie management tools.',
                'consent' => 'We obtain your consent for non-essential cookies as required by law.'
            ]
        ];
    }

    /**
     * Generate changes to policy section
     */
    private function generateChangesToPolicySection(): array
    {
        return [
            'title' => 'Changes to This Privacy Policy',
            'content' => [
                'We may update this Privacy Policy from time to time to reflect changes in our practices or applicable laws.',
                'We will notify you of any material changes by posting the updated policy on our website or through other appropriate communication channels.',
                'Your continued use of our services after any changes indicates your acceptance of the updated policy.',
                'We encourage you to review this policy periodically to stay informed about how we protect your information.'
            ]
        ];
    }

    /**
     * Generate contact information section
     */
    private function generateContactInformationSection(Tenant $tenant): array
    {
        return [
            'title' => 'Contact Us',
            'content' => [
                'description' => 'If you have any questions about this Privacy Policy or our data practices, please contact us:',
                'contact_info' => [
                    'organization' => $tenant->name,
                    'email' => $tenant->contact_email ?? "privacy@{$tenant->domain}",
                    'phone' => $tenant->contact_phone ?? 'Available upon request',
                    'address' => $tenant->address ?? 'Available upon request',
                    'dpo_email' => $tenant->dpo_email ?? "dpo@{$tenant->domain}"
                ]
            ]
        ];
    }

    /**
     * Store privacy policy
     */
    private function storePolicy(string $tenantId, array $policy): string
    {
        $filename = "privacy_policy_{$tenantId}_" . Carbon::now()->format('Y-m-d_H-i-s') . '.json';
        $path = "policies/{$tenantId}/{$filename}";
        
        Storage::put($path, json_encode($policy, JSON_PRETTY_PRINT));
        
        return $path;
    }

    /**
     * Store DPA
     */
    private function storeDPA(string $tenantId, array $dpa, string $processorName): string
    {
        $safeProcessorName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $processorName);
        $filename = "dpa_{$tenantId}_{$safeProcessorName}_" . Carbon::now()->format('Y-m-d_H-i-s') . '.json';
        $path = "dpa/{$tenantId}/{$filename}";
        
        Storage::put($path, json_encode($dpa, JSON_PRETTY_PRINT));
        
        return $path;
    }

    // DPA Generation Methods

    private function generateDPADefinitions(): array
    {
        return [
            'title' => 'Definitions',
            'content' => [
                '"Controller"' => 'The natural or legal person that determines the purposes and means of the processing of personal data.',
                '"Processor"' => 'The natural or legal person that processes personal data on behalf of the Controller.',
                '"Personal Data"' => 'Any information relating to an identified or identifiable natural person.',
                '"Processing"' => 'Any operation performed on personal data, whether automated or not.',
                '"Data Subject"' => 'An identified or identifiable natural person whose personal data is processed.',
                '"GDPR"' => 'Regulation (EU) 2016/679 of the European Parliament and of the Council.'
            ]
        ];
    }

    private function generateDPASubjectMatter(array $processorInfo): array
    {
        return [
            'title' => 'Subject Matter and Details of Processing',
            'content' => [
                'description' => 'The Processor shall process personal data on behalf of the Controller for the following services:',
                'services' => $processorInfo['services'] ?? ['Data processing services as specified in the main agreement'],
                'processing_location' => $processorInfo['location'] ?? 'As specified in service agreement'
            ]
        ];
    }

    private function generateDPADuration(array $options): array
    {
        return [
            'title' => 'Duration of Processing',
            'content' => [
                'start_date' => $options['effective_date'] ?? Carbon::now()->format('Y-m-d'),
                'end_date' => $options['end_date'] ?? 'Until termination of the main service agreement',
                'termination_conditions' => 'This agreement terminates automatically upon termination of the main service agreement.'
            ]
        ];
    }

    private function generateDPANaturePurpose(array $dataAnalysis): array
    {
        return [
            'title' => 'Nature and Purpose of Processing',
            'content' => [
                'nature' => 'Processing of personal data as necessary for the provision of services under the main agreement.',
                'purposes' => $dataAnalysis['processing_purposes'] ?: ['Service provision', 'Technical support', 'System maintenance']
            ]
        ];
    }

    private function generateDPADataCategories(array $dataAnalysis): array
    {
        $categoryDescriptions = [
            'name' => 'Name and identification data',
            'email' => 'Contact information (email addresses)',
            'phone' => 'Contact information (phone numbers)',
            'ip_address' => 'Technical data (IP addresses)',
            'location' => 'Location data',
            'credit_card' => 'Financial information',
            'health' => 'Health-related data',
            'biometric' => 'Biometric identifiers'
        ];

        $applicableCategories = [];
        foreach ($dataAnalysis['pii_categories'] as $category) {
            if (isset($categoryDescriptions[$category])) {
                $applicableCategories[] = $categoryDescriptions[$category];
            }
        }

        return [
            'title' => 'Categories of Personal Data',
            'content' => [
                'categories' => $applicableCategories ?: ['Personal data as specified in the service agreement'],
                'special_categories' => !empty($dataAnalysis['special_categories']) ? 
                    array_map(fn($cat) => ucfirst($cat) . ' data', $dataAnalysis['special_categories']) : 
                    ['None']
            ]
        ];
    }

    private function generateDPADataSubjectCategories(array $dataAnalysis): array
    {
        return [
            'title' => 'Categories of Data Subjects',
            'content' => [
                'categories' => [
                    'Users of Controller\'s services',
                    'Customers and clients',
                    'Employees and contractors (where applicable)',
                    'Website visitors and users'
                ]
            ]
        ];
    }

    private function generateDPAProcessorObligations(): array
    {
        return [
            'title' => 'Processor\'s Obligations',
            'content' => [
                'general_obligations' => [
                    'Process personal data only on documented instructions from the Controller',
                    'Ensure confidentiality of personal data',
                    'Implement appropriate technical and organizational security measures',
                    'Not engage sub-processors without prior authorization',
                    'Assist the Controller in responding to data subject requests',
                    'Assist the Controller in meeting its compliance obligations',
                    'Return or delete personal data upon termination'
                ],
                'instructions' => 'The Processor shall process personal data only in accordance with documented instructions from the Controller, including regarding international transfers.'
            ]
        ];
    }

    private function generateDPASecurityMeasures(array $dataAnalysis): array
    {
        $baseMeasures = [
            'Encryption of personal data in transit and at rest',
            'Regular security assessments and vulnerability testing',
            'Access controls and authentication mechanisms',
            'Staff training on data protection and security',
            'Incident response and breach notification procedures',
            'Regular backup and recovery procedures'
        ];

        if ($dataAnalysis['requires_encryption']) {
            $baseMeasures[] = 'Enhanced encryption for sensitive data categories';
        }

        if (!empty($dataAnalysis['special_categories'])) {
            $baseMeasures[] = 'Special security measures for special categories of personal data';
        }

        return [
            'title' => 'Technical and Organizational Security Measures',
            'content' => [
                'description' => 'The Processor shall implement appropriate technical and organizational measures to ensure security of personal data:',
                'measures' => $baseMeasures,
                'review' => 'Security measures shall be reviewed and updated regularly to maintain effectiveness.'
            ]
        ];
    }

    private function generateDPASubProcessing(array $options): array
    {
        return [
            'title' => 'Sub-processing',
            'content' => [
                'authorization' => $options['allow_subprocessors'] ?? false ? 
                    'The Controller authorizes the Processor to engage sub-processors subject to the conditions in this agreement.' :
                    'The Processor shall not engage sub-processors without specific prior written authorization from the Controller.',
                'conditions' => [
                    'Sub-processors must provide the same level of data protection as this agreement',
                    'The Processor remains fully liable for sub-processor activities',
                    'Current list of sub-processors must be maintained and shared with Controller'
                ],
                'notification' => 'The Processor shall inform the Controller of any changes to sub-processors with sufficient advance notice.'
            ]
        ];
    }

    private function generateDPADataSubjectRights(): array
    {
        return [
            'title' => 'Data Subject Rights',
            'content' => [
                'assistance_obligation' => 'The Processor shall assist the Controller in responding to data subject requests within applicable legal timeframes.',
                'supported_rights' => [
                    'Right of access to personal data',
                    'Right to rectification of personal data',
                    'Right to erasure ("right to be forgotten")',
                    'Right to restriction of processing',
                    'Right to data portability',
                    'Right to object to processing'
                ],
                'response_time' => 'The Processor shall provide assistance within 72 hours of receiving a request from the Controller.'
            ]
        ];
    }

    private function generateDPABreachNotification(): array
    {
        return [
            'title' => 'Personal Data Breach Notification',
            'content' => [
                'notification_obligation' => 'The Processor shall notify the Controller without undue delay upon becoming aware of a personal data breach.',
                'notification_timeframe' => 'Notification shall be provided within 72 hours of becoming aware of the breach.',
                'notification_content' => [
                    'Description of the nature of the breach',
                    'Categories and approximate number of data subjects affected',
                    'Categories and approximate number of records affected',
                    'Likely consequences of the breach',
                    'Measures taken or proposed to address the breach'
                ],
                'assistance' => 'The Processor shall provide reasonable assistance to the Controller in meeting breach notification obligations to supervisory authorities and data subjects.'
            ]
        ];
    }

    private function generateDPAInternationalTransfers(array $dataAnalysis): array
    {
        $content = [
            'title' => 'International Data Transfers',
            'content' => []
        ];

        if ($dataAnalysis['cross_border_restricted']) {
            $content['content'] = [
                'restriction' => 'Personal data may only be transferred to third countries or international organizations with appropriate safeguards.',
                'safeguards' => [
                    'European Commission adequacy decisions',
                    'Standard Contractual Clauses approved by the European Commission',
                    'Binding Corporate Rules',
                    'Codes of conduct or certification mechanisms with binding enforcement'
                ],
                'authorization' => 'Any international transfer requires prior written authorization from the Controller.'
            ];
        } else {
            $content['content'] = [
                'domestic_processing' => 'Processing shall be conducted within the agreed jurisdiction unless otherwise authorized by the Controller.'
            ];
        }

        return $content;
    }

    private function generateDPATermination(): array
    {
        return [
            'title' => 'Return or Deletion of Personal Data',
            'content' => [
                'termination_obligation' => 'Upon termination of this agreement, the Processor shall return or delete all personal data and any copies thereof.',
                'deletion_timeframe' => 'Return or deletion shall be completed within 30 days of termination unless legal requirements mandate retention.',
                'certification' => 'The Processor shall provide written certification of deletion or return of personal data.',
                'exceptions' => 'Data may be retained only to the extent required by applicable law, in which case the Processor shall inform the Controller of such legal requirements.'
            ]
        ];
    }

    private function generateDPALiability(): array
    {
        return [
            'title' => 'Liability and Indemnification',
            'content' => [
                'liability_allocation' => 'Each party shall be liable for damages caused by its own processing activities that violate this agreement or applicable data protection law.',
                'joint_liability' => 'Where both parties are involved in the same processing operation, they may be jointly and severally liable.',
                'indemnification' => 'Each party shall indemnify the other against claims arising from its breach of this agreement.',
                'limitation' => 'Liability limitations in the main service agreement shall apply to this DPA unless prohibited by applicable law.'
            ]
        ];
    }

    private function generateDPAContactDetails(Tenant $tenant, array $processorInfo): array
    {
        return [
            'title' => 'Contact Details',
            'content' => [
                'controller' => [
                    'name' => $tenant->name,
                    'email' => $tenant->contact_email ?? "privacy@{$tenant->domain}",
                    'dpo_email' => $tenant->dpo_email ?? "dpo@{$tenant->domain}",
                    'address' => $tenant->address ?? 'Address on file'
                ],
                'processor' => [
                    'name' => $processorInfo['name'],
                    'email' => $processorInfo['email'] ?? 'Contact email required',
                    'dpo_email' => $processorInfo['dpo_email'] ?? 'DPO email if applicable',
                    'address' => $processorInfo['address'] ?? 'Address required'
                ]
            ]
        ];
    }

    // Consent Notice Generation Methods

    private function generateConsentIntroduction(Tenant $tenant, array $context): array
    {
        return [
            'title' => 'Consent Request',
            'content' => [
                "We, {$tenant->name}, would like your consent to process your personal data for the purposes described below.",
                "Your consent is important to us, and you have the right to withdraw it at any time.",
                "Please read the following information carefully and indicate your consent preferences."
            ]
        ];
    }

    private function generateConsentDataDescription(array $context): array
    {
        return [
            'title' => 'Data to be Processed',
            'content' => [
                'description' => 'We would like to process the following personal data:',
                'data_types' => $context['data_types'] ?? ['Contact information', 'Usage data', 'Communication preferences']
            ]
        ];
    }

    private function generateConsentPurposes(array $context): array
    {
        return [
            'title' => 'Purposes of Processing',
            'content' => [
                'description' => 'We will use your personal data for the following purposes:',
                'purposes' => $context['purposes'] ?? ['Service provision', 'Communication', 'Service improvement']
            ]
        ];
    }

    private function generateConsentLegalBasis(array $context): array
    {
        return [
            'title' => 'Legal Basis',
            'content' => [
                'basis' => $context['legal_basis'] ?? 'consent',
                'description' => 'The legal basis for this processing is your explicit consent. You have the right to withdraw your consent at any time.'
            ]
        ];
    }

    private function generateConsentRecipients(array $context): array
    {
        return [
            'title' => 'Data Recipients',
            'content' => [
                'description' => 'Your personal data may be shared with:',
                'recipients' => $context['recipients'] ?? ['Internal teams', 'Service providers', 'Legal authorities when required']
            ]
        ];
    }

    private function generateConsentRetention(array $context): array
    {
        return [
            'title' => 'Data Retention',
            'content' => [
                'period' => $context['retention_period'] ?? 'Until consent is withdrawn or the purpose is fulfilled',
                'description' => 'We will retain your personal data for the period specified above, after which it will be securely deleted or anonymized.'
            ]
        ];
    }

    private function generateConsentRights(): array
    {
        return [
            'title' => 'Your Rights',
            'content' => [
                'description' => 'You have the following rights regarding your personal data:',
                'rights' => [
                    'Access your personal data',
                    'Correct inaccurate data',
                    'Request deletion of your data',
                    'Restrict processing',
                    'Data portability',
                    'Object to processing',
                    'Withdraw consent at any time'
                ]
            ]
        ];
    }

    private function generateConsentWithdrawal(Tenant $tenant): array
    {
        return [
            'title' => 'Withdrawal of Consent',
            'content' => [
                'description' => 'You can withdraw your consent at any time by:',
                'methods' => [
                    "Contacting us at {$tenant->contact_email}",
                    'Using the unsubscribe link in our communications',
                    'Updating your preferences in your account settings',
                    'Contacting our Data Protection Officer'
                ],
                'effect' => 'Withdrawing consent will not affect the lawfulness of processing based on consent before its withdrawal.'
            ]
        ];
    }

    private function generateConsentConsequences(array $context): array
    {
        return [
            'title' => 'Consequences of Not Providing Consent',
            'content' => [
                'description' => 'If you do not provide consent:',
                'consequences' => $context['consequences'] ?? [
                    'We may not be able to provide certain services',
                    'You may not receive communications about our services',
                    'Some features may not be available to you'
                ]
            ]
        ];
    }
}