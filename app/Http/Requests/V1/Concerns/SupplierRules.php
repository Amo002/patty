<?php

namespace App\Http\Requests\V1\Concerns;

/**
 * Rules shared by E8 (create) and E10 (update) (validation.md, Suppliers).
 */
trait SupplierRules
{
    /**
     * @param  array<int, string>  $presence  `['required']` on create, `['sometimes', 'required']` on update (G10)
     * @return array<string, array<int, mixed>>
     */
    protected function supplierRules(array $presence, mixed $unique): array
    {
        return [
            // S16: no line breaks or control characters, so a name can never forge a log line.
            'name' => [...$presence, 'string', 'min:2', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/', $unique],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
        ];
    }

    /**
     * Static text only: never interpolate input into a message.
     *
     * @return array<string, string>
     */
    protected function supplierMessages(): array
    {
        return [
            'name.unique' => 'A supplier with this name already exists.',
            'name.min' => 'The name must be at least 2 characters.',
            'name.max' => 'The name must be at most 120 characters.',
            'name.not_regex' => 'The name cannot contain line breaks or other control characters.',
            'email.email' => 'Enter a valid email address.',
            'email.max' => 'The email must be at most 255 characters.',
            'phone.regex' => 'The phone number may only contain digits, spaces, + - and brackets.',
            'phone.max' => 'The phone number must be at most 30 characters.',
        ];
    }
}
