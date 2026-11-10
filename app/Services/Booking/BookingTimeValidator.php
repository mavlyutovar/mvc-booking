<?php

namespace App\Services\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BookingTimeValidator
{
    private const MIN_DURATION_MINUTES = 15;

    private const MAX_DURATION_MINUTES = 240;

    private const TIME_STEP_MINUTES = 15;

    private const WORK_START_HOUR = 9;

    private const WORK_END_HOUR = 20;

    /**
     * Валидировать время бронирования.
     */
    public function validate(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
    ): void {
        // Переводим в локальное время офиса для проверки правил
        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);

        $this->validateDuration($startsAt, $endsAt);
        $this->validateStartTime($localStart);
        $this->validateWorkingHours($localStart, $localEnd);
    }

    /**
     * Проверить длительность бронирования.
     */
    private function validateDuration(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        // Минимум 15 минут
        if ($endsAt->lt($startsAt->addMinutes(self::MIN_DURATION_MINUTES))) {
            throw ValidationException::withMessages([
                'ends_at' => 'Минимальная длительность бронирования — 15 минут.',
            ]);
        }

        // Максимум 4 часа
        if ($endsAt->gt($startsAt->addMinutes(self::MAX_DURATION_MINUTES))) {
            throw ValidationException::withMessages([
                'ends_at' => 'Максимальная длительность бронирования — 4 часа.',
            ]);
        }
    }

    /**
     * Проверить шаг времени (кратно 15 минутам).
     */
    private function validateStartTime(
        CarbonImmutable $startsAt,
    ): void {
        $validMinute = $startsAt->minute % self::TIME_STEP_MINUTES === 0;
        $validSeconds = $startsAt->second === 0
            && $startsAt->microsecond === 0;

        if (! $validMinute || ! $validSeconds) {
            throw ValidationException::withMessages([
                'starts_at' => 'Время начала должно быть кратно 15 минутам.',
            ]);
        }
    }

    /**
     * Проверить рабочее время офиса (09:00–20:00).
     */
    private function validateWorkingHours(
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
    ): void {
        // Рабочее начало по локальному времени
        $workStart = (clone $localStart)->setTime(self::WORK_START_HOUR, 0);

        // Рабочее окончание по локальному времени
        $workEnd = (clone $localStart)->setTime(self::WORK_END_HOUR, 0);

        if ($localStart->lt($workStart) || $localEnd->gt($workEnd)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронирование должно полностью находиться в рабочем времени офиса (09:00–20:00).',
            ]);
        }
    }
}
