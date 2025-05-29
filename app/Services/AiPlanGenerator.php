<?php

namespace App\Services;

use OpenAI;

class AiPlanGenerator
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(config('services.openai.api_key'));
    }

    public function generatePlan(string $goalDescription, array $context = [])
    {
        $today = now()->toDateString(); 
        $messages = [
            [
                'role' => 'system',
                'content' => <<<EOT
You are an AI coach that builds detailed, step-by-step, time-based plans for users to reach their goals. If you don't have enough information to make a plan, ask concise, relevant questions to collect what you need. If you do have enough, return a structured timeline with tasks.

Once enough information is collected each event must:
- Be highly specific and immediately actionable
- Include how to perform the step (e.g., what tools, environments, or platforms to use)
- Avoid generalities like “reflect”, “improve”, “build” unless paired with specific tasks
- Each step must include a due_date starting from TODAY'S DATE (currently: $today), spaced realistically forward over time depending on the goal's complexity or the duration the user has specified.
- Be adaptable to any type of goal (fitness, business, education, personal growth, etc.)

For habits or routines that repeat (e.g., workouts, journaling), include a "repeat" object with:
  - frequency (e.g., "weekly")
  - times_per_week (e.g., 3)
  - duration_in_weeks (e.g., 16)

Only one event should be created for the routine, with repeat metadata. Do NOT duplicate repeating events.

Your output must be one of the following valid JSON formats:

1. If more information is needed:
{
  "finished": false,
  "message": "What additional context do you need?"
}

2. If enough information is provided:
{
  "finished": true,
  "events": [
    {
      "title": "Step 1 name",
      "description": "Detailed step",
      "due_date": "2025-06-01",
      "repeat": {
          "frequency": "weekly",
          "times_per_week": 3,
          "duration_in_weeks": 16
      }
    }
  ]
}

DO NOT include any extra commentary, markdown, or explanations. Return only valid JSON.
EOT
            ],
            [
                'role' => 'user',
                'content' => "Goal: {$goalDescription}" . (!empty($context) ? "\n\nContext: " . json_encode($context) : ''),
            ],
        ];

        $response = $this->client->chat()->create([
            'model' => 'gpt-4o',
            'messages' => $messages,
        ]);

        return $response['choices'][0]['message']['content'] ?? [];
    }
}
