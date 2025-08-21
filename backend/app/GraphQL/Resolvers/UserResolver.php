<?php

namespace App\GraphQL\Resolvers;

use App\Models\User;
use App\Models\Activity;
use App\Services\AdvancedSecurityService;
use App\Services\AdvancedMonitoringService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * User GraphQL Resolver
 * 
 * Handles all user-related GraphQL queries and mutations with comprehensive
 * performance monitoring, security validation, and caching optimization
 */
class UserResolver
{
    protected AdvancedSecurityService $securityService;
    protected AdvancedMonitoringService $monitoringService;

    public function __construct(
        AdvancedSecurityService $securityService,
        AdvancedMonitoringService $monitoringService
    ) {
        $this->securityService = $securityService;
        $this->monitoringService = $monitoringService;
    }

    /**
     * Resolve current authenticated user
     */
    public function me($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ?User
    {
        $startTime = microtime(true);
        
        try {
            // Security validation
            $this->securityService->validateRequest($context->request());
            
            $user = Auth::user();
            
            if (!$user) {
                $this->monitoringService->recordMetric('graphql.auth.failed', 1, [
                    'query' => 'me',
                    'timestamp' => now()->toISOString(),
                ]);
                return null;
            }

            // Load user with optimized relationships
            $user->load([
                'tenant',
                'meetings' => function ($query) {
                    $query->latest()->limit(10);
                },
                'activities' => function ($query) {
                    $query->latest()->limit(20);
                }
            ]);

            // Record successful access
            $this->monitoringService->recordMetric('graphql.user.me.success', 1, [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $user;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.user.me.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve user by ID with authorization checks
     */
    public function user($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ?User
    {
        $startTime = microtime(true);
        
        try {
            $userId = $args['id'];
            $currentUser = Auth::user();

            // Security validation
            $this->securityService->validateRequest($context->request());
            
            // Authorization check
            if (!$this->securityService->canViewUser($currentUser, $userId)) {
                $this->monitoringService->recordSecurityEvent('unauthorized_user_access', [
                    'requesting_user_id' => $currentUser?->id,
                    'target_user_id' => $userId,
                    'ip_address' => $context->request()->ip(),
                ]);
                throw new \Exception('Unauthorized access to user data');
            }

            // Cache key for user data
            $cacheKey = "graphql.user.{$userId}.data";
            
            $user = Cache::remember($cacheKey, 300, function () use ($userId) {
                return User::with([
                    'tenant',
                    'meetings' => function ($query) {
                        $query->latest()->limit(10);
                    },
                    'activities' => function ($query) {
                        $query->latest()->limit(20);
                    }
                ])->find($userId);
            });

            if (!$user) {
                $this->monitoringService->recordMetric('graphql.user.not_found', 1, [
                    'user_id' => $userId,
                ]);
                return null;
            }

            // Record successful access
            $this->monitoringService->recordMetric('graphql.user.success', 1, [
                'user_id' => $userId,
                'requesting_user_id' => $currentUser?->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $user;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.user.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve paginated users list with advanced filtering
     */
    public function users($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $startTime = microtime(true);
        
        try {
            $currentUser = Auth::user();
            
            // Security validation
            $this->securityService->validateRequest($context->request());
            
            // Authorization check for viewing all users
            if (!$this->securityService->canViewAllUsers($currentUser)) {
                throw new \Exception('Insufficient permissions to view users list');
            }

            $first = $args['first'] ?? 10;
            $first = min($first, 100); // Enforce maximum limit

            // Build query with tenant isolation
            $query = User::query()
                ->when($currentUser->tenant_id, function ($q) use ($currentUser) {
                    $q->where('tenant_id', $currentUser->tenant_id);
                })
                ->with(['tenant'])
                ->orderBy('created_at', 'desc');

            // Apply filters if provided
            if (isset($args['filters'])) {
                $filters = $args['filters'];
                
                if (isset($filters['role'])) {
                    $query->where('role', $filters['role']);
                }
                
                if (isset($filters['active'])) {
                    $query->where('is_active', $filters['active']);
                }
                
                if (isset($filters['search'])) {
                    $search = $filters['search'];
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'ilike', "%{$search}%")
                          ->orWhere('email', 'ilike', "%{$search}%");
                    });
                }
            }

            $users = $query->paginate($first);

            // Record successful query
            $this->monitoringService->recordMetric('graphql.users.success', 1, [
                'count' => $users->count(),
                'total' => $users->total(),
                'requesting_user_id' => $currentUser->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $users;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.users.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve user performance metrics
     */
    public function userPerformanceMetrics($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $startTime = microtime(true);
        
        try {
            $currentUser = Auth::user();
            $targetUserId = $args['user_id'] ?? $currentUser->id;
            
            // Security validation
            $this->securityService->validateRequest($context->request());
            
            // Authorization check
            if (!$this->securityService->canViewUserMetrics($currentUser, $targetUserId)) {
                throw new \Exception('Unauthorized access to user metrics');
            }

            $filters = $args['filters'] ?? [];
            $dateFrom = $filters['date_from'] ?? now()->subDays(30);
            $dateTo = $filters['date_to'] ?? now();

            // Cache key for metrics
            $cacheKey = "user.metrics.{$targetUserId}." . hash('sha256', serialize($filters));
            
            $metrics = Cache::remember($cacheKey, 1800, function () use ($targetUserId, $dateFrom, $dateTo) {
                return $this->calculateUserPerformanceMetrics($targetUserId, $dateFrom, $dateTo);
            });

            // Record successful metrics query
            $this->monitoringService->recordMetric('graphql.user_metrics.success', 1, [
                'target_user_id' => $targetUserId,
                'requesting_user_id' => $currentUser->id,
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $metrics;

        } catch (\Exception $e) {
            $this->monitoringService->recordMetric('graphql.user_metrics.error', 1, [
                'error' => $e->getMessage(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);
            throw $e;
        }
    }

    /**
     * Calculate comprehensive user performance metrics
     */
    protected function calculateUserPerformanceMetrics(int $userId, $dateFrom, $dateTo): array
    {
        $user = User::findOrFail($userId);
        
        // Query meetings in date range
        $meetings = $user->meetings()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get();

        $hostedMeetings = $meetings->where('user_id', $userId);
        $attendedMeetings = $meetings; // All meetings the user participated in
        
        // Calculate action items
        $actionItemsCreated = $user->actionItems()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();
            
        $actionItemsCompleted = $user->actionItems()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$dateFrom, $dateTo])
            ->count();

        // Calculate engagement score (0-10 scale)
        $engagementScore = $this->calculateEngagementScore($user, $meetings, $dateFrom, $dateTo);
        
        // Calculate productivity score (0-10 scale)
        $productivityScore = $this->calculateProductivityScore($actionItemsCreated, $actionItemsCompleted, $meetings->count());

        return [
            'user_id' => $userId,
            'meetings_hosted' => $hostedMeetings->count(),
            'meetings_attended' => $attendedMeetings->count(),
            'avg_meeting_duration' => $meetings->avg('actual_duration_minutes') ?? 0,
            'action_items_created' => $actionItemsCreated,
            'action_items_completed' => $actionItemsCompleted,
            'engagement_score' => round($engagementScore, 2),
            'productivity_score' => round($productivityScore, 2),
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'trends' => $this->calculateUserTrends($userId, $dateFrom, $dateTo),
        ];
    }

    /**
     * Calculate user engagement score based on multiple factors
     */
    protected function calculateEngagementScore(User $user, $meetings, $dateFrom, $dateTo): float
    {
        $score = 0;
        $maxScore = 10;

        // Meeting participation frequency (0-3 points)
        $meetingsPerWeek = $meetings->count() / max(1, now()->parse($dateTo)->diffInWeeks($dateFrom));
        $score += min(3, $meetingsPerWeek * 0.5);

        // Meeting completion rate (0-2 points)
        $completedMeetings = $meetings->where('status', 'completed')->count();
        $completionRate = $meetings->count() > 0 ? $completedMeetings / $meetings->count() : 0;
        $score += $completionRate * 2;

        // Action item follow-through (0-3 points)
        $actionItemsCreated = $user->actionItems()->whereBetween('created_at', [$dateFrom, $dateTo])->count();
        $actionItemsCompleted = $user->actionItems()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$dateFrom, $dateTo])
            ->count();
        
        if ($actionItemsCreated > 0) {
            $followThroughRate = $actionItemsCompleted / $actionItemsCreated;
            $score += $followThroughRate * 3;
        }

        // Recent activity (0-2 points)
        $recentActivities = $user->activities()
            ->whereBetween('created_at', [now()->subDays(7), now()])
            ->count();
        $score += min(2, $recentActivities * 0.1);

        return min($maxScore, $score);
    }

    /**
     * Calculate user productivity score
     */
    protected function calculateProductivityScore(int $actionItemsCreated, int $actionItemsCompleted, int $meetingsCount): float
    {
        $score = 0;
        $maxScore = 10;

        // Action items creation rate (0-4 points)
        if ($meetingsCount > 0) {
            $actionItemsPerMeeting = $actionItemsCreated / $meetingsCount;
            $score += min(4, $actionItemsPerMeeting);
        }

        // Action items completion rate (0-4 points)
        if ($actionItemsCreated > 0) {
            $completionRate = $actionItemsCompleted / $actionItemsCreated;
            $score += $completionRate * 4;
        }

        // Consistency bonus (0-2 points)
        if ($actionItemsCreated > 0 && $meetingsCount > 0) {
            $score += 2; // Bonus for being active
        }

        return min($maxScore, $score);
    }

    /**
     * Calculate user trend data
     */
    protected function calculateUserTrends(int $userId, $dateFrom, $dateTo): array
    {
        // Split date range into periods for trend analysis
        $weeks = [];
        $current = now()->parse($dateFrom)->startOfWeek();
        $end = now()->parse($dateTo)->endOfWeek();

        while ($current <= $end) {
            $weekEnd = $current->copy()->endOfWeek();
            if ($weekEnd > $end) {
                $weekEnd = $end;
            }

            $weekMeetings = User::find($userId)
                ->meetings()
                ->whereBetween('created_at', [$current, $weekEnd])
                ->count();

            $weeks[] = [
                'week' => $current->format('Y-m-d'),
                'meetings' => $weekMeetings,
            ];

            $current->addWeek();
        }

        return [
            'weekly_meetings' => $weeks,
            'trend_direction' => $this->calculateTrendDirection($weeks),
        ];
    }

    /**
     * Calculate trend direction from data points
     */
    protected function calculateTrendDirection(array $dataPoints): string
    {
        if (count($dataPoints) < 2) {
            return 'stable';
        }

        $values = array_column($dataPoints, 'meetings');
        $first = array_slice($values, 0, ceil(count($values) / 2));
        $last = array_slice($values, floor(count($values) / 2));

        $firstAvg = array_sum($first) / count($first);
        $lastAvg = array_sum($last) / count($last);

        $change = ($lastAvg - $firstAvg) / max(1, $firstAvg);

        if ($change > 0.2) {
            return 'increasing';
        } elseif ($change < -0.2) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Resolve user recent activities
     */
    public function recentActivity($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo)
    {
        $user = $rootValue;
        $limit = $args['limit'] ?? 20;
        
        return $user->activities()
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Resolve user meeting count
     */
    public function meetingCount($rootValue, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): int
    {
        $user = $rootValue;
        
        // Use cached count if available
        $cacheKey = "user.{$user->id}.meeting_count";
        
        return Cache::remember($cacheKey, 1800, function () use ($user) {
            return $user->meetings()->count();
        });
    }
}