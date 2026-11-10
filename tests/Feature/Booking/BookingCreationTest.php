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

class BookingCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Office $office;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->office = Office::factory()->create([
            'name' => 'Москва',
            'timezone' => 'Europe/Moscow',
        ]);

        $this->room = Room::factory()
            ->for($this->office)
            ->create();

        Sanctum::actingAs($this->user);
    }

    private function createBooking(
        string $startsAt,
        string $endsAt,
        ?int $roomId = null,
        array $extra = [],
    ) {
        return $this->postJson('/api/bookings', array_merge([
            'room_id' => $roomId ?? $this->room->id,
            'title' => 'Тестовое бронирование',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ], $extra));
    }

    public function test_it_creates_booking_successfully(): void
    {
        $response = $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        );

        $response->assertCreated()
            ->assertJsonPath('title', 'Тестовое бронирование')
            ->assertJsonPath('room_id', $this->room->id)
            ->assertJsonPath('user_id', $this->user->id);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_it_allows_adjacent_bookings(): void
    {
        $first = $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        );

        $first->assertCreated();

        $second = $this->createBooking(
            '2026-11-12T11:00:00+03:00',
            '2026-11-12T12:00:00+03:00',
        );

        $second->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_it_allows_booking_immediately_before_existing_booking(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T09:00:00+03:00',
            '2026-11-12T10:00:00+03:00',
        )->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_it_rejects_overlapping_bookings(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T10:30:00+03:00',
            '2026-11-12T11:30:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_it_rejects_fully_matching_booking_interval(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertUnprocessable();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_it_rejects_booking_containing_existing_interval(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T09:30:00+03:00',
            '2026-11-12T11:30:00+03:00',
        )->assertUnprocessable();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_it_rejects_booking_inside_existing_interval(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T12:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T10:30:00+03:00',
            '2026-11-12T11:30:00+03:00',
        )->assertUnprocessable();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_it_allows_same_time_booking_in_another_room(): void
    {
        $anotherRoom = Room::factory()
            ->for($this->office)
            ->create();

        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
            $anotherRoom->id,
        )->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_it_allows_booking_in_another_date_for_same_room(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-13T10:00:00+03:00',
            '2026-11-13T11:00:00+03:00',
        )->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_it_accepts_minimum_duration_of_15_minutes(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T10:15:00+03:00',
        )->assertCreated();
    }

    public function test_it_accepts_maximum_duration_of_4_hours(): void
    {
        $this->createBooking(
            '2026-11-12T09:00:00+03:00',
            '2026-11-12T13:00:00+03:00',
        )->assertCreated();
    }

    public function test_it_rejects_duration_shorter_than_15_minutes(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T10:14:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_it_rejects_duration_longer_than_4_hours(): void
    {
        $this->createBooking(
            '2026-11-12T09:00:00+03:00',
            '2026-11-12T13:01:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_it_rejects_end_time_before_start_time(): void
    {
        $this->createBooking(
            '2026-11-12T11:00:00+03:00',
            '2026-11-12T10:00:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_it_accepts_start_time_on_15_minute_steps(): void
    {
        $times = ['10:00', '10:15', '10:30', '10:45'];

        foreach ($times as $index => $time) {
            $date = sprintf('2026-11-%02d', 12 + $index);

            $this->createBooking(
                "{$date}T{$time}:00+03:00",
                "{$date}T11:30:00+03:00",
            )->assertCreated();
        }

        $this->assertDatabaseCount('bookings', 4);
    }

    public function test_it_rejects_start_time_not_on_15_minute_steps(): void
    {
        foreach (['10:01', '10:10', '10:14', '10:16'] as $time) {
            $this->createBooking(
                "2026-11-12T{$time}:00+03:00",
                '2026-11-12T11:00:00+03:00',
            )->assertUnprocessable()
                ->assertJsonValidationErrors('starts_at');
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_it_rejects_start_time_with_nonzero_seconds(): void
    {
        $this->createBooking(
            '2026-11-12T10:15:30+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_it_accepts_booking_starting_at_09_00(): void
    {
        $this->createBooking(
            '2026-11-12T09:00:00+03:00',
            '2026-11-12T10:00:00+03:00',
        )->assertCreated();
    }

    public function test_it_accepts_booking_ending_at_20_00(): void
    {
        $this->createBooking(
            '2026-11-12T19:00:00+03:00',
            '2026-11-12T20:00:00+03:00',
        )->assertCreated();
    }

    public function test_it_accepts_booking_from_19_45_to_20_00(): void
    {
        $this->createBooking(
            '2026-11-12T19:45:00+03:00',
            '2026-11-12T20:00:00+03:00',
        )->assertCreated();
    }

    public function test_it_rejects_booking_starting_before_09_00(): void
    {
        $this->createBooking(
            '2026-11-12T08:45:00+03:00',
            '2026-11-12T09:45:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_it_rejects_booking_ending_after_20_00(): void
    {
        $this->createBooking(
            '2026-11-12T19:15:00+03:00',
            '2026-11-12T20:15:00+03:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_moscow_office_uses_moscow_local_time(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T08:00:00+03:00',
            '2026-11-12T09:00:00+03:00',
        )->assertUnprocessable();
    }

    public function test_novosibirsk_office_uses_novosibirsk_local_time(): void
    {
        $novosibirskOffice = Office::factory()->novosibirsk()->create();

        $novosibirskRoom = Room::factory()
            ->for($novosibirskOffice)
            ->create();

        $this->createBooking(
            '2026-11-12T10:00:00+07:00',
            '2026-11-12T11:00:00+07:00',
            $novosibirskRoom->id,
        )->assertCreated();

        $this->createBooking(
            '2026-11-12T08:00:00+07:00',
            '2026-11-12T09:00:00+07:00',
            $novosibirskRoom->id,
        )->assertUnprocessable();
    }

    public function test_it_accepts_utc_input_for_moscow_office(): void
    {
        // 07:00 UTC — это 10:00 по Москве.
        $response = $this->createBooking(
            '2026-11-12T07:00:00Z',
            '2026-11-12T08:00:00Z',
        );

        $response->assertCreated();

        $booking = Booking::query()->firstOrFail();

        $this->assertTrue(
            CarbonImmutable::parse($booking->starts_at)
                ->utc()
                ->equalTo(CarbonImmutable::parse('2026-11-12T07:00:00Z'))
        );
    }

    public function test_it_accepts_utc_input_for_novosibirsk_office(): void
    {
        $novosibirskOffice = Office::factory()->novosibirsk()->create();

        $novosibirskRoom = Room::factory()
            ->for($novosibirskOffice)
            ->create();

        // 03:00 UTC — это 10:00 в Новосибирске.
        $response = $this->createBooking(
            '2026-11-12T03:00:00Z',
            '2026-11-12T04:00:00Z',
            $novosibirskRoom->id,
        );

        $response->assertCreated();
    }

    public function test_it_accepts_iso8601_with_explicit_offset(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
        )->assertCreated();
    }

    public function test_it_accepts_iso8601_with_z_suffix(): void
    {
        $this->createBooking(
            '2026-11-12T07:00:00Z',
            '2026-11-12T08:00:00Z',
        )->assertCreated();
    }

    public function test_it_rejects_datetime_without_timezone_offset(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00',
            '2026-11-12T11:00:00',
        )->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_at',
                'ends_at',
            ]);
    }

    public function test_it_rejects_invalid_datetime(): void
    {
        $this->createBooking(
            'not-a-date',
            'also-not-a-date',
        )->assertUnprocessable()
            ->assertJsonValidationErrors([
                'starts_at',
                'ends_at',
            ]);
    }

    public function test_it_rejects_invalid_calendar_date(): void
    {
        $this->createBooking(
            '2026-02-30T10:00:00+03:00',
            '2026-02-30T11:00:00+03:00',
        )->assertUnprocessable();
    }

    public function test_it_rejects_invalid_timezone_offset(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+99:00',
            '2026-11-12T11:00:00+99:00',
        )->assertUnprocessable();
    }

    public function test_authenticated_user_is_used_as_booking_author(): void
    {
        $anotherUser = User::factory()->create();

        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
            extra: [
                'user_id' => $anotherUser->id,
            ],
        )->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertDatabaseMissing('bookings', [
            'room_id' => $this->room->id,
            'user_id' => $anotherUser->id,
        ]);
    }

    public function test_it_rejects_request_without_room_id(): void
    {
        $this->postJson('/api/bookings', [
            'title' => 'Тест',
            'starts_at' => '2026-11-12T10:00:00+03:00',
            'ends_at' => '2026-11-12T11:00:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_it_rejects_nonexistent_room(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
            999999,
        )->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_it_rejects_empty_title(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
            extra: ['title' => ''],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_it_rejects_title_longer_than_255_characters(): void
    {
        $this->createBooking(
            '2026-11-12T10:00:00+03:00',
            '2026-11-12T11:00:00+03:00',
            extra: ['title' => str_repeat('a', 256)],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_unauthenticated_user_cannot_create_booking(): void
    {
        auth()->forgetGuards();

        $this->postJson('/api/bookings', [
            'room_id' => $this->room->id,
            'title' => 'Тест',
            'starts_at' => '2026-11-12T10:00:00+03:00',
            'ends_at' => '2026-11-12T11:00:00+03:00',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('bookings', 0);
    }
}
