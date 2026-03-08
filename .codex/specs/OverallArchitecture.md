# Trendsetter-Core - Laravel API

## Project Overview

Trendsetter-Core is the Laravel backend API for the Trendsetter goal-tracking application. It provides RESTful endpoints for authentication, goal management, event scheduling, feedback tracking, and AI-powered planning.

## Tech Stack

- **Framework**: Laravel 12.0
- **PHP Version**: 8.2+
- **Database**: SQLite (configurable)
- **Authentication**: Laravel Sanctum (token-based)
- **AI Integration**: OpenAI GPT-4o via openai-php/client
- **Frontend**: Inertia.js with React (admin dashboard)

## Project Structure

```
trendsetter-core/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/              # Authentication controllers
│   │   │   ├── Settings/          # User settings controllers
│   │   │   ├── GoalController.php # Goals & AI planning
│   │   │   └── EventController.php# Events & feedback
│   │   ├── Middleware/
│   │   │   ├── HandleInertiaRequests.php
│   │   │   └── HandleAppearance.php
│   │   └── Requests/
│   │       └── Auth/              # Form requests
│   ├── Models/
│   │   ├── User.php
│   │   ├── Goal.php
│   │   ├── AiPlan.php
│   │   ├── Event.php
│   │   ├── EventFeedback.php
│   │   └── Image.php
│   ├── Services/
│   │   ├── AiPlanGenerator.php    # OpenAI integration
│   │   └── EventGenerator.php     # Event creation from AI
│   └── Providers/
├── routes/
│   ├── api.php                    # API routes (v1)
│   ├── web.php                    # Web routes
│   ├── auth.php                   # Auth routes
│   └── settings.php               # Settings routes
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── config/
└── resources/
    └── js/                        # React frontend (Inertia)
```

## Database Schema

### Models & Relationships

```
User
├── hasMany Goals
├── hasMany EventFeedback
└── morphMany Images

Goal
├── belongsTo User
├── hasMany AiPlans
└── hasMany Events

AiPlan
├── belongsTo Goal
└── hasMany Events

Event
├── belongsTo Goal
├── belongsTo AiPlan
└── hasMany EventFeedback (as 'feedback')

EventFeedback
├── belongsTo User
├── belongsTo Event
└── morphMany Images

Image
└── morphTo imageable (User, EventFeedback)
```

### Key Tables

**goals**
- id, user_id, title, description, category, status, start_date, end_date, timestamps
- Status enum: `active`, `completed`, `abandoned`, `paused`, `stalled`, `needs_review`

**events**
- id, ai_plan_id, goal_id, title, description, repeat (json), scheduled_for, completed_at, points, timestamps

**event_feedback**
- id, event_id, user_id, note, status, mood, timestamps
- Status enum: `completed`, `skipped`, `partial`, `struggled`, `nailed_it`
- Mood: `happy`, `meh`, `good`, `frustrated`

## Conventions

### Controller Pattern

```php
<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $goals = $request->user()->goals()->with('events')->get();

        return response()->json([
            'goals' => $goals,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'end_date' => 'required|date|after:today',
        ]);

        $goal = $request->user()->goals()->create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'end_date' => $validated['end_date'],
            'start_date' => now(),
            'status' => 'active',
            'category' => 'User Created',
        ]);

        return response()->json(['goal' => $goal], 201);
    }
}
```

### Model Pattern

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function aiPlans(): HasMany
    {
        return $this->hasMany(AiPlan::class);
    }
}
```

### Service Pattern

```php
<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\AiPlan;
use OpenAI\Laravel\Facades\OpenAI;

class AiPlanGenerator
{
    public function generatePlan(string $goalDescription, array $context = []): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => $this->buildMessages($goalDescription, $context),
        ]);

        return json_decode($response->choices[0]->message->content, true);
    }

    public function storePlanAndGoal(array $aiResponse, array $context): array
    {
        $goal = Goal::create([
            'user_id' => auth()->id(),
            'title' => $aiResponse['goal']['title'],
            'description' => $aiResponse['goal']['description'],
            'category' => $aiResponse['goal']['category'],
            'start_date' => now(),
            'end_date' => $aiResponse['goal']['end_date'],
            'status' => 'active',
        ]);

        $aiPlan = AiPlan::create([
            'goal_id' => $goal->id,
            'version' => 1,
            'prompt_log' => $context,
        ]);

        return ['goal' => $goal, 'ai_plan' => $aiPlan];
    }
}
```

### Migration Pattern

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('category');
            $table->enum('status', ['active', 'completed', 'abandoned', 'paused', 'stalled', 'needs_review']);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
```

### Form Request Pattern

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            'end_date' => 'required|date|after:today',
        ];
    }
}
```

## API Routes

All API routes are prefixed with `/api/v1`.

### Public Routes

```php
Route::post('/auth/login', [AuthenticatedSessionController::class, 'storeApi']);
```

### Protected Routes (auth:sanctum)

```php
// Goals
Route::get('/goals', [GoalController::class, 'getGoals']);
Route::post('/goals', [GoalController::class, 'storeGoal']);
Route::get('/goals/{goal}/feedback', [GoalController::class, 'getGoalEventFeedback']);

// Events
Route::post('/events', [EventController::class, 'storeEvent']);
Route::get('/events/{event}/feedback', [EventController::class, 'getEventFeedback']);
Route::post('/events/{event}/feedback', [EventController::class, 'storeEventFeedback']);
Route::put('/events/{event}/feedback', [EventController::class, 'updateEventFeedback']);
Route::delete('/events/{event}/feedback', [EventController::class, 'deleteEventFeedback']);

// AI Planning
Route::post('/ai-plan/chat', [GoalController::class, 'generatePlan']);
```

## Authentication

### Sanctum Token Authentication

```php
// Login response (AuthenticatedSessionController@storeApi)
public function storeApi(LoginRequest $request): JsonResponse
{
    $request->authenticate();

    $user = Auth::user();
    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'goals' => $user->goals()->with('events')->get(),
        'token' => $token,
    ]);
}
```

### Protecting Routes

```php
// In routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    // Protected routes here
});
```

## Event Repeat Structure

Events can have recurring schedules stored as JSON:

```php
// In Event model
protected $casts = [
    'repeat' => 'json',
];

// Repeat structure
[
    'frequency' => 'weekly',        // daily, weekly, monthly
    'times_per_week' => 3,          // optional
    'duration_in_weeks' => 16,      // how long the event repeats
]
```

## AI Integration

### OpenAI Configuration

```php
// config/services.php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
],
```

### AI Response Structure

```php
// Expected AI response for plan generation
[
    'finished' => true,
    'message' => 'Your plan is ready!',
    'goal' => [
        'title' => 'Learn Spanish',
        'description' => '...',
        'category' => 'Learning',
        'end_date' => '2025-06-01',
    ],
    'events' => [
        [
            'title' => 'Daily vocabulary practice',
            'description' => '...',
            'due_date' => '2025-01-15',
            'repeat' => [
                'frequency' => 'daily',
                'duration_in_weeks' => 16,
            ],
        ],
    ],
]
```

## Adding New Features

### New Migration

```bash
php artisan make:migration create_table_name_table
```

### New Model

```bash
php artisan make:model ModelName
```

Place in `app/Models/`, add relationships, casts, and guarded/fillable.

### New Controller

```bash
php artisan make:controller ControllerName
```

Place in `app/Http/Controllers/`. For API controllers, return `JsonResponse`.

### New Service

1. Create in `app/Services/`
2. Inject via constructor or use dependency injection
3. Register in `AppServiceProvider` if needed

### New API Route

1. Add to `routes/api.php`
2. Use `Route::middleware('auth:sanctum')` for protected routes
3. Group related routes together

### New Form Request

```bash
php artisan make:request RequestName
```

Place in `app/Http/Requests/`.

## Testing

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/GoalTest.php
```

## Common Commands

```bash
# Migrations
php artisan migrate
php artisan migrate:fresh --seed

# Cache
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Tinker
php artisan tinker

# Queue
php artisan queue:listen

# Code formatting
./vendor/bin/pint
```

## Environment Variables

Key `.env` variables:

```env
APP_NAME=TrendsetterCore
APP_URL=https://trendsetter-core.test

DB_CONNECTION=sqlite

SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:8000,localhost:8081

OPENAI_API_KEY=sk-proj-...

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

## Error Handling

Return consistent JSON error responses:

```php
return response()->json([
    'message' => 'Validation failed',
    'errors' => $validator->errors(),
], 422);

return response()->json([
    'message' => 'Resource not found',
], 404);
```

## Git Workflow

- **Main branch**: `main`
- Follow Laravel conventions for commits
- Run `./vendor/bin/pint` before committing
