<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomCategoryRequest;
use App\Http\Requests\UpdateRoomCategoryRequest;
use App\Models\RoomCategory;

class RoomCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): void
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): void
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoomCategoryRequest $request): void
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(RoomCategory $roomCategory): void
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RoomCategory $roomCategory): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoomCategoryRequest $request, RoomCategory $roomCategory): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RoomCategory $roomCategory): void
    {
        //
    }
}
