<?php

namespace App\Http\Controllers;

use App\Models\OfflineSaleAttempt;
use App\Services\OfflineSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Replaying sales that were rung up while the till was offline.
 *
 * Two endpoints, both answering JSON to the till's own JavaScript:
 *
 *   POST /pos/sync         - here is a batch of queued sales; what became of each?
 *   GET  /pos/sync/status  - is anything waiting for a person?
 *
 * The thinking - what is banked, what is refused and why - all lives in
 * OfflineSyncService, and the sale itself is created by CheckoutService, the
 * same code the online till goes through. This class validates the envelope,
 * authorises, and shapes the answer.
 *
 * WHY THE BATCH SHAPE IS VALIDATED HERE AND EACH SALE IS NOT
 *
 * A malformed sale must reject only itself. Validating `sales.*.whatever` with
 * request rules would fail the whole request on one bad line, which means a till
 * with one corrupt queue entry could never bank any of the good ones - the
 * entries in front of it would be stuck behind it for ever. So the request only
 * has to be "an array of at most MAX_BATCH things", and each thing is validated
 * on its own inside the service, where a failure is a verdict rather than a 422.
 */
class OfflineSyncController extends Controller
{
    /**
     * Bank a batch of queued sales and answer with a verdict for each.
     *
     * The response is per-sale on purpose: the till has to know exactly which
     * entries to clear from its queue (`accepted`, `duplicate`) and which to keep
     * and put in front of a human (`rejected`). A single overall status code
     * cannot say that, so the HTTP status only reports whether the batch was
     * understood - the money is in the body.
     */
    public function store(Request $request, OfflineSyncService $sync)
    {
        Gate::authorize('create', OfflineSaleAttempt::class);

        $data = $request->validate([
            'sales' => ['required', 'array', 'min:1', 'max:' . OfflineSyncService::MAX_BATCH],
            /*
             * Deliberately no `sales.*` rules at all - not even "must be an
             * array". A rule here fails the whole request, which would mean one
             * corrupt queue entry permanently blocking every good sale behind it
             * in that till's queue. Each entry is checked in the service instead,
             * where "unreadable" is a recorded verdict rather than a 422.
             */
        ], [
            'sales.max' => 'Send at most ' . OfflineSyncService::MAX_BATCH
                . ' queued sales at a time, then sync again for the rest.',
        ]);

        $verdicts = $sync->replay($data['sales'], $request->user());

        return response()->json([
            'results' => $verdicts,
            'summary' => [
                'accepted' => $this->countOf($verdicts, OfflineSaleAttempt::ACCEPTED),
                'duplicate' => $this->countOf($verdicts, OfflineSaleAttempt::DUPLICATE),
                'rejected' => $this->countOf($verdicts, OfflineSaleAttempt::REJECTED),
            ],
            // What is still outstanding after this batch, so the till's indicator
            // is right without a second request.
            'status' => $sync->summaryFor($request->user()),
        ]);
    }

    /**
     * What the till needs to show: how many offline sales were refused and are
     * waiting for somebody, and what they were.
     */
    public function status(Request $request, OfflineSyncService $sync)
    {
        Gate::authorize('viewAny', OfflineSaleAttempt::class);

        return response()->json($sync->summaryFor($request->user()));
    }

    /** @param  array<int, array<string, mixed>>  $verdicts */
    private function countOf(array $verdicts, string $status): int
    {
        return count(array_filter($verdicts, fn (array $verdict) => $verdict['status'] === $status));
    }
}
