<?php
/**
 * config.php
 * FREE OpenRouter API key: https://openrouter.ai/keys
 * No credit card needed. 200 free requests/day.
 */

// ── OpenRouter API ────────────────────────────────────────────
define('OR_API_KEY', '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'); // 👈 sk-or-v1-...
define('OR_API_URL', 'https://openrouter.ai/api/v1/chat/completions');

// Verified free models (March 2026) — tried in order until one works
define('OR_MODELS', [
    'openrouter/free',                                    // auto-pick any free model
    'meta-llama/llama-3.3-70b-instruct:free',            // Llama 3.3 70B
    'deepseek/deepseek-r1-0528-qwen3-8b:free',           // DeepSeek R1
    'nvidia/llama-3.3-nemotron-super-49b-v1:free',       // NVIDIA Nemotron
    'google/gemini-2.5-flash-image-preview:free',        // Gemini 2.5 Flash
    'deepseek/deepseek-r1:free',                         // DeepSeek R1 full
    'meta-llama/llama-4-scout:free',                     // Llama 4 Scout
]);

// ── MySQL ─────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'ats_checker');
define('DB_USER',    'root');
define('DB_PASS',    'ravi82891');           // 👈 your MySQL password e.g. '!Ravi82891'
define('DB_CHARSET', 'utf8mb4');