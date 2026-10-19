<?php

namespace App\Services\AI;

use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use Throwable;

/**
 * Retries a Gemini/OpenAI call on transient server-side failures (5xx, 429,
 * connection drops) with a short exponential backoff — NOT on a 4xx
 * ErrorException below 429 (bad request, invalid tool schema), where
 * retrying just repeats the same failure. Added 2026-10-04 after this app's
 * free-tier Gemini endpoint returned 503 on every request for several
 * minutes straight, confirmed via a bare "say OK" call outside any of this
 * app's own logic — a real, recurring availability gap on the free tier,
 * not a one-off blip worth ignoring.
 *
 * Exception shapes confirmed against the installed openai-php/client
 * version (vendor/openai-php/client/src/Exceptions) rather than assumed:
 * ServerException is a dedicated, always-5xx type; ErrorException's
 * getCode() is NOT the HTTP status (Exception::__construct is never given
 * one) — getStatusCode() is.
 */
trait RetriesTransientOpenAiErrors
{
    private function withOpenAiRetry(callable $call, int $maxAttempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $call();
            } catch (Throwable $e) {
                if (! $this->isTransientOpenAiError($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                usleep((int) (300_000 * (2 ** ($attempt - 1)))); // 0.3s, 0.6s, 1.2s...
            }
        }
    }

    private function isTransientOpenAiError(Throwable $e): bool
    {
        if ($e instanceof ServerException || $e instanceof RateLimitException || $e instanceof TransporterException) {
            return true;
        }

        if ($e instanceof ErrorException) {
            return $e->getStatusCode() >= 500;
        }

        return false;
    }
}
