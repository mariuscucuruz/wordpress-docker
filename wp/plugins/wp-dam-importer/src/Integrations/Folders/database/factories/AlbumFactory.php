<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Database\Factories;

use MariusCucuruz\DAMImporter\Models\Team;
use MariusCucuruz\DAMImporter\Models\User;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;
use Illuminate\Database\Eloquent\Factories\Factory;

class AlbumFactory extends Factory
{
    protected $model = Album::class;

    public function definition(): array
    {
        $userId = auth()->id() ?? User::factory();

        return [
            'user_id' => $userId,
            'team_id' => Team::factory()->create(['user_id' => $userId])->id,
            'name'    => $this->faker->words(rand(3, 10), true),
        ];
    }
}
