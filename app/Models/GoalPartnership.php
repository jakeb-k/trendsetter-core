<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalPartnership extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
        ];
    }

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    public function partner()
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }
}
