<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Cashier shifts: open with a float, close by counting the drawer.
 *
 * Nothing here works out money from the request. The expected contents of the
 * drawer always come back from ShiftService, which reads the database; the only
 * figures accepted from the browser are the ones a person physically counted.
 */
class ShiftController extends Controller
{
    /** decimal(15,2) holds 13 digits before the point; anything larger is a typo. */
    private const MAX_MONEY = 9999999999999;

    public function index(Request $request, ShiftService $shifts)
    {
        Gate::authorize('viewAny', Shift::class);

        $user = $request->user();
        $manager = $user->hasAnyRole(['admin', 'manager']);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|in:' . Shift::STATUS_OPEN . ',' . Shift::STATUS_CLOSED,
            'user' => 'nullable|integer',
        ]);

        $query = Shift::with(['user', 'closedBy'])->latest('opened_at');

        if ($manager) {
            $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->subDays(6)->startOfDay();
            $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

            $query->whereBetween('opened_at', [$from, $to])
                ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->user));
        } else {
            /*
             * Cashiers see their own drawers and nobody else's, however the URL is
             * tampered with. No date window: these are their own records, and a
             * hidden one would quietly lose an old shift they were looking for.
             */
            $from = now()->startOfDay();
            $to = now()->endOfDay();
            $query->where('user_id', $user->id);
        }

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->status));

        $shiftList = $query->paginate(20)->withQueryString();

        // The viewer's own open drawer, with a live expected figure: this is the
        // number a cashier checks against the drawer during the day.
        $open = $shifts->currentFor($user);
        $expected = $open ? $shifts->expectedByMethod($open) : null;

        // Managers also need to see whose drawer is still open, including people who went home.
        $otherOpen = $manager
            ? Shift::open()->with('user')->where('user_id', '!=', $user->id)->get()
            : collect();

        $cashiers = $manager ? User::orderBy('name')->get(['id', 'name']) : collect();

        return view('shifts.index', [
            'shifts' => $shiftList,
            'open' => $open,
            'expected' => $expected,
            'otherOpen' => $otherOpen,
            'cashiers' => $cashiers,
            'manager' => $manager,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function open(Request $request, ShiftService $shifts)
    {
        Gate::authorize('create', Shift::class);

        $data = $request->validate([
            'opening_float' => 'required|numeric|min:0|max:' . self::MAX_MONEY,
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $shift = $shifts->open($request->user(), (float) $data['opening_float'], $data['note'] ?? null);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('shifts.index')
            ->with('success', 'Shift opened with a float of ' . money($shift->opening_float) . '.');
    }

    /** The end-of-day (Z) report for one shift. */
    public function show(Shift $shift, ShiftService $shifts)
    {
        Gate::authorize('view', $shift);

        $shift->load(['user', 'closedBy']);

        return view('shifts.show', [
            'shift' => $shift,
            'summary' => $shifts->summarise($shift),
        ]);
    }

    public function close(Request $request, Shift $shift, ShiftService $shifts)
    {
        Gate::authorize('close', $shift);

        /*
         * Only the counted figures are read from the request. Any expected or
         * variance field the browser sends is ignored outright - the whole control
         * rests on the expected side being recomputed here.
         */
        $data = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:' . self::MAX_MONEY,
            'counted_pos' => 'required|numeric|min:0|max:' . self::MAX_MONEY,
            'counted_transfer' => 'required|numeric|min:0|max:' . self::MAX_MONEY,
            'close_note' => 'nullable|string|max:500',
        ]);

        $counted = [];
        foreach (Shift::METHODS as $method) {
            $counted[$method] = $data['counted_' . $method];
        }

        try {
            $shift = $shifts->close($shift, $counted, $data['close_note'] ?? null, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $variance = $shift->variance_total;
        $verdict = $variance == 0.0
            ? 'The drawer balanced.'
            : ($variance > 0
                ? 'The drawer is over by ' . money($variance) . '.'
                : 'The drawer is short by ' . money(abs($variance)) . '.');

        return redirect()->route('shifts.show', $shift)->with('success', 'Shift closed. ' . $verdict);
    }
}
