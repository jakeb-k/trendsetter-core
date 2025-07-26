<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventFeedback;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class EventFeedbackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $events = Event::all();
        foreach ($events as $event) {
            EventFeedback::factory(5)
                ->make([
                    'event_id' => $event->id,
                    'user_id' => $event->goal->user_id,
                ])
                ->toArray();
        }
    }
}
