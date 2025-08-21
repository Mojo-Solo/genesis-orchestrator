<?php

namespace App\Http\Controllers\ExitPlanning;

use App\Http\Controllers\Controller;
use App\Services\ExitPlanning\AssessmentScoring;
use App\Services\ExitPlanning\ExitPredictor;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(
        private AssessmentScoring $assessmentScoring,
        private ExitPredictor $exitPredictor
    ) {}

    public function calculateScore(Request $request)
    {
        $request->validate([
            'personal_readiness' => 'required|numeric|between:0,1',
            'financial_readiness' => 'required|numeric|between:0,1', 
            'business_readiness' => 'required|numeric|between:0,1',
            'base_value' => 'nullable|integer|min:0',
            'base_time_months' => 'nullable|integer|min:1'
        ]);

        $result = $this->assessmentScoring->calculateReadinessScore(
            $request->personal_readiness,
            $request->financial_readiness,
            $request->business_readiness,
            $request->base_value ?? 1000000,
            $request->base_time_months ?? 24
        );

        return response()->json([
            'success' => true,
            'assessment' => $result
        ]);
    }

    public function predictSuccess(Request $request)
    {
        $request->validate([
            'factors' => 'required|array',
            'factors.readiness_score' => 'required|numeric|between:0,1',
            'factors.market_conditions' => 'required|numeric|between:0,1',
            'factors.value_drivers' => 'required|numeric|between:0,1'
        ]);

        $prediction = $this->exitPredictor->predictExitSuccess($request->factors);

        return response()->json([
            'success' => true,
            'prediction' => $prediction
        ]);
    }
}
