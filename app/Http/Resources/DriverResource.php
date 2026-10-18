<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->driver_id,
            'tipo' => 'driver',
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'cnh' => $this->cnh,
            'status_online' => $this->status_online,
            'avatar' => $this->avatar,
            'rating' => (float) $this->getRating(),
            'vehicles' => VehicleResource::collection($this->whenLoaded('vehicles')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}