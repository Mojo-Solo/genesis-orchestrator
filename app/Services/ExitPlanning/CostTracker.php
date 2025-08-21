<?php

namespace App\Services\ExitPlanning;

class CostTracker
{
    private array $modelPricing = [
        'openai' => [
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
            'gpt-3.5-turbo' => ['input' => 0.001, 'output' => 0.002]
        ],
        'anthropic' => [
            'claude-3-5-sonnet' => ['input' => 0.003, 'output' => 0.015],
            'claude-3-haiku' => ['input' => 0.00025, 'output' => 0.00125]
        ]
    ];

    public function calculateCost(string $provider, string $model, int $inputTokens, int $outputTokens): array
    {
        $pricing = $this->modelPricing[$provider][$model] ?? null;
        
        if (!$pricing) {
            throw new \InvalidArgumentException("Unknown model: $provider/$model");
        }

        $inputCost = ($inputTokens / 1000) * $pricing['input'];
        $outputCost = ($outputTokens / 1000) * $pricing['output'];
        $totalCost = $inputCost + $outputCost;

        return [
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'input_cost' => round($inputCost, 6),
            'output_cost' => round($outputCost, 6),
            'total_cost' => round($totalCost, 6)
        ];
    }

    public function getOptimalModel(int $estimatedTokens, string $priority = 'balanced'): array
    {
        $recommendations = [];
        
        foreach ($this->modelPricing as $provider => $models) {
            foreach ($models as $model => $pricing) {
                $estimatedCost = ($estimatedTokens / 1000) * ($pricing['input'] + $pricing['output']) / 2;
                
                $recommendations[] = [
                    'provider' => $provider,
                    'model' => $model,
                    'estimated_cost' => $estimatedCost,
                    'quality_tier' => $this->getQualityTier($model),
                    'speed_tier' => $this->getSpeedTier($model)
                ];
            }
        }

        usort($recommendations, function($a, $b) use ($priority) {
            return match($priority) {
                'cost' => $a['estimated_cost'] <=> $b['estimated_cost'],
                'quality' => $b['quality_tier'] <=> $a['quality_tier'],
                'speed' => $b['speed_tier'] <=> $a['speed_tier'],
                default => $a['estimated_cost'] <=> $b['estimated_cost']
            };
        });

        return $recommendations[0];
    }

    private function getQualityTier(string $model): int
    {
        return match(true) {
            str_contains($model, 'gpt-4') => 5,
            str_contains($model, 'claude-3-5') => 5,
            str_contains($model, 'claude-3') => 4,
            str_contains($model, 'gpt-3.5') => 3,
            default => 2
        };
    }

    private function getSpeedTier(string $model): int
    {
        return match(true) {
            str_contains($model, 'turbo') => 5,
            str_contains($model, 'haiku') => 5,
            str_contains($model, 'gpt-3.5') => 4,
            default => 3
        };
    }
}
