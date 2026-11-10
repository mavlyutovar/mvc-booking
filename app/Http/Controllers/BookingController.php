<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Room;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {
    }

    /**
     * Получить бронирования текущего пользователя.
     */
    public function index(Request $request)
    {
        $bookings = $this->bookingService->getForUser($request->user());

        return response()->json($bookings);
    }

    /**
     * Создать бронирование.
     */
    public function store(StoreBookingRequest $request)
    {
        $data = $request->validated();

        $booking = $this->bookingService->create(
            user: $request->user(),
            roomId: (int) $data['room_id'],
            title: $data['title'],
            startsAt: CarbonImmutable::parse($data['starts_at']),
            endsAt: CarbonImmutable::parse($data['ends_at']),
        );

        return response()->json($booking, 201);
    }

    /**
     * Отменить бронирование.
     */
    public function destroy(Request $request, int $id)
    {
        $this->bookingService->cancel(
            user: $request->user(),
            bookingId: $id,
        );

        return response()->noContent();
    }

    /**
     * Получить бронирования комнаты на сегодня.
     *
     * Оставляем существующую реализацию метода без изменений.
     */
    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($booking) => [
                'time' => $booking->starts_at . ' – ' . $booking->ends_at,
                'author' => $booking->user->name,
            ]);
    }
}
