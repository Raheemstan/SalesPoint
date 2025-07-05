<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|string|max:20',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $supplier = Supplier::create([
                'name'    => $request->name,
                'phone'   => $request->phone,
                'email'   => $request->email,
                'address' => $request->address,
            ]);

            AuditLog::create([
                'user_id' => userId(),
                'action'  => 'Created supplier',
                'table_name' => 'suppliers',
                'record_id' => $supplier->id,
                'ip_address' => $request->ip(),
            ]);

            DB::commit();
            return redirect()->back()->with('success', 'Supplier added successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Supplier creation failed', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to add supplier.');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Supplier $supplier)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Supplier $supplier)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Supplier $supplier)
    {
        //
    }
}
