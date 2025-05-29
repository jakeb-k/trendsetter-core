<?php

namespace App\Services;

use App\Models\Event;
use Carbon\Carbon;

class EventGenerator
{
    // public function createEvents($eventContent, $goalAndPlan)
    // {
    //     $events = collect();
    //     foreach ($eventContent as $event) {
    //         if (isset($event['repeat'])) {
    //             $timesPerWeek = $event['repeat']['times_per_week'];
    //             $durationWeeks = $event['repeat']['duration_in_weeks'];
    //             $totalEvents = $timesPerWeek * $durationWeeks;

    //             $startDate = Carbon::parse($event['due_date']);

    //             for ($i = 0; $i < $totalEvents; $i++) {
    //                 $weekIndex = floor($i / $timesPerWeek);
    //                 $dayOffset = $i % $timesPerWeek;
    //                 $daysBetween = floor(7 / $timesPerWeek) * $dayOffset;

    //                 $eventDate = $startDate->copy()->addWeeks($weekIndex)->addDays($daysBetween);

    //                 $newEvent = Event::create([
    //                     'ai_plan_id' => $goalAndPlan['ai_plan']->id,
    //                     'goal_id' => $goalAndPlan['goal']->id,
    //                     'title' => $event['title'],
    //                     'description' => $event['description'],
    //                     'scheduled_for' => $eventDate,
    //                     'points' => 0,
    //                 ]);

    //                 $events->push($newEvent);
    //             }
    //         } else {
    //             $newEvent = Event::create([
    //                 'ai_plan_id' => $goalAndPlan['ai_plan']->id,
    //                 'goal_id' => $goalAndPlan['goal']->id,
    //                 'title' => $event['title'],
    //                 'description' => $event['description'],
    //                 'scheduled_for' => $event['due_date'],
    //                 'points' => 0, // Default points, can be adjusted later
    //             ]);
    //             $events->push($newEvent);
    //         }
    //     }
    //     return $events; 
    // }


    public function createEvents($eventContent, $goalAndPlan)
    {
        $events = collect();
        foreach ($eventContent as $event) {
            $newEvent = Event::create([
                'ai_plan_id' => $goalAndPlan['ai_plan']->id,
                'goal_id' => $goalAndPlan['goal']->id,
                'title' => $event['title'],
                'description' => $event['description'],
                'scheduled_for' => $event['due_date'],
                'points' => 0, // Default points, can be adjusted later
                'repeat' => isset($event['repeat']) ? $event['repeat'] : null,
            ]);
            $events->push($newEvent);
        }
        return $events; 
    }
}
