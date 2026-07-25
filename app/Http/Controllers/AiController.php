<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiException;
use App\Services\Ai\Prompts;
use App\Services\Ai\ProviderFactory;
use App\Support\CssGuard;
use App\Support\HtmlFragment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(private readonly ProviderFactory $providers) {}

    /**
     * Ask for a small set of CSS declarations. This is the normal edit path.
     */
    public function edit(Request $request): JsonResponse
    {
        $input = $this->validateEdit($request);

        return $this->run($request, function ($provider, string $model) use ($input) {
            $result = $provider->structured(
                Prompts::editSystem(),
                $this->buildPrompt($input),
                Prompts::editSchema(),
                $model,
                8000,
            );

            return [
                'summary' => (string) ($result['summary'] ?? ''),
                'changes' => $this->cleanChanges($result['changes'] ?? []),
            ];
        });
    }

    /**
     * Produce several distinct directions for the same brief, for side-by-side
     * comparison. This is the shape most preference testing actually needs.
     */
    public function variants(Request $request): JsonResponse
    {
        $input = $this->validateEdit($request);
        $count = (int) $request->integer('count', 3);
        $count = max(2, min(4, $count));

        return $this->run($request, function ($provider, string $model) use ($input, $count) {
            $result = $provider->structured(
                Prompts::variantsSystem($count),
                $this->buildPrompt($input)."\n\nProduce exactly {$count} variants.",
                Prompts::variantsSchema(),
                $model,
                12000,
            );

            $variants = [];
            foreach ((array) ($result['variants'] ?? []) as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $variants[] = [
                    'label' => (string) ($variant['label'] ?? 'Variant'),
                    'rationale' => (string) ($variant['rationale'] ?? ''),
                    'changes' => $this->cleanChanges($variant['changes'] ?? []),
                ];
            }

            return ['variants' => $variants];
        });
    }

    /**
     * Rewrite an element's markup. Slower, costlier, and lossy — kept for the
     * cases where the structure itself really does have to change.
     */
    public function restructure(Request $request): JsonResponse
    {
        $limit = (int) config('editor.limits.max_html_chars', 60000);

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:'.config('editor.limits.max_prompt_chars', 2000)],
            'html' => ['required', 'string', 'max:'.$limit],
            'theme' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->run($request, function ($provider, string $model) use ($validated) {
            $prompt = "Design tokens on this page:\n".($validated['theme'] ?? 'unknown')
                ."\n\nElement HTML:\n".$validated['html']
                ."\n\nRequest: ".$validated['prompt'];

            $result = $provider->structured(
                Prompts::restructureSystem(),
                $prompt,
                Prompts::restructureSchema(),
                $model,
                16000,
            );

            $html = HtmlFragment::sanitize((string) ($result['html'] ?? ''));

            if ($html === '') {
                throw new AiException('The model returned nothing usable. Try selecting a smaller element.', 422);
            }

            return [
                'summary' => (string) ($result['summary'] ?? ''),
                'html' => $html,
            ];
        });
    }

    /**
     * Read-only design critique — no edits, nothing to undo, safe to run on
     * anything. Cheap, and the output is shareable as-is in a research doc.
     */
    public function critique(Request $request): JsonResponse
    {
        $input = $this->validateEdit($request, promptRequired: false);

        return $this->run($request, function ($provider, string $model) use ($input) {
            $prompt = $this->buildPrompt($input, fallbackPrompt: 'Critique this section.');

            $result = $provider->structured(
                Prompts::critiqueSystem(),
                $prompt,
                Prompts::critiqueSchema(),
                $model,
                8000,
            );

            $findings = [];
            foreach ((array) ($result['findings'] ?? []) as $finding) {
                if (! is_array($finding)) {
                    continue;
                }
                $findings[] = [
                    'id' => (string) ($finding['id'] ?? ''),
                    'title' => (string) ($finding['title'] ?? ''),
                    'severity' => in_array($finding['severity'] ?? '', ['high', 'medium', 'low'], true)
                        ? $finding['severity']
                        : 'medium',
                    'recommendation' => (string) ($finding['recommendation'] ?? ''),
                ];
            }

            return [
                'summary' => (string) ($result['summary'] ?? ''),
                'findings' => $findings,
            ];
        });
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return array{prompt:string,outline:string,theme:?string,history:list<array{prompt:string,summary:string}>}
     */
    private function validateEdit(Request $request, bool $promptRequired = true): array
    {
        $validated = $request->validate([
            'prompt' => [$promptRequired ? 'required' : 'nullable', 'string', 'max:'.config('editor.limits.max_prompt_chars', 2000)],
            'outline' => ['required', 'string', 'max:40000'],
            'theme' => ['nullable', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*.prompt' => ['nullable', 'string', 'max:1000'],
            'history.*.summary' => ['nullable', 'string', 'max:1000'],
        ]);

        $history = [];
        foreach ((array) ($validated['history'] ?? []) as $turn) {
            $prompt = trim((string) ($turn['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $history[] = ['prompt' => $prompt, 'summary' => trim((string) ($turn['summary'] ?? ''))];
        }

        return [
            'prompt' => (string) ($validated['prompt'] ?? ''),
            'outline' => $validated['outline'],
            'theme' => $validated['theme'] ?? null,
            'history' => $history,
        ];
    }

    /**
     * @param  array{prompt:string,outline:string,theme:?string,history:list<array{prompt:string,summary:string}>}  $input
     */
    private function buildPrompt(array $input, string $fallbackPrompt = ''): string
    {
        $prompt = $input['prompt'] !== '' ? $input['prompt'] : $fallbackPrompt;

        $sections = [
            "Design tokens on this page:\n".($input['theme'] ?: 'not detected'),
            "Selected element outline (this already reflects every earlier edit):\n".$input['outline'],
        ];

        // Without this, "no, less rounded" is meaningless — each request
        // started from zero and the model had no idea what "less" referred to.
        if ($input['history'] !== []) {
            $lines = ['Earlier requests for this same element, oldest first:'];
            foreach ($input['history'] as $index => $turn) {
                $lines[] = sprintf('%d. Asked: "%s"', $index + 1, $turn['prompt']);
                if ($turn['summary'] !== '') {
                    $lines[] = '   You did: '.$turn['summary'];
                }
            }
            $lines[] = 'Treat the new request as a refinement of that work, not a fresh start.';
            $sections[] = implode("\n", $lines);
        }

        $sections[] = 'Request: '.$prompt;

        return implode("\n\n", $sections);
    }

    /**
     * @param  mixed  $changes
     * @return list<array{id:string,declarations:list<array{property:string,value:string}>,text:string}>
     */
    private function cleanChanges($changes): array
    {
        $clean = [];

        foreach ((array) $changes as $change) {
            if (! is_array($change)) {
                continue;
            }

            $id = trim((string) ($change['id'] ?? ''));

            // Ids are assigned by the browser and always look like "e42";
            // anything else is a hallucination and would not match a node.
            if (preg_match('/^e\d{1,6}$/', $id) !== 1) {
                continue;
            }

            $declarations = CssGuard::filter((array) ($change['declarations'] ?? []));
            $text = (string) ($change['text'] ?? '');

            if ($declarations === [] && trim($text) === '') {
                continue;
            }

            $clean[] = [
                'id' => $id,
                'declarations' => $declarations,
                'text' => mb_substr($text, 0, 5000),
            ];
        }

        return $clean;
    }

    /**
     * Resolve the provider, run the callback, and turn any failure into JSON.
     */
    private function run(Request $request, callable $callback): JsonResponse
    {
        $providerKey = (string) $request->input('provider', config('editor.default_provider'));

        try {
            $provider = $this->providers->make($providerKey, $request->input('api_key'));
            $model = $this->providers->resolveModel($providerKey, $request->input('model'));

            $payload = $callback($provider, $model);

            return response()->json([
                'ok' => true,
                'model' => $model,
                'usage' => $this->usage($provider->lastUsage(), $model),
            ] + $payload);
        } catch (AiException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status());
        }
    }

    /**
     * Token counts plus a cost, when we have a published price for the model.
     *
     * A wrong number here is worse than no number — someone budgeting a
     * research sprint would act on it — so an unpriced model reports its
     * tokens with a null cost rather than a guess.
     *
     * @param  array{input:int,output:int}  $usage
     * @return array<string,mixed>
     */
    private function usage(array $usage, string $model): array
    {
        $price = config('editor.pricing.'.$model);

        $cost = null;
        if (is_array($price)) {
            $cost = ($usage['input'] / 1_000_000) * (float) $price['input']
                + ($usage['output'] / 1_000_000) * (float) $price['output'];
            $cost = round($cost, 6);
        }

        return [
            'input_tokens' => $usage['input'],
            'output_tokens' => $usage['output'],
            'cost_usd' => $cost,
        ];
    }
}
