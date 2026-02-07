<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalPartnerInvite extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
    
    /**
     * A goal partner invite belongs to a goal
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }
    
    /**
     * A goal partner invite belongs to an inviter (user).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }
    
    /**
     * A goal partner invite belongs to an invitee (user).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function invitee()
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }

    /**
     * Check if the current invite is expired.
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
