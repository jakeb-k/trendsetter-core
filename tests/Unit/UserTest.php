<?php

namespace Tests\Unit;

use App\Models\EventFeedback;
use App\Models\Goal;
use App\Models\Image;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class UserTest extends TestCase
{

    use RefreshDatabase;

    public User $user; 

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function user_has_goals()
    {
        Goal::factory(3)->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertCount(3, $this->user->goals);
        $this->assertInstanceOf(HasMany::class, $this->user->goals());
    }

    #[Test]
    public function user_has_event_feedback()
    {
        EventFeedback::factory(3)->create([
            'user_id' => $this->user->id, 
        ]);

        $this->assertCount(3, $this->user->event_feedback);
        $this->assertInstanceOf(HasMany::class, $this->user->event_feedback());
    }

    #[Test]
    public function user_can_have_morphable_images()
    {
        Image::factory(3)->create([
            'imageable_id' => $this->user->id,
            'imageable_type' => User::class,
        ]);
        $this->assertCount(3, $this->user->images);
        $this->assertInstanceOf(MorphMany::class, $this->user->images());
    }


}
