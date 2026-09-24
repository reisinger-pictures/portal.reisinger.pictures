<?php

namespace App\Services;

use App\Models\ModelProfile;
use RuntimeException;

/**
 * Keeps the canonical customer contact fields and the encrypted profile
 * snapshot in lockstep when an administrator edits a CRM customer.
 */
class ModelProfileContactService
{
    public function __construct(private readonly ModelQuestionnaire $questionnaire) {}

    public function syncEmail(ModelProfile $profile, ?string $email): void
    {
        $answers = is_array($profile->answers) ? $profile->answers : [];
        $updated = false;

        foreach ($answers as $index => $answer) {
            if (is_array($answer) && ($answer['key'] ?? null) === 'email') {
                $answers[$index]['value'] = $email;
                $updated = true;
            }
        }

        if (! $updated) {
            $question = collect($this->questionnaire->questions('person'))
                ->firstWhere('key', 'email');

            if (is_array($question)) {
                $answers[] = [
                    'scope' => 'person',
                    'key' => 'email',
                    'label' => $question['label'] ?? 'E-Mail',
                    'type' => 'email',
                    'value' => $email,
                ];
            }
        }

        if ($profile->forceFill(['answers' => $answers])->save() !== true) {
            throw new RuntimeException('Model profile e-mail synchronization was cancelled.');
        }
    }
}
