<?php

return [
    // score >= high: matched | score <= low: mismatched | between: not matched + needs_review
    'thresholds' => [
        'high' => (float) env('NAME_MATCH_HIGH', 0.92),
        'low'  => (float) env('NAME_MATCH_LOW', 0.55),
    ],

    'token_floor'         => 0.75, // fuzzy token pairs below this similarity get zero credit
    'initial_credit'      => 0.90, // "A" vs "Adebayo"
    'extra_token_penalty' => 0.05, // per unmatched extra token (optional middle names)

    'titles' => [
        'mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'prof', 'engr', 'eng', 'chief', 'alhaji', 'alhaja',
        'hajia', 'hajiya', 'pastor', 'pst', 'rev', 'revd', 'sir', 'dame', 'madam', 'barr', 'hon',
        'sen', 'otunba', 'mallam', 'malam', 'elder', 'evang', 'deacon', 'deaconess', 'apostle',
        'mazi', 'ichie',
        // Deliberately NOT here: prince, princess, lady, eze (real given names / name parts).
    ],

    'business_words' => [
        'enterprise', 'enterprises', 'ent', 'ventures', 'venture', 'stores', 'store', 'trading',
        'nig', 'nigeria', 'ltd', 'limited', 'co', 'company', 'kobo', 'and', 'sons', 'bros', 'global',
        'resources', 'services',
    ],

    // First entry of each group is the canonical spelling. Seed list: extend from real data.
    'name_variants' => [
        ['muhammad', 'mohammed', 'mohammad', 'muhammed', 'mohamed', 'mohd'],
        ['ibrahim', 'ibraheem', 'ibrahem'],
        ['yusuf', 'yusuph', 'yussuf', 'yusuff', 'yousuf'],
        ['abubakar', 'abubakr', 'aboubakar', 'abubakarr'],
        ['usman', 'uthman', 'osman', 'othman'],
        ['aisha', 'aishat', 'ayisha', 'aysha'],
        ['fatima', 'fatimah', 'fatimoh'],
    ],
];