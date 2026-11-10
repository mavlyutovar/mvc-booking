<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\User;
use App\Repositories\Booking\BookingRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly BookingTimeValidator $timeValidator,
    ) {
    }

    /**
     * Получить бронирования пользователя.
     */
    public function getForUser(
        User $user,
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->bookingRepository->getForUser($user, $perPage);
    }

    /**
     * Создать бронирование.
     */
    public function create(
        User $user,
        int $roomId,
        string $title,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): Booking {
        return DB::transaction(function () use (
            $user,
            $roomId,
            $title,
            $startsAt,
            $endsAt,
        ) {
            // Блокируем комнату для обновления
            $room = $this->bookingRepository->findRoomForUpdate($roomId);

            // Валидация времени в часовом поясе офиса
            $this->timeValidator->validate(
                $startsAt,
                $endsAt,
                $room->office->timezone,
            );

            // Конвертируем в UTC для хранения в БД
            $startsAtUtc = $startsAt->utc();
            $endsAtUtc = $endsAt->utc();

            // Проверка конфликтов (внутри транзакции после блокировки)
            if ($this->bookingRepository->hasConflict(
                $room,
                $startsAtUtc,
                $endsAtUtc,
            )) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Выбранное время уже занято. Выберите другой интервал.',
                ]);
            }

            // Создание брони
            $booking = $this->bookingRepository->create(
                $room,
                $user,
                $title,
                $startsAtUtc,
                $endsAtUtc,
            );

            // Логирование с контекстом
            Log::channel('bookings')->info('Booking created', [
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'user_id' => $user->id,
            ]);

            return $booking;
        });
    }

    /**
     * Отменить бронирование.
     */
    public function cancel(
        User $user,
        int $bookingId,
    ): void {
        DB::transaction(function () use ($user, $bookingId) {
            // Блокируем бронь для обновления
            $booking = $this->bookingRepository->findBookingForUpdate($bookingId);

            // Проверка авторизации: только владелец может отменить
            if ((int) $booking->user_id !== (int) $user->id) {
                throw new AuthorizationException(
                    'Вы можете отменить только собственное бронирование.'
                );
            }

            // Удаление брони
            $this->bookingRepository->delete($booking);
        });
    }
}
