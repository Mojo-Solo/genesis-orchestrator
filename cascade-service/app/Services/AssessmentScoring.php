<?php

namespace App\Services;

class AssessmentScoring
{
    // Three-dimensional business readiness scoring
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

        return [
            'overall_score' => round($weightedScore, 2),
            'dimension_scores' => [
                'personal' => $personalReadiness,
                'financial' => $financialReadiness,
                'business' => $businessReadiness
            ],
            'weights' => $weights,
            'time_to_transition_months' => $this->calculateTimeToTransition($weightedScore, $baseTimeMonths),
            'valuation_multiplier' => $this->calculateValuationImpact($weightedScore),
            'projected_value' => $baseValue * $this->calculateValuationImpact($weightedScore),
            'readiness_level' => $this->getReadinessLevel($weightedScore)
        ];
    }

    private function calculateTimeToTransition(float $score, int $baseMonths): int
    {
        $adjustment = (1.0 - $score) * $baseMonths;
        return max(6, intval($baseMonths + $adjustment));
    }

    private function calculateValuationImpact(float $score): float
    {
        return 1.0 + ($score * 0.35);
    }

    private function getReadinessLevel(float $score): string
    {
        if ($score >= 0.85) return 'Highly Ready';
        if ($score >= 0.70) return 'Moderately Ready';  
        if ($score >= 0.55) return 'Developing Readiness';
        return 'Early Stage';
    }
}
