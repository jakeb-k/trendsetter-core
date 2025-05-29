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
        $messages = [
            [
                'role' => 'system',
                'content' => <<<EOT
You are an AI coach that builds detailed, step-by-step, time-based plans for users to reach their goals. If you don't have enough information to make a plan, ask concise, relevant questions to collect what you need. If you do have enough, return a structured timeline with tasks.

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
      "due_date": "2025-06-01"
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
