<?php

namespace App\Http\Controllers;

use App\Services\AiPlanGenerator;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    /**
     * Generate a detailed plan for a user's goal using AI.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generatePlan(Request $request)
    {
        $request->validate([
            'goal_description' => 'required|string',
            'context' => 'array',
        ]);
        $aiPlanGenerator = new AiPlanGenerator();
        $aiResponse = $aiPlanGenerator->generatePlan($request->goal_description, $request->context ?? []);
        \Log::info($aiResponse); 
        $decodedResponse = json_decode($aiResponse, true);
        if($decodedResponse['finished']){
            $events = $this->createEvents($decodedResponse['events']); 
        } else {
            return response()->json([
                'message' => $decodedResponse['message'],
            ]); 
        }
    }

    public function createEvents($eventContent)
    {
        // using the event content, which is array of events, generate the events and then return them to be sent back    
    }
}
