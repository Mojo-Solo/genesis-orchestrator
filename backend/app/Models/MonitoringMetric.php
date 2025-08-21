<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Monitoring Metric Model
 * 
 * Stores time-series monitoring data for analysis and alerting
 */
class MonitoringMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'series',
        'data',
        'tenant_id',
        'timestamp',
    ];

    protected $casts = [
        'data' => 'array',
        'timestamp' => 'datetime',
    ];

    /**
     * Get metrics for a specific series within a time range
     */
    public static function getSeriesData(
        string $series,
        \DateTime $startTime,
        \DateTime $endTime,
        ?int $tenantId = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = self::where('series', $series)
            ->whereBetween('timestamp', [$startTime, $endTime])
            ->orderBy('timestamp');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get();
    }

    /**
     * Aggregate metrics by time interval
     */
    public static function aggregateByInterval(
        string $series,
        \DateTime $startTime,
        \DateTime $endTime,
        string $interval = '1h',
        string $aggregation = 'avg',
        ?int $tenantId = null
    ): array {
        $intervalMapping = [
            '1m' => 'YYYY-MM-DD HH24:MI',
            '5m' => 'YYYY-MM-DD HH24:MI',
            '15m' => 'YYYY-MM-DD HH24:MI',
            '1h' => 'YYYY-MM-DD HH24',
            '1d' => 'YYYY-MM-DD',
            '1w' => 'YYYY-"W"WW',
        ];

        $dateFormat = $intervalMapping[$interval] ?? $intervalMapping['1h'];

        $query = self::selectRaw("
            to_char(timestamp, '{$dateFormat}') as time_bucket,
            {$aggregation}((data->>'value')::numeric) as value,
            count(*) as count
        ")
            ->where('series', $series)
            ->whereBetween('timestamp', [$startTime, $endTime])
            ->groupBy('time_bucket')
            ->orderBy('time_bucket');

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get()->toArray();
    }

    /**
     * Get latest metric value for a series
     */
    public static function getLatestValue(string $series, ?int $tenantId = null): ?float
    {
        $query = self::where('series', $series)
            ->orderBy('timestamp', 'desc')
            ->limit(1);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $metric = $query->first();

        return $metric ? $metric->data['value'] ?? null : null;
    }

    /**
     * Clean up old metrics based on retention policy
     */
    public static function cleanupOldMetrics(int $retentionDays = 30): int
    {
        $cutoffDate = now()->subDays($retentionDays);
        
        return self::where('timestamp', '<', $cutoffDate)->delete();
    }
}