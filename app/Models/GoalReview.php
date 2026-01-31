<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalReview extends Model
{
    /** @use HasFactory<\Database\Factories\GoalReviewFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'feelings' => 'json',
            'stats_snapshot' => 'json',
        ];
    }

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }
}
