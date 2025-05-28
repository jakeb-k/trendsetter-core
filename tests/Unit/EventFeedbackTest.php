<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EventFeedbackTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public EventFeedback $eventFeedback;

    public User $user;

    public Event $event;

    /** @var \Illuminate\Support\Collection<int, Image> */
    public $images;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->event = Event::factory()->create();
        $this->eventFeedback = EventFeedback::factory()->create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
        ]);
    }
    
    #[Test]
    public function event_feedback_belongs_to_a_user()
    {
        $this->assertInstanceOf(User::class, $this->eventFeedback->user);
        $this->assertEquals($this->user->id, $this->eventFeedback->user->id);
    }

    #[Test]
    public function event_feedback_belongs_to_an_event()
    {
        $this->assertInstanceOf(Event::class, $this->eventFeedback->event);
        $this->assertEquals($this->event->id, $this->eventFeedback->event->id);
    }

    #[Test]
    public function user_can_have_morphable_images()
    {
        Image::factory(3)->create([
            'imageable_id' => $this->eventFeedback->id,
            'imageable_type' => EventFeedback::class,
        ]);
        $this->assertCount(3, $this->eventFeedback->images);
        $this->assertInstanceOf(MorphMany::class, $this->eventFeedback->images());
    }
}
