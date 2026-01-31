<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    /** @use HasFactory<\Database\Factories\GoalFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * A goal belongs to a user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A goal can have many ai plans
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function ai_plans()
    {
        return $this->hasMany(AiPlan::class);
    }

    /**
     * A goal can have many events
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function review()
    {
        return $this->hasOne(GoalReview::class);
    }

}
