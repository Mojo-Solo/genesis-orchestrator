<?php

namespace App\Http\Controllers;

use App\Services\AssessmentScoring;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(private AssessmentScoring $scoring) {}

    public function calculateScore(Request $request)
    {
        $request->validate([
            'personal_readiness' => 'required|numeric|between:0,1',
            'financial_readiness' => 'required|numeric|between:0,1', 
            'business_readiness' => 'required|numeric|between:0,1'
        ]);

        $result = $this->scoring->calculateReadinessScore(
            $request->personal_readiness,
            $request->financial_readiness,
            $request->business_readiness
        );

        return response()->json(['success' => true, 'assessment' => $result]);
    }
}
