<?php

namespace App\Services;

use App\Models\SecurityAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Advanced Threat Detection Service for DDoS Protection
 * 
 * Implements sophisticated threat detection algorithms to identify
 * and mitigate various types of attacks including DDoS, bot attacks,
 * and suspicious patterns.
 */
class ThreatDetectionService
{
    // Threat levels
    const THREAT_NONE = 0;
    const THREAT_LOW = 1;
    const THREAT_MEDIUM = 2;
    const THREAT_HIGH = 3;
    const THREAT_CRITICAL = 4;
    
    // Attack patterns
    const PATTERN_DDOS = 'ddos';
    const PATTERN_BOT = 'bot';
    const PATTERN_SCRAPING = 'scraping';
    const PATTERN_BRUTE_FORCE = 'brute_force';
    const PATTERN_ANOMALOUS = 'anomalous';
    
    private array $config;
    private array $ipReputationCache;
    
    public function __construct()
    {
        $this->config = config('rate_limiting.threat_detection', [
            'ddos_threshold' => 1000,           // Requests per minute from single IP
            'distributed_threshold' => 5000,    // Total requests per minute
            'bot_score_threshold' => 0.8,       // Bot detection confidence
            'anomaly_threshold' => 3.0,         // Standard deviations from normal
            'reputation_cache_ttl' => 3600,     // 1 hour
            'pattern_window' => 300,            // 5 minutes
        ]);
        
        $this->ipReputationCache = [];
    }

    /**
     * Assess threat level for incoming request
     */
    public function assessThreat(Request $request, string $clientId): int
    {
        $threats = [
            $this->detectDDoS($request, $clientId),
            $this->detectBotActivity($request, $clientId),
            $this->detectScrapingPattern($request, $clientId),
            $this->detectBruteForce($request, $clientId),
            $this->detectAnomalousPattern($request, $clientId),
            $this->checkIPReputation($request->ip()),
            $this->detectDistributedAttack($request)
        ];
        
        $maxThreat = max($threats);
        
        // Log high-level threats
        if ($maxThreat >= self::THREAT_HIGH) {
            $this->logThreatDetection($request, $clientId, $maxThreat, $threats);
        }
        
        return $maxThreat;
    }

    /**
     * Detect DDoS attacks from single IP
     */
    private function detectDDoS(Request $request, string $clientId): int
    {
        $ip = $request->ip();
        $windowSize = 60; // 1 minute
        $now = time();
        $windowStart = $now - $windowSize;
        
        $key = "threat:ddos_check:{$ip}";
        
        $lua = "
            local key = KEYS[1]
            local window_start = tonumber(ARGV[1])
            local now = tonumber(ARGV[2])
            local threshold = tonumber(ARGV[3])
            
            -- Remove old entries
            redis.call('ZREMRANGEBYSCORE', key, 0, window_start)
            
            -- Add current request
            redis.call('ZADD', key, now, now .. ':' .. math.random(1000000))
            redis.call('EXPIRE', key, 120)
            
            -- Count requests in window
            local count = redis.call('ZCARD', key)
            
            return count
        ";
        
        $requestCount = Redis::eval($lua, 1, $key, $windowStart, $now, $this->config['ddos_threshold']);
        
        if ($requestCount > $this->config['ddos_threshold']) {
            return self::THREAT_CRITICAL;
        } elseif ($requestCount > $this->config['ddos_threshold'] * 0.8) {
            return self::THREAT_HIGH;
        } elseif ($requestCount > $this->config['ddos_threshold'] * 0.6) {
            return self::THREAT_MEDIUM;
        }
        
        return self::THREAT_NONE;
    }

    /**
     * Detect distributed DDoS attacks
     */
    private function detectDistributedAttack(Request $request): int
    {
        $windowSize = 60; // 1 minute
        $now = time();
        $windowStart = $now - $windowSize;
        
        $key = "threat:distributed_check";
        
        $lua = "
            local key = KEYS[1]
            local window_start = tonumber(ARGV[1])
            local now = tonumber(ARGV[2])
            
            -- Remove old entries
            redis.call('ZREMRANGEBYSCORE', key, 0, window_start)
            
            -- Add current request
            redis.call('ZADD', key, now, now .. ':' .. math.random(1000000))
            redis.call('EXPIRE', key, 120)
            
            -- Count total requests
            local count = redis.call('ZCARD', key)
            
            return count
        ";
        
        $totalRequests = Redis::eval($lua, 1, $key, $windowStart, $now);
        
        if ($totalRequests > $this->config['distributed_threshold']) {
            return self::THREAT_HIGH;
        } elseif ($totalRequests > $this->config['distributed_threshold'] * 0.8) {
            return self::THREAT_MEDIUM;
        }
        
        return self::THREAT_NONE;
    }

    /**
     * Detect bot activity using multiple signals
     */
    private function detectBotActivity(Request $request, string $clientId): int
    {
        $botScore = 0;
        
        // Check User-Agent patterns
        $userAgent = $request->userAgent() ?: '';
        $botPatterns = [
            '/bot/i' => 0.9,
            '/crawler/i' => 0.8,
            '/spider/i' => 0.8,
            '/scraper/i' => 0.9,
            '/curl/i' => 0.7,
            '/wget/i' => 0.7,
            '/python/i' => 0.6,
            '/ruby/i' => 0.5,
        ];
        
        foreach ($botPatterns as $pattern => $score) {
            if (preg_match($pattern, $userAgent)) {
                $botScore = max($botScore, $score);
            }
        }
        
        // Check for missing common browser headers
        $browserHeaders = ['Accept', 'Accept-Language', 'Accept-Encoding'];
        $missingHeaders = 0;
        foreach ($browserHeaders as $header) {
            if (!$request->hasHeader($header)) {
                $missingHeaders++;
            }
        }
        
        if ($missingHeaders > 1) {
            $botScore = max($botScore, 0.6);
        }
        
        // Check request patterns (too regular intervals)
        $intervalScore = $this->checkRequestIntervals($clientId);
        $botScore = max($botScore, $intervalScore);
        
        // Check JavaScript fingerprinting
        if (!$request->hasHeader('X-JS-Fingerprint') && $request->is('api/*')) {
            $botScore = max($botScore, 0.4);
        }
        
        if ($botScore >= 0.9) {
            return self::THREAT_HIGH;
        } elseif ($botScore >= 0.7) {
            return self::THREAT_MEDIUM;
        } elseif ($botScore >= 0.5) {
            return self::THREAT_LOW;
        }
        
        return self::THREAT_NONE;
    }

    /**
     * Check for too-regular request intervals (bot behavior)
     */
    private function checkRequestIntervals(string $clientId): float
    {
        $key = "threat:intervals:{$clientId}";
        $timestamps = Cache::get($key, []);
        
        // Add current timestamp
        $timestamps[] = time();
        
        // Keep only last 10 requests
        if (count($timestamps) > 10) {
            $timestamps = array_slice($timestamps, -10);
        }
        
        Cache::put($key, $timestamps, 300); // 5 minutes
        
        if (count($timestamps) < 5) {
            return 0; // Not enough data
        }
        
        // Calculate intervals
        $intervals = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $intervals[] = $timestamps[$i] - $timestamps[$i - 1];
        }
        
        // Check for too-regular patterns
        $avgInterval = array_sum($intervals) / count($intervals);
        $variance = $this->calculateVariance($intervals, $avgInterval);
        
        // Low variance indicates regular intervals (bot-like)
        if ($variance < 1 && $avgInterval < 60) {
            return 0.8; // High bot probability
        } elseif ($variance < 5 && $avgInterval < 30) {
            return 0.6; // Medium bot probability
        }
        
        return 0;
    }

    /**
     * Detect web scraping patterns
     */
    private function detectScrapingPattern(Request $request, string $clientId): int
    {
        $scrapingScore = 0;
        
        // Check for rapid sequential page access
        $pagePattern = $this->checkPageAccessPattern($clientId, $request->path());
        $scrapingScore = max($scrapingScore, $pagePattern);
        
        // Check for data-heavy endpoints
        if ($request->is('api/*/export') || $request->is('api/*/download')) {
            $scrapingScore = max($scrapingScore, 0.6);
        }
        
        // Check for missing referrer on deep pages
        if (!$request->hasHeader('Referer') && str_contains($request->path(), '/')) {
            $scrapingScore = max($scrapingScore, 0.4);
        }
        
        if ($scrapingScore >= 0.8) {
            return self::THREAT_HIGH;
        } elseif ($scrapingScore >= 0.6) {
            return self::THREAT_MEDIUM;
        } elseif ($scrapingScore >= 0.4) {
            return self::THREAT_LOW;
        }
        
        return self::THREAT_NONE;
    }

    /**
     * Check page access patterns for scraping
     */
    private function checkPageAccessPattern(string $clientId, string $path): float
    {
        $key = "threat:pages:{$clientId}";
        $pages = Cache::get($key, []);
        
        // Add current page
        $pages[] = [
            'path' => $path,
            'timestamp' => time()
        ];
        
        // Keep only last 20 pages
        if (count($pages) > 20) {
            $pages = array_slice($pages, -20);
        }
        
        Cache::put($key, $pages, 300); // 5 minutes
        
        if (count($pages) < 10) {
            return 0; // Not enough data
        }
        
        // Check for sequential patterns
        $uniquePaths = array_unique(array_column($pages, 'path'));
        $pathRatio = count($uniquePaths) / count($pages);
        
        // High unique path ratio indicates scraping
        if ($pathRatio > 0.8) {
            return 0.7;
        } elseif ($pathRatio > 0.6) {
            return 0.5;
        }
        
        return 0;
    }

    /**
     * Detect brute force attacks
     */
    private function detectBruteForce(Request $request, string $clientId): int
    {
        // Only check authentication endpoints
        $authEndpoints = ['/api/auth/login', '/login', '/api/v1/auth/token'];
        
        $isAuthEndpoint = false;
        foreach ($authEndpoints as $endpoint) {
            if ($request->is($endpoint)) {
                $isAuthEndpoint = true;
                break;
            }
        }
        
        if (!$isAuthEndpoint) {
            return self::THREAT_NONE;
        }
        
        $ip = $request->ip();
        $windowSize = 300; // 5 minutes
        $now = time();
        $windowStart = $now - $windowSize;
        
        $key = "threat:brute_force:{$ip}";
        
        // Count failed attempts
        $attempts = Cache::get($key, 0);
        
        // This would be called after authentication fails
        if ($request->method() === 'POST') {
            $attempts++;
            Cache::put($key, $attempts, $windowSize);
        }
        
        if ($attempts > 20) {
            return self::THREAT_CRITICAL;
        } elseif ($attempts > 10) {
            return self::THREAT_HIGH;
        } elseif ($attempts > 5) {
            return self::THREAT_MEDIUM;
        }
        
        return self::THREAT_NONE;
    }

    /**
     * Detect anomalous patterns using statistical analysis
     */
    private function detectAnomalousPattern(Request $request, string $clientId): int
    {
        $features = $this->extractRequestFeatures($request);
        $anomalyScore = $this->calculateAnomalyScore($features, $clientId);
        
        if ($anomalyScore > 3.0) {
            return self::THREAT_HIGH;
        } elseif ($anomalyScore > 2.0) {
            return self::THREAT_MEDIUM;
        } elseif ($anomalyScore > 1.5) {
            return self::THREAT_LOW;
        }
        
        return self::THREAT_NONE;
    }

    /**
     * Extract numerical features from request for anomaly detection
     */
    private function extractRequestFeatures(Request $request): array
    {
        return [
            'url_length' => strlen($request->fullUrl()),
            'header_count' => count($request->headers->all()),
            'query_param_count' => count($request->query()),
            'payload_size' => strlen($request->getContent()),
            'hour_of_day' => (int) date('H'),
            'day_of_week' => (int) date('w'),
        ];
    }

    /**
     * Calculate anomaly score using z-score analysis
     */
    private function calculateAnomalyScore(array $features, string $clientId): float
    {
        $scores = [];
        
        foreach ($features as $feature => $value) {
            $stats = $this->getFeatureStatistics($feature);
            
            if ($stats['count'] > 10) { // Need minimum samples
                $zScore = abs(($value - $stats['mean']) / max($stats['std_dev'], 1));
                $scores[] = $zScore;
            }
            
            // Update statistics with current value
            $this->updateFeatureStatistics($feature, $value);
        }
        
        return empty($scores) ? 0 : max($scores);
    }

    /**
     * Get statistical data for a feature
     */
    private function getFeatureStatistics(string $feature): array
    {
        return Cache::get("threat:stats:{$feature}", [
            'count' => 0,
            'sum' => 0,
            'sum_squares' => 0,
            'mean' => 0,
            'std_dev' => 1
        ]);
    }

    /**
     * Update feature statistics (online algorithm)
     */
    private function updateFeatureStatistics(string $feature, float $value): void
    {
        $stats = $this->getFeatureStatistics($feature);
        
        $stats['count']++;
        $stats['sum'] += $value;
        $stats['sum_squares'] += $value * $value;
        
        $stats['mean'] = $stats['sum'] / $stats['count'];
        
        if ($stats['count'] > 1) {
            $variance = ($stats['sum_squares'] - ($stats['sum'] * $stats['sum']) / $stats['count']) / ($stats['count'] - 1);
            $stats['std_dev'] = sqrt(max($variance, 0.01));
        }
        
        Cache::put("threat:stats:{$feature}", $stats, 86400); // 24 hours
    }

    /**
     * Check IP reputation against known threat feeds
     */
    private function checkIPReputation(string $ip): int
    {
        // Check cache first
        if (isset($this->ipReputationCache[$ip])) {
            return $this->ipReputationCache[$ip];
        }
        
        $cacheKey = "threat:ip_reputation:{$ip}";
        $reputation = Cache::get($cacheKey);
        
        if ($reputation === null) {
            $reputation = $this->queryIPReputationServices($ip);
            Cache::put($cacheKey, $reputation, $this->config['reputation_cache_ttl']);
        }
        
        $this->ipReputationCache[$ip] = $reputation;
        return $reputation;
    }

    /**
     * Query external IP reputation services
     */
    private function queryIPReputationServices(string $ip): int
    {
        // In production, integrate with services like:
        // - AbuseIPDB
        // - VirusTotal
        // - IBM X-Force
        // - Shodan
        
        // For now, implement basic checks
        
        // Check if IP is in private ranges (should not be blocked)
        if ($this->isPrivateIP($ip)) {
            return self::THREAT_NONE;
        }
        
        // Check against known malicious IP lists (implement your feeds here)
        $maliciousIPs = Cache::get('threat:malicious_ips', []);
        if (in_array($ip, $maliciousIPs)) {
            return self::THREAT_CRITICAL;
        }
        
        // Placeholder for external service integration
        return self::THREAT_NONE;
    }

    /**
     * Check if IP is in private address space
     */
    private function isPrivateIP(string $ip): bool
    {
        $privateRanges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8'
        ];
        
        foreach ($privateRanges as $range) {
            if ($this->ipInCIDR($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if IP is in CIDR range
     */
    private function ipInCIDR(string $ip, string $cidr): bool
    {
        list($subnet, $bits) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }

    /**
     * Calculate variance for intervals
     */
    private function calculateVariance(array $values, float $mean): float
    {
        $sumSquares = 0;
        foreach ($values as $value) {
            $sumSquares += pow($value - $mean, 2);
        }
        return $sumSquares / count($values);
    }

    /**
     * Log threat detection event
     */
    private function logThreatDetection(Request $request, string $clientId, int $threatLevel, array $threats): void
    {
        SecurityAuditLog::logEvent(
            SecurityAuditLog::EVENT_SECURITY_VIOLATION,
            "Threat detected - Level: {$threatLevel}",
            $threatLevel >= self::THREAT_CRITICAL ? SecurityAuditLog::SEVERITY_CRITICAL : SecurityAuditLog::SEVERITY_WARNING,
            [
                'client_id' => $clientId,
                'threat_level' => $threatLevel,
                'threat_scores' => $threats,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'path' => $request->path(),
                'method' => $request->method(),
                'timestamp' => time()
            ]
        );
    }

    /**
     * Get threat detection metrics
     */
    public function getMetrics(int $timeRange = 3600): array
    {
        $now = time();
        $start = $now - $timeRange;
        
        $metrics = [
            'timeframe' => $timeRange,
            'threats_detected' => 0,
            'threats_by_level' => [
                self::THREAT_LOW => 0,
                self::THREAT_MEDIUM => 0,
                self::THREAT_HIGH => 0,
                self::THREAT_CRITICAL => 0
            ],
            'threats_by_type' => [
                self::PATTERN_DDOS => 0,
                self::PATTERN_BOT => 0,
                self::PATTERN_SCRAPING => 0,
                self::PATTERN_BRUTE_FORCE => 0,
                self::PATTERN_ANOMALOUS => 0
            ],
            'blocked_ips' => [],
            'top_threats' => []
        ];
        
        // Get threat logs from audit trail
        $logs = SecurityAuditLog::where('event_type', SecurityAuditLog::EVENT_SECURITY_VIOLATION)
            ->where('created_at', '>=', Carbon::createFromTimestamp($start))
            ->get();
        
        foreach ($logs as $log) {
            $data = $log->event_data;
            if (isset($data['threat_level'])) {
                $metrics['threats_detected']++;
                $metrics['threats_by_level'][$data['threat_level']]++;
            }
        }
        
        return $metrics;
    }

    /**
     * Get current threat status
     */
    public function getThreatStatus(): array
    {
        $metrics = $this->getMetrics(300); // Last 5 minutes
        
        $status = 'normal';
        if ($metrics['threats_by_level'][self::THREAT_CRITICAL] > 0) {
            $status = 'critical';
        } elseif ($metrics['threats_by_level'][self::THREAT_HIGH] > 5) {
            $status = 'high';
        } elseif ($metrics['threats_detected'] > 10) {
            $status = 'elevated';
        }
        
        return [
            'status' => $status,
            'recent_threats' => $metrics['threats_detected'],
            'critical_threats' => $metrics['threats_by_level'][self::THREAT_CRITICAL],
            'high_threats' => $metrics['threats_by_level'][self::THREAT_HIGH],
            'last_updated' => time()
        ];
    }
}