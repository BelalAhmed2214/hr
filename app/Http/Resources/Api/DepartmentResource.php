<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            "id" => $this->id,
            "name" => $this->name,
            "manager_id" => $this->manager_id,
            'manager_name' => $this->manager ? $this->manager->name : null,
        ];
    }
}
