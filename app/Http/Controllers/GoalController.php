<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Services\AiPlanGenerator;
use App\Services\EventGenerator;
use Carbon\Carbon;
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
    
    /**
     * Get the all the event feedback for a goal
     *
     * @param Goal $goal
     * @return JsonResponse
     */
    public function storeGoal(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'end_date' => 'required|date|after:today',
        ]);

        $newGoal = Goal::create([
            'title' => $request->title,
            'description' => $request->description,
            'end_date' => $request->end_date,
            'start_date' => Carbon::now(),
            'status' => 'active',
            'category' => 'User Created',
        ]);

        return response()->json([
            'goal' => $newGoal
        ]); 
    }
}
