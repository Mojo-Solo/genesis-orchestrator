<?php

namespace App\Domains\Orchestration\Exceptions;

/**
 * Orchestration Exception
 * 
 * Custom exception class for orchestration domain errors.
 * Provides structured error handling for the LAG/RCR orchestration pipeline.
 */
class OrchestrationException extends \Exception
{
    /**
     * Error context data
     */
    private array $context;
    
    /**
     * Error classification
     */
    private string $errorType;
    
    public function __construct(
        string $message = "", 
        int $code = 0, 
        ?\Throwable $previous = null,
        array $context = [],
        string $errorType = 'general'
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->context = $context;
        $this->errorType = $errorType;
    }
    
    /**
     * Get error context
     */
    public function getContext(): array
    {
        return $this->context;
    }
    
    /**
     * Get error type
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }
    
    /**
     * Create LAG processing error
     */
    public static function lagProcessingError(string $message, array $context = []): self
    {
        return new self($message, 1001, null, $context, 'lag_processing');
    }
    
    /**
     * Create RCR routing error
     */
    public static function rcrRoutingError(string $message, array $context = []): self
    {
        return new self($message, 1002, null, $context, 'rcr_routing');
    }
    
    /**
     * Create workflow execution error
     */
    public static function workflowExecutionError(string $message, array $context = []): self
    {
        return new self($message, 1003, null, $context, 'workflow_execution');
    }
    
    /**
     * Create configuration error
     */
    public static function configurationError(string $message, array $context = []): self
    {
        return new self($message, 1004, null, $context, 'configuration');
    }
    
    /**
     * Create circuit breaker error
     */
    public static function circuitBreakerError(string $message, array $context = []): self
    {
        return new self($message, 1005, null, $context, 'circuit_breaker');
    }
}