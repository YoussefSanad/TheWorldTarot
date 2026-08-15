<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Joining the mailing list.
 *
 * Backend validation is authoritative, per .claude/api.md. The frontend has its
 * own checkbox and its own `type="email"`, and both live in the browser where
 * anybody can step around them, so none of it counts here.
 */
class SubscribeToNewsletterRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Lowercased before validation, the same way RegisterRequest does
            // it, so Jennifer@ and jennifer@ cannot become two contacts that
            // look identical in her audience.
            //
            // 254 is RFC 5321's maximum addressable length. The frontend field
            // says the same number, and said 50 until this was built.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:254'],

            // **Checked here even though the checkbox is what enforces it.**
            // The checkbox is client side and gates a button, so anybody can
            // post without it. The copy beside it makes a promise about what
            // somebody agreed to, and a request that never agreed must not be
            // able to satisfy that promise by skipping the field.
            'consent' => ['required', 'accepted'],

            // The coming-soon form does not ask for one. The footer form does,
            // so the endpoint takes it now and that form becomes frontend-only
            // work later.
            'name' => ['sometimes', 'nullable', 'string', 'max:40'],

            // The honeypot. A real person never sees this field and never fills
            // it in, so anything arriving with content here is automated.
            // Validated as a shape only. What happens to it is the controller's
            // business, because refusing outright would tell whoever sent it
            // exactly which field gave them away.
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
