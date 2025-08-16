<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventFeedback;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventController extends Controller
{
    public function getEventFeedback(Event $event)
    {
        //@todo Implement logic that gets the current streak count
        return [
            'feedback' => $event->feedback()->orderBy('created_at', 'desc')->get(),
        ];
    }

    public function storeEventFeedback(Request $request, Event $event)
    {
        $request->validate([
            'note' => 'required|string|max:255',
            'status' => 'required|in:completed,skipped,partial,struggled,nailed_it',
            'mood' => 'required|in:happy,meh,frustrated',
        ]);

        $newEventFeedback = EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => Auth::user()->id,
            'note' => $request->note,
            'status' => $request->status,
            'mood' => $request->mood,
        ]);

        return response()->json($newEventFeedback, 201);
    }

    public function updateEventFeedback(Request $request, Event $event)
    {
        //
    }

    public function deleteEventFeedback(Event $event)
    {
        //
    }
}
