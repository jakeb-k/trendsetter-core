<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    /**
     * An event belongs to a goal
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * An event belongs to a plan
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ai_plan()
    {
        return $this->belongsTo(AiPlan::class);
    }

    /**
     * an event can have many feedback entries
     *
     * @return \Illuminate\Database\Eloquent\Relations\hasMany
     */
    public function feedback()
    {
        return $this->hasMany(EventFeedback::class);
    }


}
