<?php

namespace App\NameMatching;

use RuntimeException;

final class NameNormalizer
{
    /** Letters NFD can't decompose (incl. Hausa ɓ ɗ ƙ, Yoruba/Igbo ɛ ɔ). */
    private const LETTER_MAP = [
        'ø' => 'o', 'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'đ' => 'd', 'ł' => 'l',
        'ɛ' => 'e', 'ɔ' => 'o', 'ɓ' => 'b', 'ɗ' => 'd', 'ƙ' => 'k', 'ŋ' => 'ng',
    ];

    /** @var array<string,true> */
    private array $ignored;

    /** @var array<string,string> token => canonical token */
    private array $variants = [];

    /**
     * @param list<string>       $titles
     * @param list<string>       $businessWords
     * @param list<list<string>> $variantGroups first entry of each group is the canonical spelling
     */
    public function __construct(array $titles = [], array $businessWords = [], array $variantGroups = [])
    {
        if (! class_exists(\Normalizer::class)) {
            throw new RuntimeException('The PHP intl extension is required for name normalisation.');
        }

        $this->ignored = array_fill_keys(array_map('strtolower', [...$titles, ...$businessWords]), true);

        foreach ($variantGroups as $group) {
            $canonical = strtolower($group[0]);
            foreach ($group as $spelling) {
                $this->variants[strtolower($spelling)] = $canonical;
            }
        }
    }

    /** @return list<string> lowercase a–z tokens: no titles, no business words, variants canonicalised */
    public function tokens(string $name): array
    {
        $s = \Normalizer::normalize($name, \Normalizer::FORM_D);
        $s = $s === false ? $name : $s;                          // invalid UTF-8: carry on with the raw string
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;           // strip combining marks (é, ọ, ṣ ...)
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, self::LETTER_MAP);
        $s = str_replace(["'", '’', 'ʼ', '`'], '', $s);          // N'Dubuisi -> ndubuisi
        $s = preg_replace('/[^a-z]+/', ' ', $s);                 // punctuation, hyphens, digits -> separator

        $tokens = [];
        foreach (explode(' ', trim($s)) as $token) {
            if ($token === '' || isset($this->ignored[$token])) {
                continue;
            }
            $tokens[] = $this->variants[$token] ?? $token;
        }

        return $tokens;
    }
}