<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\GoalReview;
use App\Services\AiPlanGenerator;
use App\Services\EventGenerator;
use App\Services\GoalCompletionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
    public function getGoals(GoalCompletionService $completionService)
    {
        $goals = Auth::user()->goals()->with(['events', 'events.feedback'])->get();
        $payload = $goals->map(function (Goal $goal) use ($completionService) {
            return $this->formatGoalWithCompletion($goal, $completionService);
        });

        return response()->json([
            'goals' => $payload,
        ]);
    }

    /**
     * Get the all the event feedback for a goal
     *
     * @param Goal $goal
     * @return JsonResponse
     */
    public function getGoalEventFeedback(Goal $goal)
    {
        $eventFeedback = []; 
        foreach($goal->events as $event){
            if($event->feedback()->exists()){
                $eventFeedback[$event->title] = $event->feedback;
            }
        }

        return response()->json([
            'feedback' => collect($eventFeedback)
        ]); 
    }

    /**
     * Store a new goal
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeGoal(Request $request, GoalCompletionService $completionService)
    {
        $request->validate([
            'title' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'end_date' => 'required|date|after:today',
        ]);

        //@todo: fix this to use the actual submitted date
        $newGoal = Goal::create([
            'title' => $request->title,
            'description' => $request->description,
            'end_date' =>Carbon::now()->addYear(),
            'start_date' => Carbon::now(),
            'status' => 'active',
            'user_id' => Auth::user()->id,
            'category' => 'User Created',
        ]);

        return response()->json([
            'goal' => $this->formatGoalWithCompletion($newGoal, $completionService),
        ]); 
    }

    public function completeGoal(Goal $goal, GoalCompletionService $completionService)
    {
        if ($goal->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $computed = $completionService->compute($goal);
        if (!$computed['is_completable']) {
            return response()->json([
                'message' => 'Goal is not completable yet.',
                'reasons' => $computed['completion_reasons'],
            ], 422);
        }

        $goal->update([
            'status' => 'completed',
            'completed_at' => Carbon::now(),
            'completion_reason' => empty($computed['completion_reasons'])
                ? null
                : implode(',', $computed['completion_reasons']),
        ]);

        return response()->json([
            'goal' => $this->formatGoalWithCompletion($goal->fresh(['events', 'events.feedback']), $completionService),
        ]);
    }

    public function createGoalReview(Request $request, Goal $goal, GoalCompletionService $completionService)
    {
        if ($goal->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($goal->status !== 'completed' && $goal->completed_at === null) {
            return response()->json(['message' => 'Goal must be completed first.'], 422);
        }

        if ($goal->review()->exists()) {
            return response()->json(['message' => 'Review already exists.'], 409);
        }

        $feelingsWhitelist = [
            'happy',
            'good',
            'meh',
            'frustrated',
            'proud',
            'relieved',
            'disappointed',
            'burnt_out',
            'motivated',
        ];

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['achieved', 'partially_achieved', 'not_achieved'])],
            'feelings' => ['required', 'array'],
            'feelings.*' => ['string', Rule::in($feelingsWhitelist)],
            'why' => 'required|string|max:5000',
            'wins' => 'required|string|max:5000',
            'obstacles' => 'required|string|max:5000',
            'lessons' => 'required|string|max:5000',
            'next_steps' => 'required|string|max:5000',
            'advice' => 'nullable|string|max:5000',
        ]);

        $computed = $completionService->compute($goal);
        $statsSnapshot = [
            'points_earned' => $computed['points_earned'],
            'max_possible_points' => $computed['max_possible_points'],
            'threshold_points' => $computed['threshold_points'],
            'completion_reasons' => $computed['completion_reasons'],
            'status_counts' => $computed['status_counts'],
            'mood_counts' => $computed['mood_counts'],
        ];

        $review = GoalReview::create([
            'goal_id' => $goal->id,
            'user_id' => Auth::id(),
            'outcome' => $validated['outcome'],
            'feelings' => $validated['feelings'],
            'why' => $validated['why'],
            'wins' => $validated['wins'],
            'obstacles' => $validated['obstacles'],
            'lessons' => $validated['lessons'],
            'next_steps' => $validated['next_steps'],
            'advice' => $validated['advice'] ?? null,
            'stats_snapshot' => $statsSnapshot,
        ]);

        return response()->json([
            'review' => $review,
        ], 201);
    }

    public function getGoalReview(Goal $goal)
    {
        if ($goal->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $review = $goal->review;
        if (!$review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        return response()->json([
            'review' => $review,
        ]);
    }

    public function getCompletedGoals(GoalCompletionService $completionService)
    {
        $goals = Auth::user()->goals()
            ->where('status', 'completed')
            ->with(['events', 'events.feedback', 'review'])
            ->orderByDesc('completed_at')
            ->get();

        $payload = $goals->map(function (Goal $goal) use ($completionService) {
            $formatted = $this->formatGoalWithCompletion($goal, $completionService);
            $formatted['review_summary'] = $goal->review
                ? [
                    'outcome' => $goal->review->outcome,
                    'completed_at' => $goal->completed_at,
                ]
                : null;
            return $formatted;
        });

        return response()->json([
            'goals' => $payload,
        ]);
    }

    private function formatGoalWithCompletion(Goal $goal, GoalCompletionService $completionService): array
    {
        $computed = $completionService->compute($goal);
        $payload = $goal->toArray();
        $payload['points_earned'] = $computed['points_earned'];
        $payload['max_possible_points'] = $computed['max_possible_points'];
        $payload['threshold_points'] = $computed['threshold_points'];
        $payload['is_completable'] = $computed['is_completable'];
        $payload['completion_reasons'] = $computed['completion_reasons'];

        return $payload;
    }
}
