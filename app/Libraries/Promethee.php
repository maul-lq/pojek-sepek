<?php

namespace App\Libraries;

use InvalidArgumentException;

class Promethee
{
    public const USUAL = 'usual';

    public const LINEAR = 'linear';

    public const QUASI = 'quasi';

    public const LINEAR_QUASI = 'linear_quasi';

    public const LEVEL = 'level';

    public const GAUSSIAN = 'gaussian';

    /**
     * @param  array<int, array{name?: string, scores?: array<int|string, numeric-string|int|float>}>  $alternatives
     * @param  array<int, array{id: int|string, name?: string, direction?: string, min_max?: string, weight?: numeric-string|int|float, type?: string|int, preference_function?: string|int, p?: numeric-string|int|float|null, q?: numeric-string|int|float|null, s?: numeric-string|int|float|null}>  $criteria
     * @return array<int, array{name: string, leaving_flow: float, entering_flow: float, net_flow: float, rank: int, code: string|null}>
     */
    public function calculate(array $alternatives, array $criteria): array
    {
        return $this->calculateDetails($alternatives, $criteria)['rankings'];
    }

    /**
     * @param  array<int, array{name?: string, scores?: array<int|string, numeric-string|int|float>}>  $alternatives
     * @param  array<int, array{id: int|string, name?: string, direction?: string, min_max?: string, weight?: numeric-string|int|float, type?: string|int, preference_function?: string|int, p?: numeric-string|int|float|null, q?: numeric-string|int|float|null, s?: numeric-string|int|float|null}>  $criteria
     * @return array{
     *     rankings: array<int, array{name: string, leaving_flow: float, entering_flow: float, net_flow: float, rank: int, code: string|null}>,
     *     alternatives: array<int, array{name: string, code: string|null}>,
     *     criteria: array<int, array{id: int|string, name: string, direction: string, weight: float, preference_function: string}>,
     *     deviations: array<int|string, array<int, array<int, float|null>>>,
     *     criterion_preferences: array<int|string, array<int, array<int, float|null>>>,
     *     pairwise_comparisons: array<int, array{alternative_a: string, alternative_b: string, deviations: array<int|string, float>, criterion_preferences: array<int|string, float>}>,
     *     preference_indices: array<int, array<int, float|null>>,
     *     flows: array<int, array{name: string, leaving_flow: float, entering_flow: float, net_flow: float}>
     * }
     */
    public function calculateDetails(array $alternatives, array $criteria): array
    {
        $this->guardInput($alternatives, $criteria);

        $normalizedAlternatives = $this->normalizeAlternatives($alternatives);
        $normalizedCriteria = $this->normalizeCriteria($criteria);
        $deviations = $this->deviationMatrices($normalizedAlternatives, $normalizedCriteria);
        $criterionPreferences = $this->criterionPreferenceMatrices($deviations, $normalizedCriteria);
        $globalPreference = $this->globalPreferenceMatrix($criterionPreferences, $normalizedCriteria);
        $flows = $this->flows($globalPreference);

        return [
            'rankings' => $this->rank($normalizedAlternatives, $flows),
            'alternatives' => array_map(static fn (array $alternative): array => [
                'name' => $alternative['name'],
                'code' => $alternative['code'],
            ], $normalizedAlternatives),
            'criteria' => array_map(static fn (array $criterion): array => [
                'id' => $criterion['id'],
                'name' => $criterion['name'],
                'direction' => $criterion['direction'],
                'weight' => $criterion['weight'],
                'preference_function' => $criterion['preference_function'],
            ], $normalizedCriteria),
            'deviations' => $deviations,
            'criterion_preferences' => $criterionPreferences,
            'pairwise_comparisons' => $this->pairwiseComparisons(
                $normalizedAlternatives,
                $normalizedCriteria,
                $deviations,
                $criterionPreferences,
            ),
            'preference_indices' => $globalPreference,
            'flows' => $this->flowRows($normalizedAlternatives, $flows),
        ];
    }

    /**
     * @param  array<int, array{name: string, code: string|null, scores: array<int|string, float>}>  $alternatives
     * @param  array<int, array{id: int|string, name: string, direction: string, weight: float, preference_function: string, p: float, q: float, s: float}>  $criteria
     * @param  array<int|string, array<int, array<int, float|null>>>  $deviations
     * @param  array<int|string, array<int, array<int, float|null>>>  $criterionPreferences
     * @return array<int, array{alternative_a: string, alternative_b: string, deviations: array<int|string, float>, criterion_preferences: array<int|string, float>}>
     */
    private function pairwiseComparisons(
        array $alternatives,
        array $criteria,
        array $deviations,
        array $criterionPreferences,
    ): array {
        $comparisons = [];

        foreach ($alternatives as $aIndex => $alternativeA) {
            foreach ($alternatives as $bIndex => $alternativeB) {
                if ($aIndex === $bIndex) {
                    continue;
                }

                $comparison = [
                    'alternative_a' => $alternativeA['name'],
                    'alternative_b' => $alternativeB['name'],
                    'deviations' => [],
                    'criterion_preferences' => [],
                ];

                foreach ($criteria as $criterion) {
                    $criterionId = $criterion['id'];
                    $comparison['deviations'][$criterionId] = $deviations[$criterionId][$aIndex][$bIndex];
                    $comparison['criterion_preferences'][$criterionId] = $criterionPreferences[$criterionId][$aIndex][$bIndex];
                }

                $comparisons[] = $comparison;
            }
        }

        return $comparisons;
    }

    /**
     * Backward-compatible alias for the previous app-facing method name.
     *
     * @param  array<int, array{name?: string, scores?: array<int|string, numeric-string|int|float>}>  $alternatives
     * @param  array<int, array{id: int|string, name?: string, direction?: string, min_max?: string, weight?: numeric-string|int|float, type?: string|int, preference_function?: string|int, p?: numeric-string|int|float|null, q?: numeric-string|int|float|null, s?: numeric-string|int|float|null}>  $criteria
     * @return array<int, array{name: string, leaving_flow: float, entering_flow: float, net_flow: float, rank: int, code: string|null}>
     */
    public function runCalculation(array $alternatives, array $criteria): array
    {
        return $this->calculate($alternatives, $criteria);
    }

    /**
     * @param  array<string, float|string|int|null>  $criterion
     */
    public function preference(float $value, array $criterion): float
    {
        $type = $this->normalizePreferenceType($criterion['preference_function'] ?? $criterion['type'] ?? self::USUAL);
        $p = (float) ($criterion['p'] ?? 0);
        $q = (float) ($criterion['q'] ?? 0);
        $s = (float) ($criterion['s'] ?? 0);

        return match ($type) {
            self::USUAL => $value <= 0.0 ? 0.0 : 1.0,
            self::LINEAR => $this->linearPreference($value, $p),
            self::QUASI => $value <= $q ? 0.0 : 1.0,
            self::LINEAR_QUASI => $this->linearQuasiPreference($value, $p, $q),
            self::LEVEL => $this->levelPreference($value, $p, $q),
            self::GAUSSIAN => $this->gaussianPreference($value, $s),
            default => throw new InvalidArgumentException("Unsupported PROMETHEE preference type [{$type}]."),
        };
    }

    /**
     * @param  array<int, mixed>  $alternatives
     * @param  array<int, mixed>  $criteria
     */
    private function guardInput(array $alternatives, array $criteria): void
    {
        if (count($alternatives) < 2) {
            throw new InvalidArgumentException('PROMETHEE requires at least two alternatives.');
        }

        if ($criteria === []) {
            throw new InvalidArgumentException('PROMETHEE requires at least one criterion.');
        }
    }

    /**
     * @param  array<int, array{name?: string, code?: string, scores?: array<int|string, numeric-string|int|float>}>  $alternatives
     * @return array<int, array{name: string, code: string|null, scores: array<int|string, float>}>
     */
    private function normalizeAlternatives(array $alternatives): array
    {
        return array_values(array_map(function (array $alternative): array {
            if (! isset($alternative['scores']) || ! is_array($alternative['scores'])) {
                throw new InvalidArgumentException('Each alternative must contain a scores array.');
            }

            return [
                'name' => trim((string) ($alternative['name'] ?? $alternative['nama_skin'] ?? 'Alternatif')),
                'code' => isset($alternative['code']) ? (string) $alternative['code'] : null,
                'scores' => array_map(static fn (mixed $score): float => (float) $score, $alternative['scores']),
            ];
        }, $alternatives));
    }

    /**
     * @param  array<int, array{id: int|string, name?: string, direction?: string, min_max?: string, weight?: numeric-string|int|float, type?: string|int, preference_function?: string|int, p?: numeric-string|int|float|null, q?: numeric-string|int|float|null, s?: numeric-string|int|float|null}>  $criteria
     * @return array<int, array{id: int|string, name: string, direction: string, weight: float, preference_function: string, p: float, q: float, s: float}>
     */
    private function normalizeCriteria(array $criteria): array
    {
        return array_values(array_map(function (array $criterion): array {
            $weight = (float) ($criterion['weight'] ?? 1.0);

            if ($weight <= 0.0) {
                throw new InvalidArgumentException('Criterion weight must be greater than zero.');
            }

            $direction = strtolower((string) ($criterion['direction'] ?? $criterion['min_max'] ?? 'max'));

            if (! in_array($direction, ['max', 'min'], true)) {
                throw new InvalidArgumentException('Criterion direction must be either max or min.');
            }

            return [
                'id' => $criterion['id'],
                'name' => (string) ($criterion['name'] ?? $criterion['criteria'] ?? $criterion['nama'] ?? $criterion['id']),
                'direction' => $direction,
                'weight' => $weight,
                'preference_function' => $this->normalizePreferenceType($criterion['preference_function'] ?? $criterion['type'] ?? self::USUAL),
                'p' => (float) ($criterion['p'] ?? 0),
                'q' => (float) ($criterion['q'] ?? 0),
                's' => (float) ($criterion['s'] ?? 0),
            ];
        }, $criteria));
    }

    /**
     * @param  array<int, array{name: string, code: string|null, scores: array<int|string, float>}>  $alternatives
     * @param  array<int, array{id: int|string, name: string, direction: string, weight: float, preference_function: string, p: float, q: float, s: float}>  $criteria
     * @return array<int, array<int, float>>
     */
    private function deviationMatrices(array $alternatives, array $criteria): array
    {
        $matrix = [];

        foreach ($criteria as $criterion) {
            $criterionId = $criterion['id'];
            $matrix[$criterionId] = [];

            foreach ($alternatives as $aIndex => $alternativeA) {
                $matrix[$criterionId][$aIndex] = [];

                foreach ($alternatives as $bIndex => $alternativeB) {
                    if ($aIndex === $bIndex) {
                        $matrix[$criterionId][$aIndex][$bIndex] = null;

                        continue;
                    }

                    $scoreA = (float) ($alternativeA['scores'][$criterionId] ?? 0.0);
                    $scoreB = (float) ($alternativeB['scores'][$criterionId] ?? 0.0);
                    $matrix[$criterionId][$aIndex][$bIndex] = $criterion['direction'] === 'min'
                        ? $scoreB - $scoreA
                        : $scoreA - $scoreB;
                }
            }
        }

        return $matrix;
    }

    /**
     * @param  array<int|string, array<int, array<int, float|null>>>  $deviations
     * @param  array<int, array{id: int|string, name: string, direction: string, weight: float, preference_function: string, p: float, q: float, s: float}>  $criteria
     * @return array<int|string, array<int, array<int, float|null>>>
     */
    private function criterionPreferenceMatrices(array $deviations, array $criteria): array
    {
        $matrices = [];

        foreach ($criteria as $criterion) {
            $criterionId = $criterion['id'];

            foreach ($deviations[$criterionId] as $aIndex => $row) {
                foreach ($row as $bIndex => $deviation) {
                    $matrices[$criterionId][$aIndex][$bIndex] = $deviation === null
                        ? null
                        : $this->preference($deviation, $criterion);
                }
            }
        }

        return $matrices;
    }

    /**
     * @param  array<int|string, array<int, array<int, float|null>>>  $criterionPreferences
     * @param  array<int, array{id: int|string, name: string, direction: string, weight: float, preference_function: string, p: float, q: float, s: float}>  $criteria
     * @return array<int, array<int, float|null>>
     */
    private function globalPreferenceMatrix(array $criterionPreferences, array $criteria): array
    {
        $matrix = [];
        $alternativeCount = count(reset($criterionPreferences));
        $totalWeight = array_sum(array_column($criteria, 'weight'));

        for ($aIndex = 0; $aIndex < $alternativeCount; $aIndex++) {
            for ($bIndex = 0; $bIndex < $alternativeCount; $bIndex++) {
                if ($aIndex === $bIndex) {
                    $matrix[$aIndex][$bIndex] = null;

                    continue;
                }

                $weightedPreference = 0.0;

                foreach ($criteria as $criterion) {
                    $weightedPreference += ($criterionPreferences[$criterion['id']][$aIndex][$bIndex] ?? 0.0)
                        * $criterion['weight'];
                }

                $matrix[$aIndex][$bIndex] = $weightedPreference / $totalWeight;
            }
        }

        return $matrix;
    }

    /**
     * @param  array<int, array<int, float|null>>  $globalPreference
     * @return array{leaving: array<int, float>, entering: array<int, float>}
     */
    private function flows(array $globalPreference): array
    {
        $alternativeCount = count($globalPreference);
        $divisor = $alternativeCount - 1;
        $leavingFlow = [];
        $enteringFlow = array_fill(0, $alternativeCount, 0.0);

        foreach ($globalPreference as $aIndex => $preferences) {
            $leavingFlow[$aIndex] = array_sum($preferences) / $divisor;

            foreach ($preferences as $bIndex => $preference) {
                $enteringFlow[$bIndex] += $preference;
            }
        }

        foreach ($enteringFlow as $index => $value) {
            $enteringFlow[$index] = $value / $divisor;
        }

        return ['leaving' => $leavingFlow, 'entering' => $enteringFlow];
    }

    /**
     * @param  array<int, array{name: string, code: string|null, scores: array<int|string, float>}>  $alternatives
     * @param  array{leaving: array<int, float>, entering: array<int, float>}  $flows
     * @return array<int, array{name: string, leaving_flow: float, entering_flow: float, net_flow: float}>
     */
    private function flowRows(array $alternatives, array $flows): array
    {
        return array_map(static function (array $alternative, int $index) use ($flows): array {
            $leaving = $flows['leaving'][$index];
            $entering = $flows['entering'][$index];

            return [
                'name' => $alternative['name'],
                'leaving_flow' => round($leaving, 4),
                'entering_flow' => round($entering, 4),
                'net_flow' => round($leaving - $entering, 4),
            ];
        }, $alternatives, array_keys($alternatives));
    }

    /**
     * @param  array<int, array{name: string, code: string|null, scores: array<int|string, float>}>  $alternatives
     * @param  array{leaving: array<int, float>, entering: array<int, float>}  $flows
     * @return array<int, array{name: string, leaving_flow: float, entering_flow: float, net_flow: float, rank: int, code: string|null}>
     */
    private function rank(array $alternatives, array $flows): array
    {
        $results = [];

        foreach ($alternatives as $index => $alternative) {
            $leaving = $flows['leaving'][$index] ?? 0.0;
            $entering = $flows['entering'][$index] ?? 0.0;

            $results[] = [
                'name' => $alternative['name'],
                'code' => $alternative['code'],
                'leaving_flow' => round($leaving, 4),
                'entering_flow' => round($entering, 4),
                'net_flow' => round($leaving - $entering, 4),
                'rank' => 0,
            ];
        }

        usort($results, static function (array $left, array $right): int {
            return [$right['net_flow'], $right['leaving_flow'], $left['entering_flow'], $left['name']]
                <=> [$left['net_flow'], $left['leaving_flow'], $right['entering_flow'], $right['name']];
        });

        foreach ($results as $index => $result) {
            $results[$index]['rank'] = $index + 1;
        }

        return $results;
    }

    private function linearPreference(float $value, float $preferenceThreshold): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        if ($preferenceThreshold <= 0.0 || $value > $preferenceThreshold) {
            return 1.0;
        }

        return $value / $preferenceThreshold;
    }

    private function linearQuasiPreference(float $value, float $preferenceThreshold, float $indifferenceThreshold): float
    {
        if ($value <= $indifferenceThreshold) {
            return 0.0;
        }

        if ($preferenceThreshold <= $indifferenceThreshold || $value > $preferenceThreshold) {
            return 1.0;
        }

        return $value / ($preferenceThreshold - $indifferenceThreshold);
    }

    private function levelPreference(float $value, float $preferenceThreshold, float $indifferenceThreshold): float
    {
        if ($value <= $indifferenceThreshold) {
            return 0.0;
        }

        if ($value > $preferenceThreshold) {
            return 1.0;
        }

        return 0.5;
    }

    private function gaussianPreference(float $value, float $gaussianThreshold): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        if ($gaussianThreshold <= 0.0) {
            return 1.0;
        }

        return 1 - exp(-1 * ($value ** 2) / (2 * ($gaussianThreshold ** 2)));
    }

    private function normalizePreferenceType(string|int $type): string
    {
        if (is_int($type)) {
            return match ($type) {
                1 => self::USUAL,
                2 => self::LINEAR,
                3 => self::QUASI,
                4 => self::LINEAR_QUASI,
                5 => self::LEVEL,
                6 => self::GAUSSIAN,
                default => throw new InvalidArgumentException("Unsupported PROMETHEE preference type [{$type}]."),
            };
        }

        $normalized = str($type)->lower()->replace(['-', ' '], '_')->toString();

        return match ($normalized) {
            'usual', 'u_shape' => self::USUAL,
            'linear', 'linier', 'v_shape' => self::LINEAR,
            'quasi' => self::QUASI,
            'linear_quasi', 'linier_quasi', 'linear_with_indifference' => self::LINEAR_QUASI,
            'level' => self::LEVEL,
            'gaussian', 'gaussion' => self::GAUSSIAN,
            default => throw new InvalidArgumentException("Unsupported PROMETHEE preference type [{$type}]."),
        };
    }
}
