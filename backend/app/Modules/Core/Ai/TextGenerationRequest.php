<?php

declare(strict_types=1);

namespace App\Modules\Core\Ai;

/**
 * One request to generate text, as a task describes it.
 *
 * The shape is deliberately narrow. ADR 0044 rejects a single contract covering
 * completion, chat, embeddings, moderation and transcription: each is a different
 * request and a different result, and an interface holding all five is one every
 * driver implements a fifth of.
 *
 * `instruction` is the task's own prompt and is code (ADR 0044 §6) — it ships with a
 * deployment and is not editable from the Admin. `content` is the material the task
 * was pointed at, and is the only part that comes from the platform's data.
 *
 * `expects` is what the task will do with the answer, stated so a driver can ask for
 * it plainly rather than a task having to parse prose out of a paragraph.
 */
final readonly class TextGenerationRequest
{
    /**
     * @param  string  $instruction  the task's prompt; code, never configuration
     * @param  string  $content  the material to work on
     * @param  string  $model  the vendor's model identifier
     * @param  int  $maxOutputTokens  the ceiling on what may come back
     * @param  float  $temperature  fixed per task, never an operator setting
     * @param  array<string, string>  $context  short labelled facts the prompt refers to
     */
    public function __construct(
        public string $instruction,
        public string $content,
        public string $model,
        public int $maxOutputTokens = 512,
        public float $temperature = 0.2,
        public array $context = [],
    ) {}

    /**
     * The instruction with its context appended, as a driver sends it.
     *
     * Built here rather than in each driver so that two vendors are asked the same
     * question — a difference in phrasing between drivers would look like a difference
     * in model quality.
     */
    public function systemPrompt(): string
    {
        if ($this->context === []) {
            return $this->instruction;
        }

        $lines = [];

        foreach ($this->context as $label => $value) {
            $lines[] = $label.': '.$value;
        }

        return $this->instruction."\n\n".implode("\n", $lines);
    }
}
