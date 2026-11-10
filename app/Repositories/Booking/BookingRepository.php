<?php

namespace App\Repositories\Booking;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BookingRepository
{
    /**
     * Получить бронирования пользователя с пагинацией.
     */
    public function getForUser(
        User $user,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return Booking::query()
            ->where('user_id', $user->id)
            ->orderByDesc('starts_at')
            ->paginate($perPage);
    }

    /**
     * Загрузить комнату с блокировкой строки для обновления.
     */
    public function findRoomForUpdate(int $roomId): Room
    {
        return Room::query()
            ->with('office')
            ->whereKey($roomId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Проверить наличие конфликта с существующими бронированиями.
     *
     * Встречи «впритык» разрешены: 10:00–11:00 и 11:00–12:00 — это не пересечение.
     */
    public function hasConflict(
        Room $room,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): bool {
        return $room->bookings()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    /**
     * Создать бронирование.
     */
    public function create(
        Room $room,
        User $user,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): Booking {
        return $room->bookings()->create([
            'user_id' => $user->id,
            'title' => $title,
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
        ]);
    }

    /**
     * Загрузить бронирование с блокировкой для обновления.
     */
    public function findBookingForUpdate(int $bookingId): Booking
    {
        return Booking::query()
            ->whereKey($bookingId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Удалить бронирование.
     */
    public function delete(Booking $booking): void
    {
        $booking->delete();
    }
}
