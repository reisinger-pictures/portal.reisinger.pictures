# Org-Admin Role — DSGVO-konforme Organisationsverwaltung

Status: SOLL (Target State)
Stand: 2026-07-03

---

## 1. Konzept

Der **Org-Admin** (Rolle `org_admin`, ehemals `customer_manager`) ist der administrative
Ansprechpartner einer Organisation. Anders als `super_admin` (der systemweit agiert) ist
der Org-Admin **strikt auf seine eigene Organisation beschränkt** — dies ist die
DSGVO-Grundlage: Kein Org-Admin darf Daten ausserhalb seiner Organisation einsehen.

### Mapping User → Organisation (1:n statt n:m)

Bisher gab es eine n:m-Beziehung via `tenant_user`-Pivot. **Neu:** Ein User gehört zu
**maximal einer** Organisation. Die Zuordnung erfolgt direkt über `users.tenant_id`.

```
users.tenant_id → tenants.id (FK, nullable)
```

- Ein User kann eine Organisation haben, muss aber nicht (`tenant_id` = NULL erlaubt)
- Super-Admins und Admins haben keine Org (`tenant_id` = NULL)
- Org-Admin User muss zwingend eine Org haben (`tenant_id` IS NOT NULL)
- Photographer/Client können optional einer Org zugeordnet sein (für Org-spezifische
  Flatrates, automatische Galerie-Zugriffe etc.)

### Rollenabgrenzung

| Rolle | Systemweit | Hat Org | Upgrades kaufen | User anlegen | Org bearbeiten |
|-------|-----------|---------|----------------|--------------|---------------|
| `super_admin` | ✅ Vollzugriff | ❌ | ✅ | ✅ | ✅ |
| `admin` | ✅ Vollzugriff | ❌ | ✅ | ✅ | ✅ |
| `org_admin` | ❌ | ✅ (eigene) | ❌ | ✅ (eigene Org) | ✅ (eigene Org) |
| `photographer` | ❌ | optional | ✅ | ❌ | ❌ |
| `client` | ❌ | optional | ❌ | ❌ | ❌ |

---

## 2. DB-Änderungen (in V019)

### `users`-Tabelle

```php
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('tenant_id')
        ->nullable()
        ->constrained('tenants')
        ->nullOnDelete();
    $table->index('tenant_id');
});
```

### `tenant_user`-Pivot

Die Hauptzuordnung erfolgt via `users.tenant_id`. `tenant_user` wird in V019 gelöscht (`DROP TABLE`).

---

## 3. Model-Änderungen

### User Model

```php
// Relation
public function tenant(): BelongsTo
{
    return $this->belongsTo(Tenant::class);
}

// Attribute
public function getIsOrgAdminAttribute(): bool
{
    return $this->role === 'org_admin' && $this->tenant_id !== null;
}

// @deprecated — Alias für Migration
public function getIsCustomerManagerAttribute(): bool
{
    return $this->is_org_admin;
}

// Scope
public function scopeByTenant(Builder $query, string $tenantId): Builder
{
    return $query->where('tenant_id', $tenantId);
}
```

### Tenant Model

Die `$fillable` + `$casts` Erweiterungen (auto_join_policy, default_role_id,
default_flatrate_level, can_purchase_upgrades) bleiben wie in Phase 0a implementiert.

---

## 4. Controller-Änderungen

### 4.1. TenantController

```php
// index() — org_admin sieht nur die eigene Org
public function index()
{
    $user = auth()->user();
    if ($user->is_admin) {
        $tenants = Tenant::all();
    } elseif ($user->is_org_admin) {
        $tenants = Tenant::where('id', $user->tenant_id)->get();
    } else {
        abort(403);
    }
    return response()->json($tenants);
}

// update() — org_admin darf nur eigene Org editieren
public function update(Request $request, $id)
{
    $user = auth()->user();
    if (!$user->is_admin && !($user->is_org_admin && $user->tenant_id === $id)) {
        abort(403);
    }
    // ...
}

// destroy() — org_admin darf nicht löschen
public function destroy($id)
{
    if (!auth()->user()->is_admin) abort(403);
    // ...
}

// store() — org_admin darf nicht erstellen
public function store(Request $request)
{
    if (!auth()->user()->is_admin) abort(403);
    // ...
}
```

### 4.2. UserController

```php
// store() — org_admin erstellt User in seiner Org
public function store(StoreUserRequest $request)
{
    $user = auth()->user();
    $data = $request->validated();

    if ($user->is_org_admin) {
        $data['tenant_id'] = $user->tenant_id; // User gehört zur gleichen Org
    }

    return User::create($data);
}

// destroy() — org_admin darf nur User in eigener Org löschen
public function destroy($id)
{
    $user = auth()->user();
    $targetUser = User::findOrFail($id);

    if (!$user->is_admin && !($user->is_org_admin && $targetUser->tenant_id === $user->tenant_id)) {
        abort(403);
    }

    $targetUser->delete();
}
```

### 4.3. AuthController

```php
// me() — is_org_admin im Response
public function me()
{
    $user = auth()->user();
    return response()->json([
        'user' => $user,
        'is_org_admin' => $user->is_org_admin,
    ]);
}

// Auto-Join (auto_join_policy auswerten)
// Bei Auto-Join wird user->tenant_id auf die gefundene Org gesetzt
// Brand-Prüfung: User-Brand muss mit Org-Brand übereinstimmen, sonst 403
```

### 4.4. Gate `manage-users`

```php
Gate::define('manage-users', fn ($user) => $user->is_admin || $user->is_org_admin);
```

Erlaubt org_admin den Zugriff auf User-Verwaltung (Liste, Erstellen), aber der
Controller-Scope stellt sicher, dass nur User der eigenen Org sichtbar/syncbar sind.

### 4.5. StatsController

```php
// Scope per tenant_id statt E-Mail-Domain
public function index()
{
    $user = auth()->user();
    $query = Stats::query();

    if ($user->is_org_admin) {
        $query->whereHas('user', fn ($q) => $q->where('tenant_id', $user->tenant_id));
    }

    return $query->get();
}
```

---

## 5. ManagementMiddleware

Keine Änderung an den Middleware-Pfaden nötig — `is_customer_manager` → `is_org_admin`
ersetzt den Check. Der Scope erfolgt in den Controllern (s.o.).

---

## 6. Frontend-Änderungen

### Permissions

```typescript
// usePermissions.ts
const isOrgAdmin = user?.is_org_admin ?? false;
const isStaff = user?.is_admin || user?.is_super_admin || isOrgAdmin;
```

### Sidebar

- Org-Link für `isOrgAdmin`: `Organisationen` (ohne "B2B")
- org_admin sieht **nur seine eine Org** in der Liste
- Kein "Organisation anlegen" Button für org_admin

---

## 7. Invite-Flow

### 7.1. TenantInviteController::redeem

```php
public function redeem(Request $request)
{
    $user = auth('api')->user();

    if ($user) {
        // Bereits eingeloggt: tenant_id setzen, kein Name/Passwort nötig
        $request->validate(['token' => 'required|string', 'accept_privacy' => 'required|accepted']);
        $invite = TenantInvite::where('token', $request->token)->firstOrFail();
        $user->update(['tenant_id' => $invite->tenant_id]);
    } else {
        // Neuer User: Registrierung + tenant_id setzen
        $request->validate(['token' => 'required|string', 'name' => 'required|string|max:255', 'password' => 'required|string|min:8', 'accept_privacy' => 'required|accepted']);
        $invite = TenantInvite::where('token', $request->token)->firstOrFail();
        $user = User::create([
            'name' => $request->name,
            'email' => $invite->email,
            'password' => bcrypt($request->password),
            'tenant_id' => $invite->tenant_id,
        ]);
    }
}
```

---

## 9. Geklärte Punkte

| Punkt | Entscheidung |
|-------|-------------|
| User → Org | 1:n via `users.tenant_id` (FK, nullable) |
| Rolle bei Entfernen aus Org | `tenant_id = NULL` + Rollen-Zuordnungen werden gelöscht. User hat danach keine aktive Rolle. |
| `tenant_user`-Pivot | Wird in V019 gelöscht (`DROP TABLE`). |
| Migration | Alles in V019 (Settings PK, FKs, tenant_id, tenant_user drop, rename). |
| Invite-Rolle | Keine Rolle. Eingeladener User bekommt nur `tenant_id` gesetzt. Mitgliedschaft wird durch `tenant_id != null` definiert, Rollen durch bestehendes Gate-System.
