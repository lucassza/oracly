<?php

namespace App\Oracly\Services;

use App\Models\AiStrategyAnalysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

final class AgainstOneGoalAiService
{
    public const STRATEGY = 'against_one_goal';

    /**
     * Analisa exclusivamente as informações disponíveis antes do início da partida.
     *
     * @param array<string, mixed> $row
     * @return array{decision: string, methodology: string, confidence: int, rationale: string, riskFlags: list<string>, cached: bool}
     */
    public function analyse(array $row, bool $force = false): array
    {
        $providerMatchId = (string) ($row['providerMatchId'] ?? '');
        if ($providerMatchId === '') {
            throw new LogicException('A análise IA precisa do providerMatchId da partida.');
        }

        $model = (string) config('services.openrouter.model');
        $input = $this->input($row);
        $inputHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        if (! $force) {
            $cached = AiStrategyAnalysis::query()
                ->where('strategy', self::STRATEGY)
                ->where('provider_match_id', $providerMatchId)
                ->where('model', $model)
                ->where('input_hash', $inputHash)
                ->first();
            if ($cached) {
                return $this->storedResult($cached->payload, true);
            }
        }

        $apiKey = trim((string) config('services.openrouter.key'));
        if ($apiKey === '') {
            throw new LogicException('OPENROUTER_API_KEY não está configurada.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout((int) config('services.openrouter.timeout', 45))
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => (int) config('services.openrouter.max_tokens', 350),
                    'reasoning' => ['enabled' => false],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR)],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'against_one_goal_analysis',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                ])
                ->throw();
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Não foi possível conectar ao OpenRouter.', previous: $exception);
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($content)) {
            throw new RuntimeException('OpenRouter não retornou uma resposta de análise válida.');
        }

        try {
            $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('OpenRouter retornou JSON inválido para a análise.', previous: $exception);
        }
        if (! is_array($payload)) {
            throw new RuntimeException('OpenRouter retornou uma análise em formato inesperado.');
        }

        $result = $this->validateResult($payload);
        AiStrategyAnalysis::query()->updateOrCreate(
            [
                'strategy' => self::STRATEGY,
                'provider_match_id' => $providerMatchId,
                'model' => $model,
                'input_hash' => $inputHash,
            ],
            [
                ...$result,
                'payload' => $result,
            ],
        );

        return [...$result, 'cached' => false];
    }

    /** @param array<string, mixed> $row */
    public function baselineMethodology(array $row): ?string
    {
        $homeAverage = $this->number($row['homeGoalsAverage'] ?? null);
        $awayAverage = $this->number($row['awayGoalsAverage'] ?? null);
        if ($homeAverage === null || $awayAverage === null) {
            return null;
        }

        $homeAverage = max(0.08, $homeAverage);
        $awayAverage = max(0.08, $awayAverage);

        return $this->poisson($homeAverage, 0) * $this->poisson($awayAverage, 1)
            <= $this->poisson($homeAverage, 1) * $this->poisson($awayAverage, 0)
            ? '0-1'
            : '1-0';
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function input(array $row): array
    {
        return [
            'strategy' => 'Lay one exact score at full time: choose either 0-1 or 1-0.',
            'fixture' => [
                'kickoffAt' => $row['kickoffAt'] ?? null,
                'country' => $row['country'] ?? null,
                'competition' => $row['competition'] ?? null,
                'homeTeam' => $row['homeTeam'] ?? null,
                'awayTeam' => $row['awayTeam'] ?? null,
            ],
            'preKickoffFeatures' => [
                'over15FtProbability' => $this->number($row['probability'] ?? null),
                'over25FtProbability' => $this->number($row['over25Probability'] ?? null),
                'bttsProbability' => $this->number($row['bttsProbability'] ?? null),
                'combinedGoalsAverage' => $this->number($row['combinedGoalsAverage'] ?? null),
                'homeGoalsAverage' => $this->number($row['homeGoalsAverage'] ?? null),
                'awayGoalsAverage' => $this->number($row['awayGoalsAverage'] ?? null),
                'homeOdd' => $this->number($row['homeOdd'] ?? null),
                'drawOdd' => $this->number($row['drawOdd'] ?? null),
                'awayOdd' => $this->number($row['awayOdd'] ?? null),
            ],
            'baselineMethodology' => $this->baselineMethodology($row),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You evaluate an experimental football betting strategy. Use only the supplied pre-kickoff data; do not assume access to results, live information, injuries, news, or external odds.

The strategy lays one exact full-time score. Select methodology "0-1" to bet against 0-1, or "1-0" to bet against 1-0. Return "enter" only if the inputs provide a clear, internally consistent case; otherwise return "skip". Confidence is the quality of the supplied signal, not a claimed chance of profit. Be conservative and do not fabricate facts.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['decision', 'methodology', 'confidence', 'rationale', 'riskFlags'],
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['enter', 'skip']],
                'methodology' => ['type' => 'string', 'enum' => ['0-1', '1-0']],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'rationale' => ['type' => 'string', 'maxLength' => 300],
                'riskFlags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 4],
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array{decision: string, methodology: string, confidence: int, rationale: string, riskFlags: list<string>}
     */
    private function validateResult(array $payload): array
    {
        $decision = $payload['decision'] ?? null;
        $methodology = $payload['methodology'] ?? null;
        $confidence = $payload['confidence'] ?? null;
        $rationale = $payload['rationale'] ?? null;
        $riskFlags = $payload['riskFlags'] ?? null;
        if (! in_array($decision, ['enter', 'skip'], true)
            || ! in_array($methodology, ['0-1', '1-0'], true)
            || ! is_int($confidence)
            || $confidence < 0
            || $confidence > 100
            || ! is_string($rationale)
            || ! is_array($riskFlags)
            || array_filter($riskFlags, fn (mixed $flag): bool => ! is_string($flag)) !== []) {
            throw new RuntimeException('A análise retornada não respeita o contrato esperado.');
        }

        return [
            'decision' => $decision,
            'methodology' => $methodology,
            'confidence' => $confidence,
            'rationale' => $rationale,
            'riskFlags' => array_values($riskFlags),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array{decision: string, methodology: string, confidence: int, rationale: string, riskFlags: list<string>, cached: bool}
     */
    private function storedResult(array $payload, bool $cached): array
    {
        return [...$this->validateResult($payload), 'cached' => $cached];
    }

    private function poisson(float $average, int $goals): float
    {
        return exp(-$average) * ($average ** $goals);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
