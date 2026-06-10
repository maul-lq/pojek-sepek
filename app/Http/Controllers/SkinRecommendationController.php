<?php

namespace App\Http\Controllers;

use App\Libraries\Promethee;
use App\Models\Criteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class SkinRecommendationController extends Controller
{
    private const SCORE_KEY_ALIASES_BY_NAME = [
        'Harga (Diamond)' => ['price'],
        'Kategori Skin' => ['category'],
        'Model Skin' => ['model'],
        'Portrait Skin' => ['portrait'],
        'Animasi Entrance' => ['entrance'],
        'In-Game Effect' => ['effect'],
        'Tingkat Preferensi Hero' => ['heroPreference', 'hero_preference'],
        'Status Ketersediaan Skin' => ['availability'],
    ];

    public function hitungRekomendasi(Request $request, Promethee $promethee): JsonResponse
    {
        $criteria = Criteria::query()
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'weight', 'preference_function', 'p', 'q', 's']);

        $input = $this->normalizeAlternativeScoreKeys($request->all(), $criteria);

        $rules = [
            'alternatives' => ['required', 'array', 'min:2'],
            'alternatives.*.name' => ['required', 'string', 'max:100'],
            'alternatives.*.scores' => ['required', 'array'],
        ];

        foreach ($criteria as $criterion) {
            $nameLower = strtolower($criterion->name);
            if (str_contains($nameLower, 'harga')) {
                $rules["alternatives.*.scores.{$criterion->id}"] = ['required', 'numeric', 'min:0'];
            } elseif (str_contains($nameLower, 'rarity') || str_contains($nameLower, 'kategori')) {
                $rules["alternatives.*.scores.{$criterion->id}"] = ['required', 'numeric', 'between:1,6'];
            } elseif (str_contains($nameLower, 'ketersediaan')) {
                $rules["alternatives.*.scores.{$criterion->id}"] = ['required', 'numeric', 'between:1,2'];
            } else {
                $rules["alternatives.*.scores.{$criterion->id}"] = ['required', 'numeric', 'between:1,7'];
            }
        }

        $validator = Validator::make($input, $rules, [
            'alternatives.min' => 'Bandingkan minimal 2 skin agar sistem bisa menghitung peringkatnya.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $criteriaData = $criteria->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'direction' => $c->type === 'minimize' ? 'min' : 'max',
                'weight' => $c->weight,
                'preference_function' => $c->preference_function,
                'p' => $c->p,
                'q' => $c->q,
                's' => $c->s,
            ];
        })->toArray();

        try {
            $calculation = $promethee->calculateDetails(
                $validator->validated()['alternatives'],
                $criteriaData,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'rekomendasi' => $calculation['rankings'],
            'perhitungan' => $calculation,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, Criteria>  $criteria
     * @return array<string, mixed>
     */
    private function normalizeAlternativeScoreKeys(array $input, Collection $criteria): array
    {
        if (! isset($input['alternatives']) || ! is_array($input['alternatives'])) {
            return $input;
        }

        $criteria = $criteria->values();

        foreach ($input['alternatives'] as $alternativeIndex => $alternative) {
            if (! is_array($alternative) || ! isset($alternative['scores']) || ! is_array($alternative['scores'])) {
                continue;
            }

            $scores = $alternative['scores'];

            foreach ($criteria as $criterionIndex => $criterion) {
                $targetKey = (string) $criterion->id;

                if ($this->hasScoreKey($scores, $targetKey)) {
                    continue;
                }

                foreach ($this->scoreKeyAliases($criterion, $criterionIndex) as $alias) {
                    if ($this->hasScoreKey($scores, $alias)) {
                        $scores[$targetKey] = $scores[$alias];
                        break;
                    }
                }
            }

            $input['alternatives'][$alternativeIndex]['scores'] = $scores;
        }

        return $input;
    }

    /**
     * @return array<int, int|string>
     */
    private function scoreKeyAliases(Criteria $criterion, int $criterionIndex): array
    {
        return [
            $criterionIndex + 1,
            (string) ($criterionIndex + 1),
            ...(self::SCORE_KEY_ALIASES_BY_NAME[$criterion->name] ?? []),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $scores
     */
    private function hasScoreKey(array $scores, int|string $key): bool
    {
        return array_key_exists($key, $scores);
    }
}
