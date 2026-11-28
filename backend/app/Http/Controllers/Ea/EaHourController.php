<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaAuditLog;
use App\Models\EaHour;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Stundenbuch (Portal #27).
 *
 * Zeit ist ausschließlich ganze Minuten; eine Buchung benötigt eine Dezimal-
 * position > 0. Ein 5-%-Anteil ist hier nicht relevant (der Gesamtstunden-
 * bestand ist fachlich nicht auf 5-%-Schritte beschränkt).
 */
class EaHourController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $query = EaHour::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['month'])) {
            $query->where('month', (int) $data['month']);
        }

        $hours = $query->orderBy('year')->orderBy('month')->orderBy('day')->orderBy('id')->get();

        return response()->json([
            'hours' => $hours->map(fn (EaHour $hour): array => $this->serialize($hour))->values(),
            'total_minutes' => (int) $hours->sum('minutes'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'day' => 'required|integer|min:1|max:31',
            'minutes' => 'required|integer|gt:0',
            'project' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:190',
        ]);

        $hour = EaHour::create([
            'brand' => BrandRegistry::currentId(),
            'year' => (int) ($data['year'] ?? EaYear::currentOrLast()),
            'month' => (int) $data['month'],
            'day' => (int) $data['day'],
            'minutes' => (int) $data['minutes'],
            'project' => $data['project'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => 'created',
            'subject_type' => 'ea_hour',
            'subject_id' => $hour->id,
            'payload' => [
                'year' => $hour->year,
                'month' => $hour->month,
                'day' => $hour->day,
                'minutes' => $hour->minutes,
            ],
        ]);

        return response()->json(['hour' => $this->serialize($hour)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EaHour $hour): array
    {
        return [
            'id' => (int) $hour->id,
            'year' => (int) $hour->year,
            'month' => (int) $hour->month,
            'day' => (int) $hour->day,
            'minutes' => (int) $hour->minutes,
            'project' => $hour->project,
            'description' => $hour->description,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(): array
    {
        return ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }
}
