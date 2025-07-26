<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

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
        //
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
