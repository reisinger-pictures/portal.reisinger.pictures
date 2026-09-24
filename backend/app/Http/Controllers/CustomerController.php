<?php

namespace App\Http\Controllers;

use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerSearchSyncService;
use App\Services\ModelProfileContactService;
use App\Services\ModelProfileEraser;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerSearchSyncService $searchSync,
        private readonly ModelProfileContactService $profileContacts,
    ) {}

    public function index(Request $request)
    {
        $brand = BrandRegistry::currentOrDefault()->value;

        $q = $request->query('q');
        if ($q && strlen($q) >= 2) {
            $customers = Customer::search($q)
                ->query(fn ($query) => $query->where('brand', $brand))
                ->orderBy('created_at', 'desc')
                ->take(20)
                ->get();

            return response()->json(
                $customers->map(fn ($c) => new CustomerResource($c))->values()
            );
        }

        $customers = Customer::where('brand', $brand)->orderBy('created_at', 'desc')->get();

        return response()->json($customers->map(fn ($c) => new CustomerResource($c))->values());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'street' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'uid' => 'nullable|string|max:100',
            'birthdate' => 'nullable|date|before:today|before:-16 years',
        ]);

        $validated['brand'] = BrandRegistry::currentOrDefault()->value;
        $customer = Customer::create($validated);

        return response()->json(['success' => true, 'customer' => new CustomerResource($customer)]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'street' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'uid' => 'nullable|string|max:100',
            'birthdate' => 'nullable|date|before:today|before:-16 years',
        ]);

        $customer = DB::transaction(function () use ($id, $validated): Customer {
            $customer = Customer::forCurrentBrand()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->searchSync->withoutSync(function () use ($customer, $validated): void {
                if ($customer->update($validated) !== true) {
                    throw new RuntimeException('Customer update was cancelled.');
                }
            });

            // A CRM admin can edit the canonical row directly. Keep the
            // encrypted profile snapshot in the same transaction so a later
            // contact-sheet/lifecycle read cannot observe a different address.
            if (array_key_exists('email', $validated) && $customer->modelProfile) {
                $this->profileContacts->syncEmail(
                    $customer->modelProfile,
                    $validated['email'] ?? null,
                );
            }

            return $customer;
        }, 3);

        if ($customer->wasChanged()) {
            $this->searchSync->defer($customer);
        }

        return response()->json(['success' => true, 'customer' => new CustomerResource($customer)]);
    }

    public function destroy($id)
    {
        $customer = Customer::forCurrentBrand()->findOrFail($id);

        // Keep the generic customer delete for ordinary CRM records, but route
        // model customers through the same transactional DSGVO routine as the
        // lifecycle and dedicated model-delete endpoints. This prevents the
        // customer shortcut from bypassing invite/Search cleanup.
        if ($customer->is_model) {
            app(ModelProfileEraser::class)->erase(
                $customer,
                'customer_admin_delete',
                auth('api')->id(),
            );
        } else {
            $customer->delete();
        }

        return response()->json(['success' => true]);
    }
}
