<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use App\Contracts\PluginInterface;
use Carbon\Carbon;
use Exception;
use ZipArchive;

class PluginArchitectureService
{
    protected $config;
    protected $auditService;
    protected $plugins = [];
    protected $pluginInstances = [];

    public function __construct(SecurityAuditService $auditService)
    {
        $this->config = Config::get('integrations.plugins');
        $this->auditService = $auditService;
        
        if ($this->config['enabled']) {
            $this->initializePlugins();
        }
    }

    /**
     * Initialize all installed plugins
     */
    protected function initializePlugins(): void
    {
        if ($this->config['auto_discovery']) {
            $this->discoverPlugins();
        }

        $this->loadRegisteredPlugins();
    }

    /**
     * Discover plugins in the plugin directory
     */
    protected function discoverPlugins(): void
    {
        $pluginDir = $this->config['plugin_directory'];
        
        if (!File::exists($pluginDir)) {
            File::makeDirectory($pluginDir, 0755, true);
            return;
        }

        $directories = File::directories($pluginDir);
        
        foreach ($directories as $directory) {
            $pluginName = basename($directory);
            $manifestPath = $directory . '/plugin.json';
            
            if (File::exists($manifestPath)) {
                try {
                    $manifest = json_decode(File::get($manifestPath), true);
                    
                    if ($this->validatePluginManifest($manifest)) {
                        $this->registerPlugin($pluginName, $manifest, $directory);
                    }
                    
                } catch (Exception $e) {
                    Log::error('Failed to load plugin manifest', [
                        'plugin' => $pluginName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Load registered plugins from database
     */
    protected function loadRegisteredPlugins(): void
    {
        $plugins = DB::table('plugins')
            ->where('status', 'active')
            ->get();

        foreach ($plugins as $plugin) {
            try {
                $this->loadPlugin($plugin);
            } catch (Exception $e) {
                Log::error('Failed to load plugin', [
                    'plugin_id' => $plugin->id,
                    'plugin_name' => $plugin->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Install a plugin from a package
     */
    public function installPlugin(string $packagePath, string $tenantId): array
    {
        // Security check
        if (!$this->isSecurePackage($packagePath)) {
            throw new Exception('Plugin package failed security validation');
        }

        $extractPath = $this->config['plugin_directory'] . '/' . 'temp_' . bin2hex(random_bytes(8));
        
        try {
            // Extract plugin package
            $this->extractPluginPackage($packagePath, $extractPath);
            
            // Load and validate manifest
            $manifestPath = $extractPath . '/plugin.json';
            if (!File::exists($manifestPath)) {
                throw new Exception('Plugin manifest not found');
            }

            $manifest = json_decode(File::get($manifestPath), true);
            if (!$this->validatePluginManifest($manifest)) {
                throw new Exception('Invalid plugin manifest');
            }

            // Check dependencies
            $this->validatePluginDependencies($manifest);

            // Security scan
            if ($this->config['security_scan']) {
                $this->performSecurityScan($extractPath, $manifest);
            }

            // Move to final location
            $finalPath = $this->config['plugin_directory'] . '/' . $manifest['name'];
            if (File::exists($finalPath)) {
                throw new Exception('Plugin already exists');
            }

            File::move($extractPath, $finalPath);

            // Register plugin in database
            $pluginId = $this->registerPluginInDatabase($manifest, $finalPath, $tenantId);

            // Load plugin
            $plugin = DB::table('plugins')->where('id', $pluginId)->first();
            $this->loadPlugin($plugin);

            $this->auditService->logSecurityEvent([
                'tenant_id' => $tenantId,
                'event_type' => 'plugin_installed',
                'plugin_id' => $pluginId,
                'plugin_name' => $manifest['name'],
                'plugin_version' => $manifest['version'],
            ]);

            return [
                'success' => true,
                'plugin_id' => $pluginId,
                'plugin_name' => $manifest['name'],
                'version' => $manifest['version'],
            ];

        } catch (Exception $e) {
            // Cleanup on failure
            if (File::exists($extractPath)) {
                File::deleteDirectory($extractPath);
            }

            Log::error('Plugin installation failed', [
                'package_path' => $packagePath,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Uninstall a plugin
     */
    public function uninstallPlugin(string $pluginId, string $tenantId): array
    {
        $plugin = DB::table('plugins')->where('id', $pluginId)->first();
        
        if (!$plugin) {
            throw new Exception('Plugin not found');
        }

        // Check if plugin can be uninstalled
        if ($plugin->protected) {
            throw new Exception('Protected plugins cannot be uninstalled');
        }

        try {
            // Deactivate plugin first
            $this->deactivatePlugin($pluginId, $tenantId);

            // Remove plugin files
            if (File::exists($plugin->path)) {
                File::deleteDirectory($plugin->path);
            }

            // Remove from database
            DB::table('plugins')->where('id', $pluginId)->delete();

            // Remove plugin instances
            unset($this->plugins[$plugin->name]);
            unset($this->pluginInstances[$plugin->name]);

            $this->auditService->logSecurityEvent([
                'tenant_id' => $tenantId,
                'event_type' => 'plugin_uninstalled',
                'plugin_id' => $pluginId,
                'plugin_name' => $plugin->name,
            ]);

            return [
                'success' => true,
                'message' => 'Plugin uninstalled successfully',
            ];

        } catch (Exception $e) {
            Log::error('Plugin uninstallation failed', [
                'plugin_id' => $pluginId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Activate a plugin for a tenant
     */
    public function activatePlugin(string $pluginId, string $tenantId, array $configuration = []): array
    {
        $plugin = DB::table('plugins')->where('id', $pluginId)->first();
        
        if (!$plugin) {
            throw new Exception('Plugin not found');
        }

        // Check tenant permissions
        if (!$this->canTenantUsePlugin($tenantId, $plugin)) {
            throw new Exception('Tenant does not have permission to use this plugin');
        }

        // Validate configuration
        if (!empty($configuration)) {
            $this->validatePluginConfiguration($plugin, $configuration);
        }

        // Store tenant plugin configuration
        DB::table('tenant_plugins')->updateOrInsert(
            ['tenant_id' => $tenantId, 'plugin_id' => $pluginId],
            [
                'status' => 'active',
                'configuration' => json_encode($configuration),
                'activated_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        // Initialize plugin for tenant
        if (isset($this->pluginInstances[$plugin->name])) {
            $this->pluginInstances[$plugin->name]->onActivate($tenantId, $configuration);
        }

        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenantId,
            'event_type' => 'plugin_activated',
            'plugin_id' => $pluginId,
            'plugin_name' => $plugin->name,
        ]);

        return [
            'success' => true,
            'message' => 'Plugin activated successfully',
            'plugin_name' => $plugin->name,
        ];
    }

    /**
     * Deactivate a plugin for a tenant
     */
    public function deactivatePlugin(string $pluginId, string $tenantId): array
    {
        $plugin = DB::table('plugins')->where('id', $pluginId)->first();
        
        if (!$plugin) {
            throw new Exception('Plugin not found');
        }

        // Update tenant plugin status
        DB::table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('plugin_id', $pluginId)
            ->update([
                'status' => 'inactive',
                'deactivated_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        // Call plugin deactivation hook
        if (isset($this->pluginInstances[$plugin->name])) {
            $this->pluginInstances[$plugin->name]->onDeactivate($tenantId);
        }

        $this->auditService->logSecurityEvent([
            'tenant_id' => $tenantId,
            'event_type' => 'plugin_deactivated',
            'plugin_id' => $pluginId,
            'plugin_name' => $plugin->name,
        ]);

        return [
            'success' => true,
            'message' => 'Plugin deactivated successfully',
        ];
    }

    /**
     * Execute plugin method
     */
    public function executePlugin(string $tenantId, string $pluginName, string $method, array $parameters = []): array
    {
        if (!isset($this->pluginInstances[$pluginName])) {
            throw new Exception("Plugin '{$pluginName}' not found or not loaded");
        }

        // Check if plugin is active for tenant
        $tenantPlugin = DB::table('tenant_plugins')
            ->join('plugins', 'tenant_plugins.plugin_id', '=', 'plugins.id')
            ->where('tenant_plugins.tenant_id', $tenantId)
            ->where('plugins.name', $pluginName)
            ->where('tenant_plugins.status', 'active')
            ->first();

        if (!$tenantPlugin) {
            throw new Exception("Plugin '{$pluginName}' not active for tenant");
        }

        $plugin = $this->pluginInstances[$pluginName];

        // Execute within sandbox if enabled
        if ($this->config['sandbox_enabled']) {
            return $this->executeInSandbox($plugin, $method, $parameters, $tenantId);
        } else {
            return $this->executeDirectly($plugin, $method, $parameters, $tenantId);
        }
    }

    /**
     * Get available plugins for a tenant
     */
    public function getAvailablePlugins(string $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $plugins = [];

        $allPlugins = DB::table('plugins')
            ->where('status', 'active')
            ->get();

        foreach ($allPlugins as $plugin) {
            if ($this->canTenantUsePlugin($tenantId, $plugin)) {
                $tenantPlugin = DB::table('tenant_plugins')
                    ->where('tenant_id', $tenantId)
                    ->where('plugin_id', $plugin->id)
                    ->first();

                $plugins[] = [
                    'id' => $plugin->id,
                    'name' => $plugin->name,
                    'display_name' => $plugin->display_name,
                    'description' => $plugin->description,
                    'version' => $plugin->version,
                    'type' => $plugin->type,
                    'author' => $plugin->author,
                    'status' => $tenantPlugin ? $tenantPlugin->status : 'available',
                    'activated_at' => $tenantPlugin ? $tenantPlugin->activated_at : null,
                    'configuration_schema' => json_decode($plugin->configuration_schema, true),
                    'capabilities' => json_decode($plugin->capabilities, true),
                ];
            }
        }

        return $plugins;
    }

    /**
     * Get plugin execution statistics
     */
    public function getPluginStatistics(string $tenantId, string $pluginName): array
    {
        $stats = DB::table('plugin_execution_log')
            ->where('tenant_id', $tenantId)
            ->where('plugin_name', $pluginName)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw('
                COUNT(*) as total_executions,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_executions,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_executions,
                AVG(execution_time_ms) as avg_execution_time_ms,
                MAX(execution_time_ms) as max_execution_time_ms
            ')
            ->first();

        return [
            'total_executions' => $stats->total_executions ?? 0,
            'successful_executions' => $stats->successful_executions ?? 0,
            'failed_executions' => $stats->failed_executions ?? 0,
            'success_rate' => $stats->total_executions > 0 ? ($stats->successful_executions / $stats->total_executions) : 0,
            'avg_execution_time_ms' => round($stats->avg_execution_time_ms ?? 0, 2),
            'max_execution_time_ms' => $stats->max_execution_time_ms ?? 0,
        ];
    }

    /**
     * Helper methods
     */
    protected function validatePluginManifest(array $manifest): bool
    {
        $required = ['name', 'version', 'description', 'type', 'main_class', 'api_version'];
        
        foreach ($required as $field) {
            if (!isset($manifest[$field])) {
                return false;
            }
        }

        // Validate plugin type
        $allowedTypes = array_keys($this->config['types']);
        if (!in_array($manifest['type'], $allowedTypes)) {
            return false;
        }

        // Validate API version compatibility
        if (version_compare($manifest['api_version'], '1.0.0', '<')) {
            return false;
        }

        return true;
    }

    protected function registerPlugin(string $name, array $manifest, string $path): void
    {
        $this->plugins[$name] = [
            'manifest' => $manifest,
            'path' => $path,
            'loaded' => false,
        ];
    }

    protected function loadPlugin($pluginRecord): void
    {
        $manifest = json_decode($pluginRecord->manifest, true);
        $classFile = $pluginRecord->path . '/' . $manifest['main_class'] . '.php';
        
        if (!File::exists($classFile)) {
            throw new Exception("Plugin main class file not found: {$classFile}");
        }

        require_once $classFile;
        
        $className = $manifest['main_class'];
        if (!class_exists($className)) {
            throw new Exception("Plugin main class not found: {$className}");
        }

        $instance = new $className($manifest, $this->config);
        
        if (!$instance instanceof PluginInterface) {
            throw new Exception("Plugin must implement PluginInterface");
        }

        $this->pluginInstances[$pluginRecord->name] = $instance;
        
        Log::info('Plugin loaded successfully', [
            'plugin_name' => $pluginRecord->name,
            'version' => $manifest['version'],
        ]);
    }

    protected function extractPluginPackage(string $packagePath, string $extractPath): void
    {
        $zip = new ZipArchive();
        
        if ($zip->open($packagePath) !== TRUE) {
            throw new Exception('Failed to open plugin package');
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new Exception('Failed to extract plugin package');
        }

        $zip->close();
    }

    protected function isSecurePackage(string $packagePath): bool
    {
        // Basic security checks
        if (!File::exists($packagePath)) {
            return false;
        }

        // Check file size (max 50MB)
        if (File::size($packagePath) > 50 * 1024 * 1024) {
            return false;
        }

        // Check file extension
        $extension = pathinfo($packagePath, PATHINFO_EXTENSION);
        if (!in_array($extension, ['zip', 'tar', 'gz'])) {
            return false;
        }

        return true;
    }

    protected function performSecurityScan(string $pluginPath, array $manifest): void
    {
        // Scan for dangerous functions
        $dangerousFunctions = [
            'exec', 'shell_exec', 'system', 'passthru', 'eval',
            'file_get_contents', 'file_put_contents', 'fopen', 'fwrite',
            'curl_exec', 'curl_multi_exec',
        ];

        $phpFiles = File::allFiles($pluginPath);
        
        foreach ($phpFiles as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                
                foreach ($dangerousFunctions as $function) {
                    if (strpos($content, $function) !== false) {
                        Log::warning('Potentially dangerous function found in plugin', [
                            'plugin' => $manifest['name'],
                            'file' => $file->getRelativePathname(),
                            'function' => $function,
                        ]);
                    }
                }
            }
        }
    }

    protected function canTenantUsePlugin(string $tenantId, $plugin): bool
    {
        $tenant = Tenant::find($tenantId);
        
        if (!$tenant) {
            return false;
        }

        // Check tier restrictions
        $tierRestrictions = [
            'free' => ['webhook_processor'],
            'starter' => ['webhook_processor', 'data_transformer'],
            'professional' => ['webhook_processor', 'data_transformer', 'notification_channel'],
            'enterprise' => null, // All plugins available
        ];

        $allowedTypes = $tierRestrictions[$tenant->tier] ?? [];
        
        return $allowedTypes === null || in_array($plugin->type, $allowedTypes);
    }

    protected function executeInSandbox($plugin, string $method, array $parameters, string $tenantId): array
    {
        $startTime = microtime(true);
        
        try {
            // Set resource limits
            ini_set('memory_limit', $this->config['max_memory_limit']);
            set_time_limit($this->config['max_execution_time']);

            // Execute plugin method
            $result = $plugin->$method($tenantId, $parameters);
            
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            $this->logPluginExecution($tenantId, $plugin->getName(), $method, $executionTime, true);
            
            return [
                'success' => true,
                'result' => $result,
                'execution_time_ms' => $executionTime,
            ];

        } catch (Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            $this->logPluginExecution($tenantId, $plugin->getName(), $method, $executionTime, false, $e->getMessage());
            
            throw $e;
        }
    }

    protected function executeDirectly($plugin, string $method, array $parameters, string $tenantId): array
    {
        $startTime = microtime(true);
        
        try {
            $result = $plugin->$method($tenantId, $parameters);
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            $this->logPluginExecution($tenantId, $plugin->getName(), $method, $executionTime, true);
            
            return [
                'success' => true,
                'result' => $result,
                'execution_time_ms' => $executionTime,
            ];

        } catch (Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            $this->logPluginExecution($tenantId, $plugin->getName(), $method, $executionTime, false, $e->getMessage());
            
            throw $e;
        }
    }

    protected function logPluginExecution(string $tenantId, string $pluginName, string $method, int $executionTime, bool $success, string $error = null): void
    {
        DB::table('plugin_execution_log')->insert([
            'tenant_id' => $tenantId,
            'plugin_name' => $pluginName,
            'method' => $method,
            'execution_time_ms' => $executionTime,
            'success' => $success,
            'error' => $error,
            'created_at' => Carbon::now(),
        ]);
    }

    protected function registerPluginInDatabase(array $manifest, string $path, string $tenantId): string
    {
        $pluginId = bin2hex(random_bytes(16));
        
        DB::table('plugins')->insert([
            'id' => $pluginId,
            'name' => $manifest['name'],
            'display_name' => $manifest['display_name'] ?? $manifest['name'],
            'description' => $manifest['description'],
            'version' => $manifest['version'],
            'type' => $manifest['type'],
            'author' => $manifest['author'] ?? 'Unknown',
            'manifest' => json_encode($manifest),
            'path' => $path,
            'status' => 'active',
            'installed_by' => $tenantId,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $pluginId;
    }

    protected function validatePluginDependencies(array $manifest): void
    {
        if (isset($manifest['dependencies'])) {
            foreach ($manifest['dependencies'] as $dependency => $version) {
                // Check if dependency is available
                if (!$this->isDependencyAvailable($dependency, $version)) {
                    throw new Exception("Missing dependency: {$dependency} {$version}");
                }
            }
        }
    }

    protected function isDependencyAvailable(string $dependency, string $version): bool
    {
        // Check system dependencies, PHP extensions, etc.
        // For now, just return true
        return true;
    }

    protected function validatePluginConfiguration($plugin, array $configuration): bool
    {
        $schema = json_decode($plugin->configuration_schema, true);
        
        if (!$schema) {
            return true; // No schema to validate against
        }

        // Basic validation - in production, use a proper JSON schema validator
        foreach ($schema['properties'] ?? [] as $property => $rules) {
            if (isset($rules['required']) && $rules['required'] && !isset($configuration[$property])) {
                throw new Exception("Required configuration property missing: {$property}");
            }
        }

        return true;
    }
}