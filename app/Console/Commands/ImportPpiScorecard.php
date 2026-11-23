<?php

namespace App\Console\Commands;

use App\Models\PpiScorecard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Throwable;

#[Signature('ppi:import-scorecard {file : Path to the official PPI scorecard JSON file} {--activate : Make this scorecard the active one}')]
#[Description('Import an official PPI scorecard (ten questions and weighted options) from JSON')]
class ImportPpiScorecard extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! File::isFile($path)) {
            $this->error("Scorecard file not found: {$path}");

            return self::FAILURE;
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            $this->error('The scorecard file must contain a valid JSON object.');

            return self::FAILURE;
        }

        $validator = Validator::make($decoded, [
            'name' => ['required', 'string', 'max:150'],
            'version' => ['required', 'string', 'max:50'],
            'country' => ['required', 'string', 'size:2'],
            'source_reference' => ['required', 'string', 'max:500'],
            'effective_from' => ['required', 'date'],
            'questions' => ['required', 'array', 'size:'.(int) config('ppi.scorecard.required_questions')],
            'questions.*.code' => ['required', 'string', 'max:10', 'distinct'],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*.code' => ['required', 'string', 'max:20'],
            'questions.*.options.*.label' => ['required', 'string', 'max:255'],
            'questions.*.options.*.points' => ['required', 'integer', 'between:-100,100'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();
        $optionError = $this->validateOptionCodes($data['questions']);

        if ($optionError !== null) {
            $this->error($optionError);

            return self::FAILURE;
        }

        try {
            $scorecard = DB::transaction(function () use ($data): PpiScorecard {
                if ($this->option('activate')) {
                    PpiScorecard::query()->update(['is_active' => false]);
                }

                $scorecard = PpiScorecard::create([
                    'name' => $data['name'],
                    'version' => $data['version'],
                    'country' => strtoupper($data['country']),
                    'source_reference' => $data['source_reference'],
                    'effective_from' => $data['effective_from'],
                    'is_active' => (bool) $this->option('activate'),
                ]);

                foreach (array_values($data['questions']) as $questionPosition => $questionData) {
                    $question = $scorecard->questions()->create([
                        'code' => $questionData['code'],
                        'prompt' => $questionData['prompt'],
                        'position' => $questionPosition + 1,
                    ]);

                    foreach (array_values($questionData['options']) as $optionPosition => $optionData) {
                        $question->options()->create([
                            'code' => $optionData['code'],
                            'label' => $optionData['label'],
                            'points' => $optionData['points'],
                            'position' => $optionPosition + 1,
                        ]);
                    }
                }

                return $scorecard;
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The scorecard could not be imported. No changes were saved.');

            return self::FAILURE;
        }

        $questionCount = $scorecard->questions()->count();
        $optionCount = $scorecard->questions()->withCount('options')->get()->sum('options_count');

        $this->info("Imported PPI scorecard {$scorecard->name} {$scorecard->version}.");
        $this->line("Questions: {$questionCount}; answer options: {$optionCount}; active: ".($scorecard->is_active ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{options: array<int, array{code: string}>}>  $questions
     */
    private function validateOptionCodes(array $questions): ?string
    {
        foreach (array_values($questions) as $index => $question) {
            $codes = array_map(
                static fn (array $option): string => (string) $option['code'],
                array_values($question['options']),
            );

            if (count($codes) !== count(array_unique($codes))) {
                return 'Option codes must be unique within question '.($index + 1).'.';
            }
        }

        return null;
    }
}
