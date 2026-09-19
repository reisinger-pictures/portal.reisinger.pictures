<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Http\Controllers\Concerns\AdminOnly;
use App\Mail\ModelInviteMail;
use App\Models\ModelRegistrationInvite;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Admin-Verwaltung der Model-Registrierungs-Einladungen.
 *
 * Primärflow ist der kopierbare Magic Link (z. B. für WhatsApp); der optionale
 * E-Mail-Versand erfolgt nur, wenn eine Adresse angegeben wurde. Erzeugt einen
 * 64-Zeichen-Einmal-Token (7 Tage gültig) und liefert den brand-aware
 * Registrierungslink in der Antwort zurück.
 */
class ModelInviteController extends Controller
{
    use AdminOnly;

    public function index(Request $request)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $invites = ModelRegistrationInvite::with('inviter')
            ->where('brand', $brand)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ModelRegistrationInvite $invite) => $this->serialize($invite))
            ->values();

        return response()->json($invites);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $brand = $this->adminBrand();
        $email = $validated['email'] ?? null;

        $invite = ModelRegistrationInvite::create([
            'email' => $email,
            'label' => $validated['label'] ?? null,
            'brand' => $brand,
            'token' => Str::random(64),
            'invited_by' => auth('api')->id(),
            'expires_at' => now()->addDays(7),
        ]);

        $link = $this->inviteLink($invite, $brand);

        // E-Mail-Versand ist optional: Primärflow ist der kopierbare Magic Link.
        if ($email !== null && $email !== '') {
            Mail::to($email)->queue(
                new ModelInviteMail($link, Brand::tryFrom($brand))
            );
        }

        return response()->json([
            'success' => true,
            'link' => $link,
            'invite' => $this->serialize($invite->load('inviter')),
        ], 201);
    }

    public function destroy(string $id)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $invite = ModelRegistrationInvite::where('brand', $brand)->findOrFail($id);
        $invite->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ModelRegistrationInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'email' => $invite->email,
            'label' => $invite->label,
            'link' => $this->inviteLink(
                $invite,
                $invite->brandValue() ?? BrandRegistry::currentOrDefault()->value
            ),
            'brand' => $invite->brandValue(),
            'status' => $invite->status(),
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'used_at' => $invite->used_at?->toIso8601String(),
            'act_id' => $invite->act_id,
            'customer_id' => $invite->customer_id,
            'invited_by' => $invite->inviter?->name,
            'created_at' => $invite->created_at?->toIso8601String(),
        ];
    }

    private function inviteLink(ModelRegistrationInvite $invite, string $brand): string
    {
        return BrandRegistry::frontendUrl(Brand::tryFrom($brand))
            .'/model-registrierung/'.$invite->token;
    }
}
