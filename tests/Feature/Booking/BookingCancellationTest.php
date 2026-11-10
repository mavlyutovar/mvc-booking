<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $anotherUser;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->anotherUser = User::factory()->create();

        $office = Office::factory()->create([
            'timezone' => 'Europe/Moscow',
        ]);

        $this->room = Room::factory()
            ->for($office)
            ->create();
    }

    private function makeBooking(?User $author = null): Booking
    {
        $author ??= $this->user;

        return Booking::factory()
            ->for($this->room)
            ->for($author)
            ->create([
                'starts_at' => '2026-11-12 10:00:00',
                'ends_at' => '2026-11-12 11:00:00',
            ]);
    }

    public function test_author_can_cancel_own_booking(): void
    {
        Sanctum::actingAs($this->user);

        $booking = $this->makeBooking($this->user);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_another_user_cannot_cancel_booking(): void
    {
        Sanctum::actingAs($this->anotherUser);

        $booking = $this->makeBooking($this->user);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_it_returns_404_when_booking_does_not_exist(): void
    {
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/bookings/999999')
            ->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_cancel_booking(): void
    {
        $booking = $this->makeBooking($this->user);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_booking_cannot_be_cancelled_twice(): void
    {
        Sanctum::actingAs($this->user);

        $booking = $this->makeBooking($this->user);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('bookings', [
            'id' => $booking->id,
        ]);
    }
}
