<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingIndexTest extends TestCase
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

        Sanctum::actingAs($this->user);
    }

    private function makeBooking(
        User $user,
        string $startsAt,
    ): Booking {
        $start = CarbonImmutable::parse($startsAt, 'UTC');

        return Booking::factory()
            ->for($this->room)
            ->for($user)
            ->create([
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
            ]);
    }

    public function test_it_returns_only_current_user_bookings(): void
    {
        $ownBooking = $this->makeBooking(
            $this->user,
            '2026-11-12 10:00:00',
        );

        $this->makeBooking(
            $this->anotherUser,
            '2026-11-12 11:00:00',
        );

        $response = $this->getJson('/api/bookings');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownBooking->id)
            ->assertJsonPath('data.0.user_id', $this->user->id);
    }

    public function test_it_sorts_bookings_by_start_time_descending(): void
    {
        $oldest = $this->makeBooking(
            $this->user,
            '2026-11-12 10:00:00',
        );

        $newest = $this->makeBooking(
            $this->user,
            '2026-11-14 10:00:00',
        );

        $middle = $this->makeBooking(
            $this->user,
            '2026-11-13 10:00:00',
        );

        $response = $this->getJson('/api/bookings');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id)
            ->assertJsonPath('data.2.id', $oldest->id);
    }

    public function test_it_returns_20_bookings_per_page(): void
    {
        for ($day = 1; $day <= 21; $day++) {
            $this->makeBooking(
                $this->user,
                sprintf('2026-12-%02d 10:00:00', $day),
            );
        }

        $response = $this->getJson('/api/bookings');

        $response->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('total', 21)
            ->assertJsonPath('last_page', 2);
    }

    public function test_it_returns_remaining_booking_on_second_page(): void
    {
        for ($day = 1; $day <= 21; $day++) {
            $this->makeBooking(
                $this->user,
                sprintf('2026-12-%02d 10:00:00', $day),
            );
        }

        $response = $this->getJson('/api/bookings?page=2');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('total', 21);
    }

    public function test_it_returns_empty_list_when_user_has_no_bookings(): void
    {
        $response = $this->getJson('/api/bookings');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_unauthenticated_user_cannot_get_bookings(): void
    {
        auth()->forgetGuards();

        $this->getJson('/api/bookings')
            ->assertUnauthorized();
    }
}
