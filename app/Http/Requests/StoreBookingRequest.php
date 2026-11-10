<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $iso8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/';

        return [
            'room_id' => [
                'required',
                'integer',
                Rule::exists('rooms', 'id'),
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'starts_at' => [
                'required',
                'string',
                Rule::regex($iso8601),
                'date',
            ],

            'ends_at' => [
                'required',
                'string',
                Rule::regex($iso8601),
                'after:start',
                'date',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'Необходимо выбрать комнату.',
            'room_id.integer' => 'Некорректный идентификатор комнаты.',
            'room_id.exists' => 'Выбранная комната не существует.',

            'title.required' => 'Укажите название бронирования.',
            'title.string' => 'Название должно быть строкой.',
            'title.max' => 'Название не должно превышать 255 символов.',

            'starts_at.required' => 'Укажите время начала.',
            'starts_at.regex' => 'Время начала должно быть в формате ISO 8601 с часовым поясом.',
            'starts_at.date' => 'Указано некорректное время начала.',

            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.regex' => 'Время окончания должно быть в формате ISO 8601 с часовым поясом.',
            'ends_at.date' => 'Указано некорректное время окончания.',
            'ends_at.after' => 'Время окончания должно быть позже времени начала.',
        ];
    }
}
