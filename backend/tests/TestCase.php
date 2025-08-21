<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use App\Models\Tenant;
use App\Models\TenantUser;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, RefreshDatabase, WithFaker;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Prevent actual external API calls during testing
        $this->mockExternalServices();
        
        // Clear all caches
        Cache::flush();
        
        // Reset queues and events
        Queue::fake();
        Event::fake();
        Notification::fake();
        
        // Set up test database with minimal data
        $this->setupTestData();
        
        // Configure test-specific settings
        config([
            'app.env' => 'testing',
            'mail.driver' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'cache.default' => 'array'
        ]);
    }

    /**
     * Setup basic test data needed for most tests
     */
    protected function setupTestData(): void
    {
        // Create default tenant for testing
        $this->defaultTenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'tier' => 'professional',
            'status' => 'active'
        ]);

        // Create test users
        $this->adminUser = TenantUser::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'email' => 'admin@test.com',
            'role' => 'admin',
            'status' => 'active'
        ]);

        $this->regularUser = TenantUser::factory()->create([
            'tenant_id' => $this->defaultTenant->id,
            'email' => 'user@test.com',
            'role' => 'user',
            'status' => 'active'
        ]);
    }

    /**
     * Mock external services to prevent actual API calls
     */
    protected function mockExternalServices(): void
    {
        // Mock OpenAI API
        $this->mock(\App\Services\OpenAIService::class, function ($mock) {
            $mock->shouldReceive('generateEmbedding')
                ->andReturn(['embedding' => array_fill(0, 1536, 0.5)]);
            $mock->shouldReceive('analyzeText')
                ->andReturn(['sentiment' => 'positive', 'confidence' => 0.85]);
        });

        // Mock Fireflies API
        $this->mock(\App\Services\FirefliesIntegrationService::class, function ($mock) {
            $mock->shouldReceive('processWebhook')
                ->andReturn(['success' => true, 'transcript_id' => 'test_123']);
            $mock->shouldReceive('fetchTranscript')
                ->andReturn(['content' => 'Test transcript content']);
        });

        // Mock Pinecone API
        $this->mock(\App\Services\PineconeVectorService::class, function ($mock) {
            $mock->shouldReceive('upsert')
                ->andReturn(['upserted_count' => 1]);
            $mock->shouldReceive('query')
                ->andReturn(['matches' => []]);
        });

        // Mock Email services
        $this->mock(\Illuminate\Mail\Mailer::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn(true);
        });

        // Mock SMS services
        $this->mock(\App\Services\SMSService::class, function ($mock) {
            $mock->shouldReceive('sendCode')->andReturn(true);
        });
    }

    /**
     * Authenticate a user for API testing
     */
    protected function actingAsUser(TenantUser $user = null): self
    {
        $user = $user ?: $this->regularUser;
        Sanctum::actingAs($user, ['*']);
        return $this;
    }

    /**
     * Authenticate as admin user
     */
    protected function actingAsAdmin(): self
    {
        return $this->actingAsUser($this->adminUser);
    }

    /**
     * Create an authenticated API request
     */
    protected function apiAs(TenantUser $user = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($user);
    }

    /**
     * Assert JSON response structure matches expected
     */
    protected function assertJsonStructureEquals(array $expected, array $actual): void
    {
        $this->assertEquals(
            array_keys($expected),
            array_keys($actual),
            'JSON structure keys do not match'
        );

        foreach ($expected as $key => $value) {
            if (is_array($value) && isset($actual[$key]) && is_array($actual[$key])) {
                $this->assertJsonStructureEquals($value, $actual[$key]);
            }
        }
    }

    /**
     * Assert API response is successful
     */
    protected function assertApiSuccess(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data',
                    'message'
                ])
                ->assertJson(['success' => true]);
    }

    /**
     * Assert API response is an error
     */
    protected function assertApiError(\Illuminate\Testing\TestResponse $response, int $status = 400): void
    {
        $response->assertStatus($status)
                ->assertJsonStructure([
                    'success',
                    'error',
                    'message'
                ])
                ->assertJson(['success' => false]);
    }

    /**
     * Assert API response has pagination
     */
    protected function assertApiPagination(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertJsonStructure([
            'data' => [
                'data',
                'current_page',
                'first_page_url',
                'from',
                'last_page',
                'last_page_url',
                'links',
                'next_page_url',
                'path',
                'per_page',
                'prev_page_url',
                'to',
                'total'
            ]
        ]);
    }

    /**
     * Create test file upload
     */
    protected function createTestFile(string $name = 'test.txt', string $content = 'test content'): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * Create test image upload
     */
    protected function createTestImage(string $name = 'test.jpg', int $width = 100, int $height = 100): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->image($name, $width, $height);
    }

    /**
     * Assert database has tenant-scoped record
     */
    protected function assertDatabaseHasTenantRecord(string $table, array $data, Tenant $tenant = null): void
    {
        $tenant = $tenant ?: $this->defaultTenant;
        $data['tenant_id'] = $tenant->id;
        
        $this->assertDatabaseHas($table, $data);
    }

    /**
     * Assert database missing tenant-scoped record
     */
    protected function assertDatabaseMissingTenantRecord(string $table, array $data, Tenant $tenant = null): void
    {
        $tenant = $tenant ?: $this->defaultTenant;
        $data['tenant_id'] = $tenant->id;
        
        $this->assertDatabaseMissing($table, $data);
    }

    /**
     * Freeze time for testing
     */
    protected function freezeTime($time = null): \Carbon\Carbon
    {
        $frozenTime = $time ?: now();
        \Carbon\Carbon::setTestNow($frozenTime);
        return $frozenTime;
    }

    /**
     * Travel in time for testing
     */
    protected function travelTo($time): \Carbon\Carbon
    {
        \Carbon\Carbon::setTestNow($time);
        return $time;
    }

    /**
     * Assert cache has key
     */
    protected function assertCacheHas(string $key): void
    {
        $this->assertTrue(Cache::has($key), "Cache does not have key: {$key}");
    }

    /**
     * Assert cache missing key
     */
    protected function assertCacheMissing(string $key): void
    {
        $this->assertFalse(Cache::has($key), "Cache has unexpected key: {$key}");
    }

    /**
     * Assert event was dispatched
     */
    protected function assertEventDispatched(string $event, callable $callback = null): void
    {
        Event::assertDispatched($event, $callback);
    }

    /**
     * Assert job was dispatched
     */
    protected function assertJobDispatched(string $job, callable $callback = null): void
    {
        Queue::assertPushed($job, $callback);
    }

    /**
     * Assert notification was sent
     */
    protected function assertNotificationSent($notifiable, string $notification): void
    {
        Notification::assertSentTo($notifiable, $notification);
    }

    /**
     * Create performance benchmark
     */
    protected function benchmark(callable $callback, float $maxTime = 1.0): float
    {
        $start = microtime(true);
        $callback();
        $duration = microtime(true) - $start;
        
        $this->assertLessThan($maxTime, $duration, 
            "Operation took {$duration}s, expected less than {$maxTime}s");
        
        return $duration;
    }

    /**
     * Assert memory usage is within limits
     */
    protected function assertMemoryUsage(callable $callback, int $maxMemoryMB = 50): void
    {
        $memoryBefore = memory_get_usage(true);
        $callback();
        $memoryAfter = memory_get_usage(true);
        
        $memoryUsedMB = ($memoryAfter - $memoryBefore) / 1024 / 1024;
        
        $this->assertLessThan($maxMemoryMB, $memoryUsedMB, 
            "Memory usage {$memoryUsedMB}MB exceeds limit of {$maxMemoryMB}MB");
    }

    /**
     * Clean up after test
     */
    protected function tearDown(): void
    {
        // Clear test data
        Cache::flush();
        DB::disconnect();
        
        // Reset time
        \Carbon\Carbon::setTestNow();
        
        parent::tearDown();
    }

    /**
     * Helper to generate test data arrays
     */
    protected function generateTestData(string $type, int $count = 10): array
    {
        return match($type) {
            'meetings' => \App\Models\Meeting::factory()->count($count)->make()->toArray(),
            'transcripts' => \App\Models\MeetingTranscript::factory()->count($count)->make()->toArray(),
            'action_items' => \App\Models\ActionItem::factory()->count($count)->make()->toArray(),
            'insights' => \App\Models\MeetingInsight::factory()->count($count)->make()->toArray(),
            'users' => TenantUser::factory()->count($count)->make()->toArray(),
            default => []
        };
    }

    /**
     * Load test fixtures from JSON files
     */
    protected function loadFixture(string $name): array
    {
        $path = base_path("tests/fixtures/{$name}.json");
        
        if (!file_exists($path)) {
            throw new \Exception("Fixture file not found: {$path}");
        }
        
        return json_decode(file_get_contents($path), true);
    }

    /**
     * Generate realistic test conversation data
     */
    protected function generateTestConversation(int $messageCount = 10): array
    {
        $speakers = ['John Doe', 'Jane Smith', 'Bob Johnson', 'Alice Brown'];
        $conversation = [];
        
        for ($i = 0; $i < $messageCount; $i++) {
            $conversation[] = [
                'speaker' => $this->faker->randomElement($speakers),
                'timestamp' => now()->addMinutes($i)->toISOString(),
                'text' => $this->faker->sentence(rand(5, 20)),
                'confidence' => $this->faker->randomFloat(2, 0.7, 1.0)
            ];
        }
        
        return $conversation;
    }

    /**
     * Create test security context
     */
    protected function createSecurityContext(array $overrides = []): array
    {
        return array_merge([
            'ip_address' => $this->faker->ipv4,
            'user_agent' => 'Test Browser/1.0',
            'device_id' => $this->faker->uuid,
            'session_id' => $this->faker->uuid,
            'timestamp' => time(),
            'method' => 'GET',
            'path' => '/api/test'
        ], $overrides);
    }
}