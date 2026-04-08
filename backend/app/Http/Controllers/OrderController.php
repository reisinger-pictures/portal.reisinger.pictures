<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\InvoiceSequence;
use App\Models\Photo;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::where('user_id', auth()->id())
            ->with('invoiceSnapshot')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($orders);
    }

    public function checkout(Request $request, PricingService $pricingService)
    {
        $user = auth('api')->user();

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.photoId' => 'required|string|exists:photos,id',
            'items.*.tier' => 'required|string|in:web,print,original',
            'items.*.usage' => 'required|string|in:editorial,commercial',
            'items.*.duration' => 'required|string|in:1_year,unlimited',
            'billing_name' => 'required|string|max:255',
            'billing_company' => 'nullable|string|max:255',
            'billing_street' => 'required|string|max:255',
            'billing_zip' => 'required|string|max:20',
            'billing_city' => 'required|string|max:255',
        ]);

        if (!$user->is_power_user && !$user->is_admin) {
            return response()->json(['error' => 'Keine Berechtigung für kostenpflichtige Upgrades.'], 403);
        }

        return DB::transaction(function () use ($request, $user) {
            $totalNet = 0.00;
            $basePrice = (float) (\App\Models\Setting::where('key', 'base_price')->value('value') ?? 35.00); 
            $lineItems = [];

            foreach ($request->items as $item) {
                $photo = Photo::findOrFail($item['photoId']);
                if (!$user->canAccessGallery($photo->gallery_id)) abort(403);

                $resMult = ['web' => 1.0, 'print' => 2.0, 'original' => 4.0][$item['tier']];
                $useMult = ['editorial' => 1.0, 'commercial' => 3.0][$item['usage']];
                $durMult = ['1_year' => 1.0, 'unlimited' => 2.0][$item['duration']];
                
                $requestedPrice = $basePrice * $resMult * $useMult * $durMult;
                
                // Flatrate deckt nur Editorial & 1 Jahr
                $userResMult = ['none' => 0.0, 'web' => 1.0, 'print' => 2.0, 'original' => 4.0][$user->flatrate_level ?? 'none'] ?? 0.0;
                $userPrice = $basePrice * $userResMult * 1.0 * 1.0;
                
                $delta = $requestedPrice - $userPrice;
                if ($delta < 0) $delta = 0;
                $totalNet += $delta;

                $lineItems[] = [
                    'photoId' => $photo->id,
                    'filename' => $photo->filename,
                    'tier' => $item['tier'],
                    'usage' => $item['usage'],
                    'duration' => $item['duration'],
                    'price' => $delta
                ];
            }

            if ($totalNet <= 0) return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);

            $taxRate = 0.00; 
            $totalGross = $totalNet; 

            $user->update([
                'billing_name' => $request->billing_name,
                'billing_company' => $request->billing_company,
                'billing_street' => $request->billing_street,
                'billing_zip' => $request->billing_zip,
                'billing_city' => $request->billing_city,
            ]);

            // Lieferschein Logik prüfen
            $tenant = $user->tenants()->first(); // Nimm primären Mandanten
            $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
            $orderStatus = $isLieferschein ? 'delivery_note' : 'invoice_created';

            $order = Order::create([
                'user_id' => $user->id,
                'status' => $orderStatus,
                'total_amount' => $totalGross
            ]);

            $prefix = $isLieferschein ? 'L-' : 'P-';
            $invoiceNumber = InvoiceSequence::getNextInvoiceNumber($prefix);

            // Immutability: Kompletten Zustand einfrieren
            $allTerms = [
                'editorial' => \App\Models\Setting::where('key', 'term_editorial')->value('value') ?? 'Nur für redaktionelle Berichterstattung zugelassen. Jegliche kommerzielle Nutzung ist untersagt.',
                'commercial' => \App\Models\Setting::where('key', 'term_commercial')->value('value') ?? 'Uneingeschränkte kommerzielle Nutzung ist gestattet.',
                '1_year' => \App\Models\Setting::where('key', 'term_1_year')->value('value') ?? 'Nutzungsrecht befristet auf 1 Jahr.',
                'unlimited' => \App\Models\Setting::where('key', 'term_unlimited')->value('value') ?? 'Zeitlich unbegrenztes Nutzungsrecht.',
                'web' => \App\Models\Setting::where('key', 'term_web')->value('value') ?? 'Auflösung optimiert für Web & Social Media.',
                'print' => \App\Models\Setting::where('key', 'term_print')->value('value') ?? 'Hohe Auflösung für den Druck.',
                'original' => \App\Models\Setting::where('key', 'term_original')->value('value') ?? 'Maximale Originalauflösung.'
            ];

            $usedTerms = [];
            foreach ($lineItems as $li) {
                $usedTerms[$li['usage']] = $allTerms[$li['usage']];
                $usedTerms[$li['duration']] = $allTerms[$li['duration']];
                $usedTerms[$li['tier']] = $allTerms[$li['tier']];
            }

            $snapshot = InvoiceSnapshot::create([
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber,
                'customer_details' => [
                    'name' => $request->billing_name,
                    'email' => $user->email,
                    'company' => $request->billing_company,
                    'street' => $request->billing_street,
                    'zip' => $request->billing_zip,
                    'city' => $request->billing_city,
                    'country' => 'Österreich',
                    'items' => $lineItems,
                    'terms' => $usedTerms // Gefrorene Bestellpositionen
                ],
                'total_net' => $totalNet,
                'total_gross' => $totalGross,
                'tax_rate' => $taxRate
            ]);

            // Asynchroner Versand via Laravel Queue
            Mail::to($user->email)->send(new InvoiceMail($order, $snapshot));

            return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $invoiceNumber]);
        });
    }

    public function downloadInvoice($id)
    {
        $order = Order::where('id', $id)->where('user_id', auth()->id())->with('invoiceSnapshot')->firstOrFail();
        $snapshot = $order->invoiceSnapshot;

        if (!$snapshot) {
            abort(404, 'Rechnungsdaten nicht gefunden.');
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'snapshot' => $snapshot,
            'items' => $snapshot->customer_details['items'] ?? []
        ]);

        return $pdf->download($snapshot->invoice_number . '.pdf');
    }

    public function indexAdmin()
    {
        $orders = Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc')->get();
        return response()->json($orders);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:invoice_created,paid,overdue,cancelled']);
        $order = Order::findOrFail($id);
        $order->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }
}