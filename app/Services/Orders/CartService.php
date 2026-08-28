<?php

namespace App\Services\Orders;

use App\Enums\MenuSectionType;
use App\Exceptions\DeadlinePassedException;
use App\Exceptions\MenuUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuDay;
use App\Models\MenuSection;
use App\Models\NonWorkingDay;
use App\Models\OrderLine;
use App\Models\Student;
use App\Models\Supplier;
use App\Services\Deadlines\DeadlineService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Кошик спільний для всіх постачальників, але кожна позиція знає свою дату
 * й постачальника — перевірка дедлайну йде по групі «постачальник + дата».
 *
 * Збирати кошик можна без входу: гостьовий кошик прив'язаний до токена в сесії
 * й переноситься в кошик учня при вході (вхід потрібен лише на оформленні).
 */
class CartService
{
    /** Ключ сесії з токеном гостьового кошика. */
    public const GUEST_TOKEN_KEY = 'guest_cart_token';

    public function __construct(private readonly DeadlineService $deadlines) {}

    public function for(Student $student): Cart
    {
        return Cart::query()->firstOrCreate(['student_id' => $student->id]);
    }

    /** Кошик поточного відвідувача — учня або гостя; створюється при першому додаванні. */
    public function current(): Cart
    {
        $student = auth()->user()?->student;

        if ($student !== null) {
            return $this->for($student);
        }

        $token = $this->guestToken();

        if ($token === null) {
            $token = Str::random(64);
            session()->put(self::GUEST_TOKEN_KEY, $token);
        }

        return Cart::query()->firstOrCreate(['session_token' => $token]);
    }

    /** Те саме, але без створення порожнього кошика — для читання (підсумок, сторінка кошика). */
    public function currentIfExists(): ?Cart
    {
        $student = auth()->user()?->student;

        if ($student !== null) {
            return Cart::query()->where('student_id', $student->id)->first();
        }

        $token = $this->guestToken();

        return $token === null
            ? null
            : Cart::query()->where('session_token', $token)->first();
    }

    /**
     * Перенесення гостьового кошика в кошик учня після входу: зібране до входу
     * не має губитися, інакше вхід на оформленні втрачає сенс.
     *
     * @return int кількість перенесених позицій
     */
    /**
     * Забирає гостьовий токен із сесії. Викликається до session()->regenerate(),
     * бо після перестворення сесії значення може вже не бути.
     */
    public function pullGuestToken(): ?string
    {
        $token = session()->pull(self::GUEST_TOKEN_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Переносить гостьовий кошик до учня після входу.
     *
     * @param  string|null  $token  якщо не переданий, береться з поточної сесії
     */
    public function adoptGuestCart(Student $student, ?string $token = null): int
    {
        $token ??= $this->pullGuestToken();

        if ($token === null) {
            return 0;
        }

        $guest = Cart::query()
            ->where('session_token', $token)
            ->with('items.menuSection')
            ->first();

        if ($guest === null) {
            return 0;
        }

        $cart = $this->for($student);
        $moved = 0;

        DB::transaction(function () use ($guest, $cart, &$moved): void {
            foreach ($guest->items as $item) {
                // У групі вибору лишається один варіант — свіжіший, гостьовий.
                if ($item->menuSection?->type === MenuSectionType::Choice) {
                    $cart->items()
                        ->where('menu_section_id', $item->menu_section_id)
                        ->where('dish_id', '!=', $item->dish_id)
                        ->delete();
                }

                $existing = $cart->items()
                    ->where('menu_section_id', $item->menu_section_id)
                    ->where('dish_id', $item->dish_id)
                    ->first();

                if ($existing !== null) {
                    $existing->increment('quantity', $item->quantity);
                    $item->delete();
                } else {
                    $item->update(['cart_id' => $cart->id]);
                }

                $moved++;
            }

            $guest->delete();
        });

        return $moved;
    }

    /**
     * Додає страву в кошик. Якщо страва вже там — збільшує кількість.
     * У групі вибору попередній варіант замінюється: обрати можна тільки один.
     */
    public function add(Cart $cart, MenuSection $section, int $dishId, int $quantity = 1): CartItem
    {
        $menuDay = $section->menuDay;

        $this->assertOrderable($menuDay);
        $this->assertDishBelongsToSection($section, $dishId);
        $this->deadlines->assertCanOrder($menuDay->supplier_id, $menuDay->date);

        if ($section->type === MenuSectionType::Choice) {
            $cart->items()
                ->where('menu_section_id', $section->id)
                ->where('dish_id', '!=', $dishId)
                ->delete();
        }

        $item = $cart->items()
            ->where('menu_section_id', $section->id)
            ->where('dish_id', $dishId)
            ->first();

        if ($item !== null) {
            $item->increment('quantity', $quantity);

            return $item->refresh();
        }

        return $cart->items()->create([
            'supplier_id' => $menuDay->supplier_id,
            'service_date' => $menuDay->date,
            'dish_id' => $dishId,
            'menu_section_id' => $section->id,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Повтор набору страв з іншого тижня (ТЗ, п. 9.2).
     *
     * Страви шукаються в меню цільового тижня за назвою: id страви той самий
     * лише поки постачальник не перестворив її, а назва переживає й це.
     *
     * @return array{added: int, unavailable: array<int, string>}
     */
    public function repeatWeek(Student $student, CarbonInterface|string $sourceWeekStart, CarbonInterface|string $targetWeekStart): array
    {
        $source = CarbonImmutable::parse($sourceWeekStart)->startOfWeek();
        $target = CarbonImmutable::parse($targetWeekStart)->startOfWeek();

        $lines = OrderLine::query()
            ->where('student_id', $student->id)
            ->whereDate('service_date', '>=', $source->toDateString())
            ->whereDate('service_date', '<=', $source->addDays(6)->toDateString())
            ->active()
            ->get();

        $cart = $this->for($student);
        $added = 0;
        $unavailable = [];

        foreach ($lines as $line) {
            $date = $target->addDays($line->service_date->isoWeekday() - 1);

            $section = $this->findSectionFor($line, $date);

            if ($section === null) {
                $unavailable[] = sprintf('%s — %s', $line->dish_name, $date->translatedFormat('d.m'));

                continue;
            }

            try {
                $this->add($cart, $section['section'], $section['dish_id'], $line->quantity);
                $added++;
            } catch (DeadlinePassedException|MenuUnavailableException $e) {
                $unavailable[] = sprintf('%s — %s (%s)', $line->dish_name, $date->translatedFormat('d.m'), $e->getMessage());
            }
        }

        return ['added' => $added, 'unavailable' => $unavailable];
    }

    /**
     * @return array{section: MenuSection, dish_id: int}|null
     */
    private function findSectionFor(OrderLine $line, CarbonImmutable $date): ?array
    {
        $menuDay = MenuDay::query()
            ->where('supplier_id', $line->supplier_id)
            ->whereDate('date', $date->toDateString())
            ->visibleToStudents()
            ->with('sections.sectionDishes.dish')
            ->first();

        if ($menuDay === null) {
            return null;
        }

        foreach ($menuDay->sections as $section) {
            foreach ($section->sectionDishes as $sectionDish) {
                if ($sectionDish->dish?->name === $line->dish_name) {
                    return ['section' => $section, 'dish_id' => $sectionDish->dish_id];
                }
            }
        }

        return null;
    }

    public function setQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    /**
     * Вміст кошика для показу: постачальник → дата → позиції.
     *
     * @return Collection<int, array{supplier: \App\Models\Supplier, dates: Collection<string, array{date: CarbonImmutable, items: Collection<int, CartItem>, total: float, deadline: \App\Services\Deadlines\Deadlines}>, total: float}>
     */
    public function grouped(?Cart $cart): Collection
    {
        $items = $this->items($cart, ['dish', 'supplier', 'menuSection'])
            ->sortBy(['supplier_id', 'service_date']);

        return $items
            ->groupBy('supplier_id')
            ->map(function (Collection $supplierItems): array {
                $dates = $supplierItems
                    ->groupBy(fn (CartItem $item): string => $item->service_date->toDateString())
                    ->map(fn (Collection $dateItems, string $date): array => [
                        'date' => CarbonImmutable::parse($date),
                        'items' => $dateItems->values(),
                        'total' => $dateItems->sum(fn (CartItem $item): float => $item->subtotal()),
                        'deadline' => $this->deadlines->for($dateItems->first()->supplier_id, $date),
                    ]);

                return [
                    'supplier' => $supplierItems->first()->supplier,
                    'dates' => $dates,
                    'total' => $supplierItems->sum(fn (CartItem $item): float => $item->subtotal()),
                ];
            })
            ->values();
    }

    public function total(?Cart $cart): float
    {
        return $this->items($cart, ['dish'])->sum(fn (CartItem $item): float => $item->subtotal());
    }

    public function count(?Cart $cart): int
    {
        return $cart === null ? 0 : (int) $cart->items()->sum('quantity');
    }

    /**
     * Дати цього постачальника, які вже лежать у кошику: меню показує їх
     * як додані й не дає покласти той самий день удруге.
     *
     * @return array<int, string>
     */
    public function datesInCartFor(Supplier $supplier): array
    {
        $cart = $this->currentIfExists();

        if ($cart === null) {
            return [];
        }

        return $cart->items()
            ->where('supplier_id', $supplier->id)
            ->pluck('service_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /** Чи лежить цей день постачальника вже в кошику. */
    public function hasDay(?Cart $cart, int $supplierId, CarbonInterface|string $date): bool
    {
        if ($cart === null) {
            return false;
        }

        return $cart->items()
            ->where('supplier_id', $supplierId)
            ->whereDate('service_date', CarbonImmutable::parse($date)->toDateString())
            ->exists();
    }

    /** Позиції, за якими дедлайн минув, поки кошик лежав незавершеним. */
    public function expiredItems(?Cart $cart): Collection
    {
        return $this->items($cart, ['dish', 'supplier'])
            ->filter(fn (CartItem $item): bool => ! $this->deadlines->canOrder($item->supplier_id, $item->service_date));
    }

    /**
     * @param  array<int, string>  $with
     * @return Collection<int, CartItem>
     */
    private function items(?Cart $cart, array $with = []): Collection
    {
        return $cart === null
            ? collect()
            : $cart->items()->with($with)->get();
    }

    private function assertOrderable(MenuDay $menuDay): void
    {
        if (! $menuDay->is_working_day || $menuDay->published_at === null) {
            throw MenuUnavailableException::dayNotAvailable($menuDay->date);
        }

        // Свято могли додати вже після публікації меню — перевіряємо на сервері.
        if (NonWorkingDay::isHoliday($menuDay->date)) {
            throw MenuUnavailableException::dayNotAvailable($menuDay->date);
        }

        if ($this->isOutsideHorizon($menuDay->date)) {
            throw MenuUnavailableException::outsideHorizon();
        }
    }

    private function isOutsideHorizon(CarbonInterface $date): bool
    {
        return $date->isAfter(CarbonImmutable::today()->addDays(config('school.menu_horizon_days')));
    }

    private function assertDishBelongsToSection(MenuSection $section, int $dishId): void
    {
        $belongs = $section->sectionDishes()->where('dish_id', $dishId)->exists();

        if (! $belongs) {
            $dishName = $section->dishes()->find($dishId)?->name ?? 'страва';

            throw MenuUnavailableException::dishNotInMenu($dishName, $section->menuDay->date);
        }
    }

    private function guestToken(): ?string
    {
        $token = session()->get(self::GUEST_TOKEN_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
