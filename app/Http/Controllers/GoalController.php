<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AiPlanGenerator;
use App\Services\EventGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GoalController extends Controller
{
    /**
     * Generate a detailed plan for a user's goal using AI.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generatePlan(Request $request, AiPlanGenerator $aiPlanGenerator, EventGenerator $eventGenerator)
    {
        $request->validate([
            'goal_description' => 'required|string',
            'context' => 'array',
        ]);

        $aiResponse = $aiPlanGenerator->generatePlan($request->goal_description, $request->context ?? []);
        \Log::info($aiResponse);

        $decodedResponse = json_decode($aiResponse, true);
        if ($decodedResponse['finished']) {
            $goalAndPlan = $aiPlanGenerator->storePlanAndGoal($decodedResponse, $request->context ?? []);
            $events = $eventGenerator->createEvents($decodedResponse['events'], $goalAndPlan);

            return response()->json([
                'goal' => $goalAndPlan['goal'],
                'ai_plan' => $goalAndPlan['ai_plan'],
                'events' => $events,
                'finished' => true, 
            ]);
        } else {
            return response()->json([
                'message' => $decodedResponse['message'],
            ]);
        }
    }

    /**
     * Fetch the users goals
     *
     * @return JsonResponse
     */
    public function getGoals()
    {
        return response()->json([
            'goals' => Auth::user()->goals,
        ]);
    }

}
