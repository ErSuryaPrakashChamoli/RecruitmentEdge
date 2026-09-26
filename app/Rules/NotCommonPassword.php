<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Phase 8.4: refuses passwords built on the words attackers try first (password, welcome, admin,
 * company or product names, keyboard walks), however they are decorated with capitals, digits and
 * symbols — "Welcome@2026" meets every complexity rule and is still guessed in minutes. Works
 * offline; the optional breached-password check (identity.password.check_breached) is separate.
 */
class NotCommonPassword implements ValidationRule
{
    /**
     * Compared after lowercasing and removing everything but letters.
     *
     * @var list<string>
     */
    private const array COMMON_WORDS = [
        'password', 'passw', 'passwd', 'pass', 'welcome', 'admin', 'administrator', 'root', 'user', 'login',
        'letmein', 'qwerty', 'qwertyuiop', 'asdf', 'asdfgh', 'asdfghjkl', 'zxcvbn', 'zxcvbnm', 'abc', 'abcd', 'abcdef',
        'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football', 'baseball', 'cricket', 'master',
        'secret', 'changeme', 'default', 'test', 'testing', 'guest', 'hello', 'india', 'mumbai', 'delhi',
        'recruitment', 'recruitmentedge', 'edge', 'markedge', 'hrms', 'hr', 'company', 'office', 'summer',
        'winter', 'spring', 'autumn', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
        'september', 'october', 'november', 'december', 'monday', 'friday', 'god', 'love', 'trustno', 'shadow',
    ];

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    /**
     * Look-alike characters people put inside a word.
     *
     * @var array<string, string>
     */
    private const array LOOK_ALIKES = ['@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i', '0' => 'o', '$' => 's', '5' => 's', '7' => 't'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Strip the decoration around the word ("2026", "@123!"), undo look-alikes inside it
        // ("P@ssw0rd"), then compare the letters that remain.
        $core = preg_replace('/^[^a-zA-Z]+|[^a-zA-Z]+$/', '', (string) $value) ?? '';
        $letters = preg_replace('/[^a-z]/', '', strtr(strtolower($core), self::LOOK_ALIKES)) ?? '';

        if ($letters === '' || in_array($letters, self::COMMON_WORDS, true)) {
            $fail('The :attribute is too common or easy to guess. Choose a less predictable password.');
        }
    }
}
