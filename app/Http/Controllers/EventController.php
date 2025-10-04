<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventFeedback;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function getEventFeedback(Event $event)
    {
        //@todo Implement logic that gets the current streak count
        return [
            'feedback' => $event->feedback()->orderBy('created_at', 'desc')->get(),
        ];
    }

    /**
     * Storing new events
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeEvent(Request $request)
    {
        $request->validate([
            'goal_id' => 'required|numeric|exists:goals,id',
            'title' => 'required|string|max:50',
            'description' => 'nullable|string|max:255',
            'frequency' => ['required', Rule::in(['weekly', 'monthly'])],
            'times_per_week' => 'required|numeric|gt:0|max:7',
            'duration_in_weeks' => 'required|numeric|gt:0',
            'start_date' => 'nullable|date|after_or_equal:today',
        ]);

        $startDate = is_null($request->start_date) ? Carbon::today()->toDateString() : Carbon::parse($request->start_date)->toDateString();

        $repeat = [
            'frequency' => $request->frequency,
            'times_per_week' => $request->times_per_week,
            'duration_in_weeks' => $request->duration_in_weeks,
        ];
        // @TODO: add a legit points system in here
        $newEvent = Event::create([
            'goal_id' => $request->goal_id,
            'title' => $request->title,
            'description' => $request->description,
            'repeat' => $repeat,
            'scheduled_for' => $startDate,
            'points' => 0,
        ]);

        return response()->json($newEvent);
    }

    /**
     * Stores or updates event feedback for the current day
     *
     * @param Request $request
     * @param Event $event
     * @return JsonResponse
     */
    public function storeEventFeedback(Request $request, Event $event)
    {
        $request->validate([
            'note' => 'required|string|max:255',
            'status' => 'required|in:completed,skipped,partial,struggled,nailed_it',
            'mood' => 'required|in:happy,meh,good,frustrated',
        ]);

        $eventFeedback = EventFeedback::where('user_id', Auth::id())
            ->where('event_id', $event->id)
            ->whereDate('created_at', Carbon::today())
            ->first();

        $payload = [
            'event_id' => $event->id,
            'user_id' => Auth::id(),
            'note' => $request->note,
            'status' => $request->status,
            'mood' => $request->mood,
        ];

        if ($eventFeedback) {
            $eventFeedback->update($payload);
            $statusCode = 200;
        } else {
            $eventFeedback = EventFeedback::create($payload);
            $statusCode = 201;
        }

        return response()->json($eventFeedback, $statusCode);
    }
}
