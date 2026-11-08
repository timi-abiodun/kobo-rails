<?php

namespace App\NameMatching;

enum NameMatchMethod: string
{
    case StringHigh = 'string_high';     // string score >= high: matched
    case StringLow = 'string_low';       // string score <= low: mismatched
    case StringReview = 'string_review'; // middle band, no embedding score: blocked + needs_review

    // Used by the embedding step (batch 3)
    case EmbeddingLow = 'embedding_low';         // middle band, embedding clearly says different: mismatched
    case EmbeddingReview = 'embedding_review';   // middle band, embedding can't settle it: blocked + needs_review
}