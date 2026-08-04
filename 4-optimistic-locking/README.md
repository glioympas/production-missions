# Optimistic Locking

Notes + a working Laravel implementation of optimistic locking. This is the thing that stops two people editing the same row from silently overwriting each other's changes — the "lost update" problem.

## The problem this solves

You've got an admin panel where people edit records. Two organizers open the same challenge at once. A changes the points from 500 to 300 and saves. B — whose form still shows 500 because he loaded the page before A's change — fixes a typo in the description and saves. B's form submits the old points (500), silently overwriting A's change back to 500. A's work is gone and nobody notices.

That's the lost update: two people edit the same row from stale data, and whoever saves last clobbers the other. It's easy to miss because both saves "succeed" — there's no error, just quietly lost work.

## Two ways to handle concurrent edits

**Pessimistic locking** assumes conflict will happen, so it locks the row the moment someone starts editing. Safe, but heavy: you hold locks, block other people, and if someone opens the edit form and walks away, the row stays locked. You really don't want to hold a database lock across a human's "think time."

**Optimistic locking** assumes conflict probably won't happen — two people editing the exact same row in the same few seconds is rare — so it doesn't lock anything. It just detects a clash at save time and rejects the loser. You only pay a cost when a conflict actually happens, which is almost never. For admin forms, this is the right call.

## How it works

Add a `version` column that starts at 1. Every update is guarded:

```sql
UPDATE challenges SET points = 300, version = version + 1
WHERE id = 42 AND version = 5;
```

The `AND version = 5` means "only apply this if nobody has changed the row since I loaded it." If someone already bumped the version to 6, this update matches 0 rows. That **0 rows affected** is the whole mechanism — it means "someone got here before you, your data is stale." You reject the save, return a 409, and tell the user to reload.

Trace the two organizers:

```
Both load challenge 42, version = 5.

A saves:
  UPDATE ... SET points = 300, version = 6 WHERE id = 42 AND version = 5
  -> 1 row affected. Success. Version is now 6.

B saves (form still thinks version = 5):
  UPDATE ... SET description = '...', version = 6 WHERE id = 42 AND version = 5
  -> 0 rows affected! Version is 6, not 5. Rejected.
```

A's change survives. B is told to reload and redo his edit on fresh data instead of silently overwriting.

Optimistic locking doesn't *prevent* the conflict — it *detects* it and refuses the stale write.

## The single most important detail: the check must be in SQL

The whole thing hinges on the version check being part of the UPDATE statement, not done separately in PHP. This is worth understanding deeply because it's the entire trick.

If you did the check in PHP instead:

```php
$current = Challenge::find($id);
if ($current->version !== $expectedVersion) {   // check
    throw new StaleException;
}
$current->update([...]);                         // then act
```

...you'd have a race condition. Those are two separate database operations with a gap between them. Two requests can both read version 5, both pass the PHP check, and both update — the lost update is back:

```
A: read version 5
B: read version 5
A: PHP check 5===5 ✓ -> update to 6
B: PHP check 5===5 ✓ -> update to 6   (clobbered A)
```

Doing it as one SQL statement fuses the check and the write into a single atomic operation:

```sql
UPDATE ... SET version = 6 WHERE id = 42 AND version = 5
```

The database guarantees this is indivisible — nothing can happen "in the middle," because there is no middle. By the time B's update runs, A already bumped the version, so B's `WHERE version = 5` matches nothing. Exactly one wins.
### The circular trap (worth knowing)

"Can't I make the PHP check safe?" Yes — by adding a lock (`lockForUpdate` or a Redis lock) so only one request runs at a time. But once you've added a lock, the lock is already doing the whole job, and the version check is dead weight. You've just rebuilt pessimistic locking with extra useless steps. The takeaway: the "safe way to check in PHP" is to not need optimistic locking anymore. Which is exactly why the check goes in the SQL. That one line isn't a workaround — it's the mechanism that lets optimistic locking work without a lock.
## Implementation notes

The logic lives in a small reusable trait, `HasOptimisticLocking`, with one method: `updateWithVersion($attributes, $expectedVersion)`. Drop it on any model that has a `version` column.

```php
public function updateWithVersion(array $attributes, int $expectedVersion): bool
{
    $column = $this->getVersionColumn();

    $affected = $this->newQueryWithoutScopes()
        ->whereKey($this->getKey())
        ->where($column, $expectedVersion)
        ->update([
            ...$attributes,
            $column => $expectedVersion + 1,
        ]);

    if ($affected === 0) {
        throw new StaleModelException($this);
    }

    $this->refresh();

    return true;
}
```

A few decisions in there worth explaining:

**Why `$expectedVersion` is an explicit argument.** The version the client saw comes from the request. Passing it in directly — `updateWithVersion([...], expectedVersion: 5)` — reads clearly and keeps the method honest.

**Why `newQueryWithoutScopes()`.** Global scopes (like soft deletes' `WHERE deleted_at IS NULL`, or a tenant scope) silently add conditions to every query. If one snuck into our guard, the UPDATE could match 0 rows for the wrong reason — e.g. the row was soft-deleted, not version-changed.

**Why `refresh()` at the end.** The update runs on a query builder, so it changes the database row but leaves the in-memory `$challenge` object holding its old values. `refresh()` reloads the object's attributes from the database so it reflects reality — otherwise the object would return stale values and falsely look like it has unsaved changes. There's a lower-level way to do this without the extra query (`forceFill(...)->syncOriginal()`), which is more efficient but fiddlier; for a form-save operation, one extra SELECT is meaningless and `refresh()` reads far cleaner. Reach for the manual sync only if this runs in a hot loop.

The controller passes the client's version straight through and returns a 409 on conflict:

```php
$challenge->updateWithVersion(
    $request->only('title', 'description', 'points'),
    expectedVersion: $request->integer('version'),
);
```

## Files

- `app/Concerns/HasOptimisticLocking.php` — the reusable trait
- `app/Exceptions/StaleModelException.php` — thrown on conflict (can render itself as a 409)
- `app/Models/Challenge.php`
- `app/Http/Controllers/ChallengeController.php`
- `database/migrations/*_create_challenges_table.php`
- `database/factories/ChallengeFactory.php`
- `tests/Feature/OptimisticLockingTest.php`

## Testing

The tests reproduce the lost update, then prove it's caught:

- update succeeds and bumps the version
- a stale update (wrong expected version) throws
- the lost update does NOT happen — A's change survives even after B tries to save stale data
- the endpoint returns 409 on conflict, and the stale value is not written

The key test is "the lost update does not happen." Temporarily remove the `->where($column, $expectedVersion)` guard from the trait and watch it fail — B clobbers A back to the old value. That failure is the exact bug optimistic locking prevents.

## When to use this (and when not to)

Use optimistic locking when concurrent edits are possible but rare, and there's human "think time" between loading and saving — admin forms, settings pages, profile edits. The whole value is that it's lock-free.

## The one-line version

Optimistic locking prevents the lost update — two users editing the same row from stale data, last save wins. You add a `version` column and guard each update with `WHERE version = <what I loaded>`, bumping it on success; 0 rows affected means someone got there first, so you reject with a 409. The check lives in the SQL, not PHP, because that's what makes it atomic and lock-free — moving it to PHP either reopens the race or forces you into pessimistic locking, at which point the version check is pointless.
