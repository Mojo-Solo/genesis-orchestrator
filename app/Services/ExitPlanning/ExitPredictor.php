<?php

namespace App\Services\ExitPlanning;

class ExitPredictor
{
    public function predictExitSuccess(array $factors): array
    {
        $weights = [
            'readiness_score' => 0.30,
            'market_conditions' => 0.25,
            'value_drivers' => 0.20,
            'buyer_fit' => 0.15,
            'risk_factors' => 0.10
        ];

        $successProbability = 0;
        foreach ($factors as $factor => $value) {
            if (isset($weights[$factor])) {
                $successProbability += $value * $weights[$factor];
            }
        }

        return [
            'success_probability' => round($successProbability * 100, 1),
            'risk_level' => $this->calculateRiskLevel($successProbability),
            'key_factors' => $this->identifyKeyFactors($factors),
            'recommendations' => $this->generateRecommendations($successProbability, $factors)
        ];
    }

    private function calculateRiskLevel(float $probability): string
    {
        if ($probability >= 0.8) return 'Low Risk';
        if ($probability >= 0.6) return 'Medium Risk';
        return 'High Risk';
    }

    private function identifyKeyFactors(array $factors): array
    {
        arsort($factors);
        return array_slice($factors, 0, 3, true);
    }

    private function generateRecommendations(float $probability, array $factors): array
    {
        $recommendations = [];
        
        if ($probability < 0.6) {
            $recommendations[] = "Focus on improving readiness score before proceeding";
        }
        
        if (isset($factors['value_drivers']) && $factors['value_drivers'] < 0.7) {
            $recommendations[] = "Strengthen value drivers to maximize exit value";
        }

        if (isset($factors['market_conditions']) && $factors['market_conditions'] < 0.5) {
            $recommendations[] = "Consider waiting for better market conditions";
        }

        return $recommendations;
    }
}
