<?php

namespace App\Rules;

use App\Support\AddressList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * A comma-separated list of email addresses.
 *
 * Names the offending address in the message: told only that a field is
 * invalid, someone pasting six addresses has to find the bad one by eye.
 */
class EmailList implements ValidationRule
{
    public function __construct(private readonly int $max = 20)
    {
    }

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a comma-separated list of email addresses.');

            return;
        }

        $addresses = AddressList::parse($value);

        if ($addresses === []) {
            return;
        }

        if (count($addresses) > $this->max) {
            $fail("The :attribute cannot hold more than {$this->max} addresses.");

            return;
        }

        $invalid = array_values(array_filter(
            $addresses,
            fn (string $address) => Validator::make(
                ['email' => $address],
                ['email' => 'email:filter']
            )->fails()
        ));

        if ($invalid !== []) {
            $fail(sprintf(
                'The :attribute contains %s that %s not a valid email address: %s.',
                count($invalid) === 1 ? 'an entry' : 'entries',
                count($invalid) === 1 ? 'is' : 'are',
                implode(', ', $invalid),
            ));
        }
    }
}
