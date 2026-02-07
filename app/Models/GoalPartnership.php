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

    /**
     * A goal partnership belongs to a goal
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * A goal partnership belongs to an initiator (user who sent the invite).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    /**
     * A goal partnership belongs to a partner (user who accepted the invite).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function partner()
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }
}
