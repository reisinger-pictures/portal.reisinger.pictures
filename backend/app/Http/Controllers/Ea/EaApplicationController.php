<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaApplication;
use App\Models\EaAuditLog;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Anträge (Portal #27).
 *
 * Ein Antrag ist ein fachlicher Vorgang mit dem Status `open` oder `done`.
 * Der Status kann separat per PATCH geändert werden; beide Schreibwege
 * schreiben ein Audit ohne Dateiinhalte.
 */
class EaApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'status' => 'nullable|in:open,done',
        ]);

        $query = EaApplication::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        $applications = $query->orderBy('year')->orderBy('id')->get();

        return response()->json([
            'applications' => $applications->map(fn (EaApplication $application): array => $this->serialize($application))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'type' => 'required|string|max:64',
            'subject' => 'required|string|max:190',
            'status' => 'nullable|in:open,done',
            'note' => 'nullable|string|max:2000',
        ]);

        $application = EaApplication::create([
            'brand' => BrandRegistry::currentId(),
            'year' => (int) ($data['year'] ?? EaYear::currentOrLast()),
            'type' => $data['type'],
            'subject' => $data['subject'],
            'status' => $data['status'] ?? 'open',
            'note' => $data['note'] ?? null,
        ]);

        $this->audit('created', $application);

        return response()->json(['application' => $this->serialize($application)], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $application = EaApplication::forCurrentBrand()->findOrFail($id);

        $data = $request->validate([
            'status' => 'sometimes|required|in:open,done',
            'note' => 'nullable|string|max:2000',
        ]);

        if (isset($data['status'])) {
            $application->status = $data['status'];
        }

        if (array_key_exists('note', $data)) {
            $application->note = $data['note'];
        }

        $application->save();
        $this->audit('status_changed', $application);

        return response()->json(['application' => $this->serialize($application->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EaApplication $application): array
    {
        return [
            'id' => (int) $application->id,
            'year' => (int) $application->year,
            'type' => $application->type,
            'subject' => $application->subject,
            'status' => $application->status,
            'note' => $application->note,
        ];
    }

    private function audit(string $action, EaApplication $application): void
    {
        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'ea_application',
            'subject_id' => $application->id,
            'payload' => [
                'year' => $application->year,
                'type' => $application->type,
                'status' => $application->status,
            ],
        ]);
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
