<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExplorerChart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExplorerChart>
 */
final class ExplorerChartFactory extends Factory
{
    protected $model = ExplorerChart::class;

    /**
     * A valid declaration over the widest source, with a range fixed in the past so a test that asserts on
     * it does not depend on the day it runs.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'is_shared' => false,
            'definition' => [
                'v' => 1,
                'source' => 'responses',
                'form_id' => null,
                'chart' => 'bar',
                'dimensions' => [['field' => 'status', 'bucket' => null]],
                'measures' => [['aggregate' => 'count', 'field' => null]],
                'filters' => [],
                'from' => '2026-01-01',
                'to' => '2026-03-31',
                'timezone' => 'UTC',
                'sort' => 'value_desc',
                'limit' => 10,
            ],
        ];
    }

    public function shared(): self
    {
        return $this->state(['is_shared' => true]);
    }
}
