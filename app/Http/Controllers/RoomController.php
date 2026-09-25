<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Room;

class RoomController extends Controller
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
    public function store(StoreRoomRequest $request): void
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Room $room): void
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Room $room): void
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoomRequest $request, Room $room): void
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Room $room): void
    {
        //
    }
}
