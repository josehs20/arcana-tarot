<?php

namespace Database\Factories;

use App\Models\SiteVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SiteVisit>
 */
class SiteVisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 240));
        $lastActivityAt = $startedAt->copy()->addSeconds(fake()->numberBetween(30, 1800));

        if ($lastActivityAt->isFuture()) {
            $lastActivityAt = now();
        }

        return [
            'visitor_id' => Str::uuid()->toString(),
            'user_id' => null,
            'ip_hash' => SiteVisit::hashIp(fake()->ipv4()),
            'browser' => fake()->randomElement(['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera']),
            'platform' => fake()->randomElement(['Windows', 'Android', 'macOS', 'iOS', 'Linux']),
            'device_type' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'referrer_host' => fake()->boolean(30) ? fake()->domainName() : null,
            'entry_path' => '/',
            'last_path' => fake()->randomElement(['/', '/atendentes', '/como-funciona', '/duvidas']),
            'page_views' => fake()->numberBetween(1, 8),
            'started_at' => $startedAt,
            'last_activity_at' => $lastActivityAt,
            'ended_at' => $lastActivityAt->copy(),
        ];
    }

    public function anonymous(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => null,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now(),
            'ended_at' => null,
        ]);
    }
}
