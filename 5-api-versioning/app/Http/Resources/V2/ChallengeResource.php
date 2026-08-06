<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // v2 replaced flat "points" with a richer scoring object.
            'scoring' => [
                'base' => $this->base_points,
                'first_blood_bonus' => $this->first_blood_bonus,
            ],
        ];
    }
}
