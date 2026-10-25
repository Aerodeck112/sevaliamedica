<?php

namespace Database\Factories;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pacienți FICTIVI pentru teste. CNP-ul, dacă e cerut, e generat valid structural pentru o dată de test,
 * nu corespunde unei persoane reale cunoscute.
 *
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'last_name' => 'Test'.fake()->unique()->lexify('????'),
            'first_name' => fake()->firstName(),
            'birth_date' => fake()->dateTimeBetween('-80 years', '-20 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(['F', 'M']),
            'phone' => '07'.fake()->numerify('########'),
            'citizenship' => 'română',
            'allow_phone' => true,
        ];
    }

    public function withCnp(?string $cnp = null): static
    {
        return $this->state(function () use ($cnp) {
            $cnp ??= self::fakeCnp();
            $derived = PatientNormalizer::fromCnp($cnp);

            return ['cnp' => $cnp, 'birth_date' => $derived['birth_date'] ?? null, 'sex' => $derived['sex'] ?? null];
        });
    }

    /** CNP valid ca structură, cu județ 52 (Giurgiu) și număr de ordine aleator — doar pentru teste. */
    public static function fakeCnp(): string
    {
        $base = sprintf('%d%02d%02d%02d52%03d', fake()->randomElement([1, 2]), fake()->numberBetween(50, 99), fake()->numberBetween(1, 12), fake()->numberBetween(1, 28), fake()->numberBetween(1, 999));
        $key = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $base[$i] * (int) $key[$i];
        }
        $c = $sum % 11;

        return $base.($c === 10 ? 1 : $c);
    }
}
