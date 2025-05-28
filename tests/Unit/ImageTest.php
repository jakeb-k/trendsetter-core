<?php

namespace Tests\Unit;

use App\Models\EventFeedback;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ImageTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function images_can_morph_to_a_user()
    {
        $user = User::factory()->create();
        $image = Image::factory()->create([
            'imageable_id' => $user->id,
            'imageable_type' => User::class,
        ]);
        $this->assertInstanceOf(User::class, $image->imageable);
        $this->assertEquals($user->id, $image->imageable->id);
    }

    public function images_can_morph_to_event_feedback()
    {
        $eventFeedback = EventFeedback::factory()->create();
        $image = Image::factory()->create([
            'imageable_id' => $eventFeedback->id,
            'imageable_type' => EventFeedback::class,
        ]);
        $this->assertInstanceOf(EventFeedback::class, $image->imageable);
        $this->assertEquals($eventFeedback->id, $image->imageable->id);
    }
}
