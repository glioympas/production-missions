# API Versioning

Notes + a working Laravel implementation of API versioning. This is how you change a response shape without breaking every client that already depends on the old one.

## The problem this solves

Your API is a contract. The moment clients depend on a response shape, changing it is a breaking change. Say `/challenges` returns points as a flat integer:

```json
{ "id": 1, "title": "SQL Injection 101", "points": 500 }
```

Now you're adding first-blood bonuses, and the clean way to model that is a richer object:

```json
{ "id": 1, "title": "SQL Injection 101", "scoring": { "base": 500, "first_blood_bonus": 100 } }
```

You can't just swap the response. Hundreds of clients read `response.points` — mobile apps, dashboards, teams' scripts. The second you replace `points` with `scoring`, all of them break at once. Versioning lets both shapes live side by side: old clients keep getting `points`, new clients get `scoring`, and everyone migrates on their own schedule instead of yours.

## Not every change needs a version

Worth being clear on this, because over-versioning is its own mess. Adding a field is backward-compatible — old clients just ignore it, nothing breaks, no new version needed. You only version for breaking changes: removing a field, renaming one, or changing its type or meaning. Renaming `points` to `scoring.base` is breaking. Adding a `difficulty` field is not.

## The three ways to version

- **URL / path** — `/api/v1/challenges`, `/api/v2/challenges`. Most visible, easiest to test (you can hit it in a browser), cache-friendly (the URL is the cache key). Most common.
- **Header** — same URL, version in an `Accept` header like `application/vnd.ctf.v2+json`. Cleaner URLs, considered more "RESTful," but less discoverable and harder to test in a browser. Also needs `Vary: Accept` so caches don't serve the wrong version.
- **Hostname** — `v1.api.example.com`. Full isolation, heavier infra.

This mission uses **URL versioning**. It's the pragmatic default — visible, testable, obvious — and it naturally gives you clean separate controllers per version.

## The key idea: version the edge, not the core

The mistake people make is duplicating everything per version — two copies of the controller, the query, the business logic. That's a maintenance nightmare where every bug fix has to be applied twice.

The right approach: **only the response shape differs between versions. The data and business logic stay single and shared.** In Laravel the response shape lives in the API Resource (the class that turns a model into JSON), so you keep one model, one set of logic, and a separate Resource per version.

That's the whole trick — version the thin presentation edge, share the thick core.

## How it's built

**One model, one set of columns.** The database stores `base_points` and `first_blood_bonus` separately. This never changes between versions — only how it's presented does.

**Two Resources, one per version.** This is where versions actually differ:

```php
// V1 — the old flat shape
class ChallengeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'points' => $this->base_points,   // v1's flat integer
        ];
    }
}

// V2 — the richer shape
class ChallengeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'scoring' => [                     // v2's object
                'base' => $this->base_points,
                'first_blood_bonus' => $this->first_blood_bonus,
            ],
        ];
    }
}
```

Same model, same columns, two different JSON shapes. No business logic duplicated.

**Two thin controllers,** one per version, each importing its own Resource. They're nearly identical — the only real difference is which Resource they wrap the data in. In a bigger app the shared query/logic would live in a service both call; here it's simple enough to show directly.

**Routes with version prefixes,** no `if version == 1` branching anywhere:

```php
Route::prefix('v1')
    ->middleware(AddDeprecationHeaders::class.':Wed, 31 Dec 2025 23:59:59 GMT')
    ->group(function () {
        Route::get('/challenges', [V1ChallengeController::class, 'index']);
        Route::get('/challenges/{challenge}', [V1ChallengeController::class, 'show']);
    });

Route::prefix('v2')->group(function () {
    Route::get('/challenges', [V2ChallengeController::class, 'index']);
    Route::get('/challenges/{challenge}', [V2ChallengeController::class, 'show']);
});
```

`Route::prefix('v1')` puts everything under `/api/v1/...`. v1 carries the deprecation middleware; v2 doesn't. Clean separation.

## Deprecating a version properly

You don't kill v1 overnight — you announce a sunset and give clients a long runway. HTTP has standard headers for exactly this, attached by a small middleware on the v1 route group:

- **`Sunset`** (RFC 8594) — an HTTP-date saying when the version will be removed.
- **`Warning`** — a human-readable "this is deprecated, migrate to v2."
- **`Link`** with `rel="sunset"` — points to the migration guide.

```php
$response->headers->set('Sunset', $sunset);
$response->headers->set('Warning', '299 - "This API version is deprecated. Please migrate to v2."');
$response->headers->set('Link', '<https://docs.ctf.example/api/migration>; rel="sunset"');
```

The sunset date is a middleware parameter, so the same middleware deprecates any version. Typical runway from the best-practice guides: a 6-month announcement, 12 months of active migration support, 18–24 months total before removal. And critically — **monitor usage per version** so you don't pull one while clients still depend on it.

## Files

- `app/Http/Resources/V1/ChallengeResource.php` — the old flat shape
- `app/Http/Resources/V2/ChallengeResource.php` — the new richer shape
- `app/Http/Controllers/Api/V1/ChallengeController.php`
- `app/Http/Controllers/Api/V2/ChallengeController.php`
- `app/Http/Middleware/AddDeprecationHeaders.php`
- `app/Models/Challenge.php`
- `database/migrations/*_create_challenges_table.php`
- `database/factories/ChallengeFactory.php`
- `routes/api.php`
- `tests/Feature/ApiVersioningTest.php`

## Testing

The tests prove the versions are truly isolated:

- v1 returns the flat `points` shape and does NOT leak `scoring`
- v2 returns the `scoring` object and does NOT keep `points`
- v1 sends the deprecation headers (Sunset, Warning, Link)
- v2 does NOT send deprecation headers

The `assertJsonMissingPath` checks are the important ones, they catch the mistake of accidentally wiring v1 to the v2 Resource (or vice versa). If the versions bled into each other, those fail.

## The one-line version

API versioning lets you evolve a response contract without breaking existing clients — old clients keep the old shape, new clients get the new one, everyone migrates on their own schedule. Version only the presentation layer (a Resource per version), keeping one model and one set of business logic; don't duplicate everything. URL versioning (`/v1/`, `/v2/`) is the pragmatic default and gives clean separate controllers per version. Retire old versions gracefully with Sunset/Warning/Link headers, a long runway, and usage monitoring. And remember: only breaking changes need a version — adding a field is backward-compatible and needs none.
