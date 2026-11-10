# Ревью API бронирования переговорных

## Critical (критичные — ломают функциональность)

### 1. SQL-инъекция в `store()` — **CRITICAL**

**Место:** [`app/Http/Controllers/BookingController.php:23-26`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L23-L26)

```php
$conflict = DB::select("SELECT id FROM bookings
    WHERE room_id = {$request->room_id}
    AND starts_at <= '{$request->ends_at}'
    AND ends_at >= '{$request->starts_at}'");
```

**Проблема:** Прямая интерполяция пользовательских значений в SQL-запросе. Атакующий может передать вредоносный запрос через `room_id`, `starts_at` или `ends_at`.

**Как исправить:** Использовать параметризованные запросы (prepared statements):

```php
$conflict = DB::select("SELECT id FROM bookings
    WHERE room_id = ?
    AND starts_at <= ?
    AND ends_at >= ?",
    [$request->room_id, $request->ends_at, $request->starts_at]);
```

Или лучше — использовать Eloquent:

```php
$conflict = Booking::where('room_id', $request->room_id)
    ->whereBetween([
        'starts_at' => $request->starts_at,
        'ends_at' => $request->ends_at,
    ])
    ->exists();
```

---

### 2. Отсутствие проверки авторизации на удаление — **CRITICAL**

**Место:** [`app/Http/Controllers/BookingController.php:45-50`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L45-L50)

```php
public function destroy($id)
{
    Booking::findOrFail($id)->delete();
    return response()->noContent();
}
```

**Проблема:** Любой пользователь может удалить чужую бронь, зная её ID. Правило "Отменить бронь может только её автор" не реализуется.

**Как исправить:** Проверить принадлежность брони текущему пользователю:

```php
public function destroy(Booking $booking)
{
    if ($booking->user_id !== auth()->id()) {
        abort(403, 'Недостаточно прав');
    }
    
    $booking->delete();
    return response()->noContent();
}
```

---

### 3. Отсутствие валидации времени бронирования — **CRITICAL**

**Место:** [`app/Http/Controllers/BookingController.php:32-35`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L32-L35)

```php
$start = Carbon::parse($request->starts_at);
if ($start->hour < 9 || $start->hour >= 20) {
    return response()->json(['error' => 'Нерабочее время'], 422);
}
```

**Проблема:** 
- Проверка только по `hour` игнорирует часовые пояса клиента (правило: "Клиент присылает время в ISO 8601 с любым смещением")
- Нет проверки длительности (должна быть от 15 минут до 4 часов)
- Нет проверки шага 15 минут

**Как исправить:** Перевести время клиента в локальное время офиса комнаты и проверить:

```php
use Carbon\Carbon;

$start = Carbon::parse($request->starts_at, $room->office->timezone);
$end = Carbon::parse($request->ends_at, $room->office->timezone);

// Длительность от 15 минут до 4 часов
$difference = $start->diffInMinutes($end);
if ($difference < 15 || $difference > 240) {
    return response()->json(['error' => 'Длительность должна быть от 15 минут до 4 часов'], 422);
}

// Шаг 15 минут
if ($start->minute % 15 !== 0 || $end->minute % 15 !== 0) {
    return response()->json(['error' => 'Время должно указываться с шагом 15 минут'], 422);
}

// В рабочее время 09:00–20:00
if ($start < Carbon::parse('09:00', $room->office->timezone) || 
    $end > Carbon::parse('20:00', $room->office->timezone)) {
    return response()->json(['error' => 'Бронь должна укладываться в рабочее время'], 422);
}
```

---

### 4. Гонка при создании брони — **CRITICAL**

**Место:** [`app/Http/Controllers/BookingController.php:23-40`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L23-L40)

```php
$conflict = DB::select(...); // проверка
// ... валидация ...
$booking = Booking::create($request->all()); // создание без транзакции!
```

**Проблема:** Классическая гонка (race condition):
1. Запрос А проверяет отсутствие конфликта → нет
2. Запрос Б проверяет отсутствие конфликта → нет  
3. Запрос А создаёт бронь
4. Запрос Б создаёт бронь — **двойная бронь!**

**Как исправить:** Использовать транзакцию с проверкой внутри:

```php
DB::transaction(function () use ($request, $room) {
    // Проверка конфликтов в той же транзакции
    Booking::where('room_id', $request->room_id)
        ->whereBetween(['starts_at' => $request->starts_at, 'ends_at' => $request->ends_at])
        ->exists() || abort(409, 'Комната занята');

    // Создание брони
    Booking::create($request->all());
});
```

---

## Major (важные — влияют на надёжность/безопасность)

### 5. Логирование без контекста — **MAJOR**

**Место:** [`app/Http/Controllers/BookingController.php:39-40`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L39-L40)

```php
Log::withContext(['booking_id' => $booking->id]);
Log::channel('bookings')->info('Booking created');
```

**Проблема:** `withContext()` не передаётся в следующий вызов `info()`. Лог будет без booking_id.

**Как исправить:** Передавать данные напрямую в лог-запись:

```php
Log::channel('bookings')->info('Booking created', [
    'booking_id' => $booking->id,
    'room_id' => $request->room_id,
]);
```

---

### 6. Отсутствие проверки существования комнаты — **MAJOR**

**Место:** [`app/Http/Controllers/BookingController.php:23`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L23)

```php
DB::select("SELECT id FROM bookings WHERE room_id = {$request->room_id} ...");
```

**Проблема:** Если комната не существует, запрос вернёт пустой массив (нет конфликта), но затем `Booking::create()` создаст запись с несуществующей комнатой.

**Как исправить:** Добавить проверку:

```php
Room::findOrFail($request->room_id); // или добавить в fillable и использовать $validated['room_id']
```

---

### 7. Отсутствие валидации через Request — **MAJOR**

**Место:** [`app/Http/Controllers/BookingController.php:21-43`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L21-L43)

```php
public function store(Request $request)
{
    // Нет валидации! Всё берётся через $request->all()
}
```

**Проблема:** 
- Нет проверки обязательных полей
- Типы данных не проверяются
- Можно передать лишние поля

**Как исправить:** Использовать `Request::validate()` или атрибутную валидацию:

```php
public function store(Request $request)
{
    $validated = Request::validate([
        'room_id' => ['required', 'exists:rooms,id'],
        'user_id' => ['required'], // обычно берётся из auth()->id()
        'title' => ['required', 'string', 'max:255'],
        'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        'ends_at' => ['required', 'after:start', 'date_format:Y-m-d\TH:i:sP'],
    ]);

    // далее использовать $validated вместо $request->all()
}
```

---

### 8. Неправильная проверка пересечения интервалов — **MAJOR**

**Место:** [`app/Http/Controllers/BookingController.php:23-26`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L23-L26)

```sql
WHERE room_id = ? AND starts_at <= ? AND ends_at >= ?
```

**Проблема:** Логика верна для проверки пересечения, но:
- Не проверяется, что новая броня целиком в рабочих часах (см. критичную проблему 3)
- Нет проверки на "впритык" — если у нас есть 10:00–11:00 и приходит 11:00–12:00, это должно быть разрешено

**Как исправить:** Уточнить условие пересечения (должно возвращать false для "впритык"):

```sql
-- Пересечение есть, если:
-- новая starts < существующая ends И новая ends > существующая starts
WHERE room_id = ? 
AND (starts_at < ? OR ends_at > ?)  -- строгое неравенство для "впритык"
```

---

## Minor (незначительные — код-качество, стилистика)

### 9. Использование `DB::select` вместо Eloquent — **MINOR**

**Место:** [`app/Http/Controllers/BookingController.php:23`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L23)

```php
$conflict = DB::select(...);
if ($conflict) { ... }
```

**Проблема:** 
- `DB::select` возвращает массив строк, проверка `$conflict` всегда истинна для пустого массива (нет!) — нужно проверять `!empty($conflict)` или использовать `exists()`
- Не использует Eloquent, что противоречит Laravel conventions

**Как исправить:**

```php
$conflict = Booking::where('room_id', $request->room_id)
    ->whereBetween(['starts_at' => $request->starts_at, 'ends_at' => $request->ends_at])
    ->exists(); // возвращает bool
if ($conflict) { ... }
```

---

### 10. `Log::withContext()` без последующего использования — **MINOR**

**Место:** [`app/Http/Controllers/BookingController.php:39`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L39)

```php
Log::withContext(['booking_id' => $booking->id]);
Log::channel('bookings')->info('Booking created');
```

**Проблема:** `withContext()` создаёт контекст, но он не передаётся в следующий вызов. Лишний код.

**Как исправить:** Убрать `withContext()`, передать данные напрямую (см. major проблема 5).

---

### 11. Отсутствие импорта Carbon — **MINOR**

**Место:** [`app/Http/Controllers/BookingController.php:7`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L7)

```php
use Carbon\Carbon;
```

**Проблема:** Импорт есть, но стоит проверить — в Laravel 13+ Carbon может быть доступен через фасад без импорта. Это не баг, а вопрос стиля.

---

### 12. `findOrFail` вместо `findOrFail` — **MINOR (стиль)**

**Место:** [`app/Http/Controllers/BookingController.php:47`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L47)

```php
Booking::findOrFail($id)->delete();
```

**Проблема:** `findOrFail` принимает ID, а не строку. Если `$id` приходит как строка из URL (что часто), это всё равно сработает, но лучше явно использовать `findOrFail`. Это вопрос консистентности.

---

### 13. Отсутствие обработки ошибок — **MINOR**

**Место:** [`app/Http/Controllers/BookingController.php:21-50`](file:///C:/www/Claude%20Code/mvc-booking/app/Http/Controllers/BookingController.php#L21-L50)

```php
public function store(Request $request) { ... }
```

**Проблема:** Нет try-catch для обработки ошибок БД. Если что-то пойдёт не так, будет 500 вместо понятной ошибки.

**Как исправить (опционально):**

```php
try {
    // логика
} catch (\Exception $e) {
    Log::error($e->getMessage());
    return response()->json(['error' => 'Внутренняя ошибка'], 500);
}
```

---

## Итоговая таблица

| № | Проблема | Серьёзность | Статус |
|---|----------|-------------|--------|
| 1 | SQL-инъекция в `store()` | Critical | Исправить через параметризованные запросы |
| 2 | Отсутствие авторизации на удаление | Critical | Добавить проверку `$booking->user_id === auth()->id()` |
| 3 | Неправильная проверка времени | Critical | Учитывать timezone, длительность, шаг |
| 4 | Гонка при создании брони | Critical | Использовать транзакции |
| 5 | Логирование без контекста | Major | Передавать данные напрямую в `info()` |
| 6 | Отсутствие проверки комнаты | Major | Добавить `Room::findOrFail($room_id)` |
| 7 | Нет валидации через Request | Major | Использовать `$validated = validate([...])` |
| 8 | Логика пересечения интервалов | Major | Уточнить условие (строго < для "впритык") |
| 9 | `DB::select` вместо Eloquent | Minor | Перевести на Eloquent + использовать `exists()` |
| 10 | Лишний `withContext()` | Minor | Убрать, передавать данные напрямую |
| 11 | Импорт Carbon (стиль) | Minor | Проверить необходимость импорта |
| 12 | `findOrFail` vs `findOrFail` | Minor | Консистентность в API |
| 13 | Нет обработки ошибок | Minor | Добавить try-catch (опционально) |
