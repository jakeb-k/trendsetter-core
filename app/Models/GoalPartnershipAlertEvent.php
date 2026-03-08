<?php

namespace App\Models;

use App\Services\PartnershipAlertEvaluationSource;
use App\Services\PartnershipAlertType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoalPartnershipAlertEvent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evaluation_source' => PartnershipAlertEvaluationSource::class,
            'selected_alert_type' => PartnershipAlertType::class,
            'candidate_types' => 'array',
            'reason_codes' => 'array',
            'snapshot_excerpt' => 'array',
            'signal_date' => 'date',
            'evaluated_at' => 'datetime',
        ];
    }

    public function partnership()
    {
        return $this->belongsTo(GoalPartnership::class, 'partnership_id');
    }

    public function goal()
    {
        return $this->belongsTo(Goal::class);
    }

    public function subjectUser()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
