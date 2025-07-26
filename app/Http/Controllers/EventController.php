<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function getEventFeedback(Event $event)
    {
        //@todo Implement logic that gets the current streak count
        return [
            'feedback' => $event->feedback(),
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
