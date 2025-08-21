<?php

namespace App\Services\ExitPlanning;

class AssessmentScoring
{
    // Three-dimensional readiness scoring algorithm
    public function calculateReadinessScore(
        float $personalReadiness,
        float $financialReadiness, 
        float $businessReadiness,
        int $baseValue = 1000000,
        int $baseTimeMonths = 24
    ): array {
        $weights = [
            'personal' => 0.25,
            'financial' => 0.35,
            'business' => 0.40
        ];

        $weightedScore = (
            $personalReadiness * $weights['personal'] +
            $financialReadiness * $weights['financial'] +
            $businessReadiness * $weights['business']
        );

        $timeToExit = $this->calculateTimeToExit($weightedScore, $baseTimeMonths);
        $valuationMultiplier = $this->calculateValuationImpact($weightedScore);
        $projectedValue = $baseValue * $valuationMultiplier;

        return [
            'overall_score' => round($weightedScore, 2),
            'dimension_scores' => [
                'personal' => $personalReadiness,
                'financial' => $financialReadiness,
                'business' => $businessReadiness
            ],
            'weights' => $weights,
            'time_to_exit_months' => $timeToExit,
            'valuation_multiplier' => $valuationMultiplier,
            'projected_value' => $projectedValue,
            'readiness_level' => $this->getReadinessLevel($weightedScore)
        ];
    }

    private function calculateTimeToExit(float $score, int $baseMonths): int
    {
        // Higher scores = shorter time to exit
        $adjustment = (1.0 - $score) * $baseMonths;
        return max(6, intval($baseMonths + $adjustment));
    }

    private function calculateValuationImpact(float $score): float
    {
        // Score impacts valuation multiplier
        $baseMultiplier = 1.0;
        $maxBonus = 0.35; // Up to 35% value increase
        
        return $baseMultiplier + ($score * $maxBonus);
    }

    private function getReadinessLevel(float $score): string
    {
        if ($score >= 0.85) return 'Highly Ready';
        if ($score >= 0.70) return 'Moderately Ready';  
        if ($score >= 0.55) return 'Developing Readiness';
        return 'Early Stage';
    }
}
