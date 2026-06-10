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
        $this->guardInput($alternatives, $criteria);

        $normalizedAlternatives = $this->normalizeAlternatives($alternatives);
        $normalizedCriteria = $this->normalizeCriteria($criteria);
        $globalPreference = $this->globalPreferenceMatrix($normalizedAlternatives, $normalizedCriteria);
        $flows = $this->flows($globalPreference);

        return $this->rank($normalizedAlternatives, $flows);
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
    private function globalPreferenceMatrix(array $alternatives, array $criteria): array
    {
        $matrix = [];
        $totalWeight = array_sum(array_column($criteria, 'weight'));

        foreach ($alternatives as $aIndex => $alternativeA) {
            $matrix[$aIndex] = [];

            foreach ($alternatives as $bIndex => $alternativeB) {
                if ($aIndex === $bIndex) {
                    continue;
                }

                $weightedPreference = 0.0;

                foreach ($criteria as $criterion) {
                    $criterionId = $criterion['id'];
                    $scoreA = (float) ($alternativeA['scores'][$criterionId] ?? 0.0);
                    $scoreB = (float) ($alternativeB['scores'][$criterionId] ?? 0.0);
                    $deviation = $criterion['direction'] === 'min' ? $scoreB - $scoreA : $scoreA - $scoreB;

                    $weightedPreference += $this->preference($deviation, $criterion) * $criterion['weight'];
                }

                $matrix[$aIndex][$bIndex] = $weightedPreference / $totalWeight;
            }
        }

        return $matrix;
    }

    /**
     * @param  array<int, array<int, float>>  $globalPreference
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
