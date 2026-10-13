<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The audit log viewer. The log itself has been written since the beginning;
 * what is new is being able to read it, which brings its own risks: it joins to
 * the users table, and a careless join or export is how password hashes end up
 * in a spreadsheet.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->actingAs($this->admin);
});

/** A log row written as a given user, optionally backdated. */
function logEntry(User $user, string $action, string $table = 'products', int $recordId = 1, ?string $at = null): AuditLog
{
    $log = AuditLog::create([
        'user_id' => $user->id,
        'action' => $action,
        'table_name' => $table,
        'record_id' => $recordId,
        'ip_address' => '198.51.100.7',
    ]);

    // created_at is not fillable on AuditLog, so backdating has to be forced.
    if ($at) {
        $when = Carbon::parse($at);
        $log->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }

    return $log;
}

test('the log is listed newest first, with the person and the IP address', function () {
    logEntry($this->admin, 'Created Product', 'products', 1, '2026-10-01 09:00:00');
    logEntry($this->admin, 'Voided Sale', 'sales', 2, '2026-10-05 09:00:00');

    $response = $this->get(route('settings.audit'))->assertOk()
        ->assertSee('Ada Admin')
        ->assertSee('198.51.100.7')
        ->assertSee('Voided Sale')
        ->assertSee('Created Product');

    /*
     * Asserted on the paginator rather than on the position of the text in the
     * HTML: the filter dropdowns list every action alphabetically, so the words
     * appear twice on the page and their order there means nothing.
     */
    expect($response->viewData('logs')->pluck('action')->all())
        ->toBe(['Voided Sale', 'Created Product']);
});

test('the user relation is eager loaded, so a page of rows is not a query per row', function () {
    $others = User::factory()->count(5)->cashier()->create();

    foreach ($others as $user) {
        logEntry($user, 'Signed In', 'users', $user->id);
    }

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get(route('settings.audit'))->assertOk();

    /*
     * A handful of queries is the page itself (the count, the rows, the filter
     * dropdowns, the session, the roles). What must not happen is one per row,
     * so the bar is set well below "rows + overhead" rather than at an exact
     * number that would break every time the page grows a widget.
     */
    expect($queries)->toBeLessThan(15);
});

test('the list can be filtered by person, action, table and date range', function () {
    $bola = User::factory()->cashier()->create(['name' => 'Bola Cashier']);

    logEntry($this->admin, 'Created Product', 'products', 1, '2026-10-01 09:00:00');
    logEntry($bola, 'Refunded Sale', 'sales', 2, '2026-10-10 09:00:00');

    /*
     * Checked against the rows the view was handed, not the rendered HTML: the
     * filter dropdowns list every action and table in the log, so "Created
     * Product" is on the page whether or not it is in the results.
     */
    $actions = fn (array $query) => $this->get(route('settings.audit', $query))
        ->assertOk()->viewData('logs')->pluck('action')->all();

    expect($actions(['user_id' => $bola->id]))->toBe(['Refunded Sale'])
        ->and($actions(['action' => 'Created Product']))->toBe(['Created Product'])
        ->and($actions(['table_name' => 'sales']))->toBe(['Refunded Sale'])
        ->and($actions(['from' => '2026-10-05', 'to' => '2026-10-10']))->toBe(['Refunded Sale'])
        // The "to" day is included, not cut off at midnight.
        ->and($actions(['from' => '2026-10-10', 'to' => '2026-10-10']))->toBe(['Refunded Sale'])
        ->and($actions(['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->toBe(['Refunded Sale', 'Created Product']);
});

test('bad filters are rejected rather than ignored', function () {
    $this->get(route('settings.audit', ['user_id' => 99999]))->assertSessionHasErrors('user_id');
    $this->get(route('settings.audit', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    $this->get(route('settings.audit', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
});

test('the list paginates rather than rendering the whole log', function () {
    for ($i = 0; $i < 60; $i++) {
        logEntry($this->admin, 'Action ' . $i, 'products', $i);
    }

    $logs = $this->get(route('settings.audit'))->assertOk()->viewData('logs');

    expect($logs)->toHaveCount(50)
        ->and($logs->total())->toBe(60)
        ->and($logs->first()->action)->toBe('Action 59');  // newest first

    // The oldest rows are on the second page, not on the first.
    expect($this->get(route('settings.audit', ['page' => 2]))->viewData('logs')->last()->action)
        ->toBe('Action 0');
});

test('the CSV export carries the log and no password hashes', function () {
    logEntry($this->admin, 'Created Product', 'products', 1);

    $csv = $this->get(route('settings.audit.csv'))->assertOk()->streamedContent();

    expect($csv)->toContain('Created Product')
        ->toContain('Ada Admin')
        ->toContain('198.51.100.7')
        // the join must not drag the whole users row along with it
        ->not->toContain($this->admin->password)
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain('two_factor');
});

test('the CSV export honours the filters it was opened with', function () {
    $bola = User::factory()->cashier()->create(['name' => 'Bola Cashier']);

    logEntry($this->admin, 'Created Product', 'products', 1);
    logEntry($bola, 'Refunded Sale', 'sales', 2);

    $csv = $this->get(route('settings.audit.csv', ['user_id' => $bola->id]))->streamedContent();

    expect($csv)->toContain('Refunded Sale')->not->toContain('Created Product');
});

test('the CSV export neutralises spreadsheet formulas', function () {
    // An action string that Excel would otherwise run as a formula.
    logEntry($this->admin, '=HYPERLINK("http://evil")', 'products', 1);

    $csv = $this->get(route('settings.audit.csv'))->streamedContent();

    expect($csv)->toContain("'=HYPERLINK");
});

test('exporting the log is itself recorded', function () {
    $this->get(route('settings.audit.csv'))->streamedContent();

    expect(AuditLog::where('action', 'Exported Audit Log')->exists())->toBeTrue();
});

test('a manager cannot read or export the audit log', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('settings.audit'))->assertForbidden();
    $this->get(route('settings.audit.csv'))->assertForbidden();
});

test('a cashier cannot read or export the audit log', function () {
    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('settings.audit'))->assertForbidden();
    $this->get(route('settings.audit.csv'))->assertForbidden();
});

test('a guest is sent to the login page', function () {
    $this->post(route('logout'));

    $this->get(route('settings.audit'))->assertRedirect(route('login'));
});

test('the settings screen links to the audit log for admins only', function () {
    $this->get(route('settings.index'))->assertOk()->assertSee(route('settings.audit'));

    $this->actingAs(User::factory()->manager()->create());
    $this->get(route('settings.index'))->assertOk()->assertDontSee(route('settings.audit'));
});

test('the viewer says so when recording has been switched off', function () {
    Setting::set('enable_audit_log', '0');

    $this->get(route('settings.audit'))->assertOk()->assertSee('switched off', escape: false);
});
