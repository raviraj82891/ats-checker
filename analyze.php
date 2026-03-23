<?php
/**
 * analyze.php — ATS Resume Checker
 * Supports: file upload (PDF/DOCX/DOC/TXT) OR pasted resume text
 * API: OpenRouter free (openrouter/free auto-router)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';
require_once 'db.php';

// ── Helpers ───────────────────────────────────────────────────

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function extractFromFile(string $path, string $ext): string {

    if ($ext === 'txt') {
        return (string) file_get_contents($path);
    }

    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml) {
                $xml  = str_replace(['</w:r>','</w:p>','</w:tr>'], [' ',"\n","\n"], $xml);
                $text = trim(strip_tags($xml));
                if (strlen($text) > 50) return $text;
            }
        }
    }

    if ($ext === 'pdf') {
        // Method 1: pdftotext (Linux/Mac)
        $pt = trim((string) shell_exec('which pdftotext 2>/dev/null'));
        if ($pt) {
            $esc  = escapeshellarg($path);
            $text = trim((string) shell_exec("pdftotext -enc UTF-8 -nopgbrk $esc - 2>/dev/null"));
            if (strlen($text) > 60) return $text;
        }

        // Method 2: PDF text operators (Tj / TJ)
        $raw  = (string) file_get_contents($path);
        $text = '';
        preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $raw, $m1);
        foreach ($m1[1] as $s) $text .= stripslashes($s) . ' ';
        preg_match_all('/\[([^\]]+)\]\s*TJ/s', $raw, $m2);
        foreach ($m2[1] as $blk) {
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $blk, $inner);
            foreach ($inner[1] as $s) $text .= stripslashes($s) . ' ';
        }
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (strlen($text) > 60) return $text;

        // Method 3: long ASCII runs
        preg_match_all('/[A-Za-z][A-Za-z0-9 @.,;:\-+#\/()\'\"]{15,}/', $raw, $m3);
        $text = trim(implode("\n", $m3[0]));
        if (strlen($text) > 60) return $text;

        return ''; // signal failure
    }

    if ($ext === 'doc') {
        $raw = (string) file_get_contents($path);
        preg_match_all('/[A-Za-z][A-Za-z0-9 @.,;:\-+#\/()]{12,}/', $raw, $m);
        return implode("\n", $m[0]);
    }

    return '';
}

function callModel(string $model, string $prompt, int $maxTokens): array {
    $payload = json_encode([
        'model'       => $model,
        'temperature' => 0.1,
        'max_tokens'  => $maxTokens,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ch = curl_init(OR_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OR_API_KEY,
            'HTTP-Referer: http://localhost/ats-checker',
            'X-Title: ATS Resume Checker',
        ],
    ]);

    $body     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) return ['error' => 'curl: ' . $curlErr];
    if (!$body)   return ['error' => 'empty body'];

    $data = json_decode($body, true);
    if ($httpCode !== 200) {
        return ['error' => $data['error']['message'] ?? 'HTTP ' . $httpCode];
    }

    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if (!$content) return ['error' => 'empty content'];

    return ['ok' => $content];
}

function repairAndParseJSON(string $raw): ?array {
    // Strip markdown fences
    $raw = preg_replace('/^```json\s*/i', '', trim($raw));
    $raw = preg_replace('/^```\s*/i',     '', $raw);
    $raw = preg_replace('/\s*```$/i',     '', trim($raw));

    // Grab from first { to last }
    $s = strpos($raw, '{');
    if ($s === false) return null;
    $raw = substr($raw, $s);

    // Try direct parse first
    $data = json_decode($raw, true);
    if (is_array($data) && isset($data['score'])) return $data;

    // Repair truncated JSON
    $raw = rtrim($raw);

    $openB = $openBr = 0;
    $inStr = $esc   = false;
    for ($i = 0; $i < strlen($raw); $i++) {
        $c = $raw[$i];
        if ($esc)  { $esc = false; continue; }
        if ($c === '\\' && $inStr) { $esc = true; continue; }
        if ($c === '"') { $inStr = !$inStr; continue; }
        if ($inStr) continue;
        if      ($c === '{') $openB++;
        elseif  ($c === '}') $openB--;
        elseif  ($c === '[') $openBr++;
        elseif  ($c === ']') $openBr--;
    }

    if ($inStr)  $raw .= '"';
    $raw = rtrim($raw, ',');
    $raw .= str_repeat(']', max(0, $openBr));
    $raw .= str_repeat('}', max(0, $openB));

    $data = json_decode($raw, true);
    return (is_array($data) && isset($data['score'])) ? $data : null;
}

// ── Validate request ──────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed.', 405);

$jobDesc    = trim($_POST['job_desc']     ?? '');
$pasteText  = trim($_POST['resume_text']  ?? '');
$resumeText = '';
$fileName   = 'pasted-resume.txt';

// ── Get resume text ───────────────────────────────────────────

if ($pasteText) {
    // Mode 1: pasted text — most reliable, no extraction needed
    $resumeText = mb_substr($pasteText, 0, 4000);
    $fileName   = 'pasted-resume.txt';

} elseif (!empty($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
    // Mode 2: file upload
    $file = $_FILES['resume'];
    $fileName = $file['name'];

    if ($file['size'] > 5 * 1024 * 1024) jsonError('File too large. Max 5MB.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','doc','docx','txt']))
        jsonError('Unsupported type. Use PDF, DOCX, DOC, or TXT.');

    $tmp = sys_get_temp_dir() . '/' . uniqid('ats_') . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $tmp)) jsonError('Server error saving file.');

    $resumeText = extractFromFile($tmp, $ext);
    @unlink($tmp);
    $resumeText = mb_substr(trim($resumeText), 0, 4000);

    if (strlen($resumeText) < 40) {
        jsonError(
            'Could not extract text from this PDF on your server. ' .
            'Please use the "📋 Paste Resume Text" tab instead — ' .
            'open your PDF, Select All (Ctrl+A), Copy (Ctrl+C), then paste it there.'
        );
    }
} else {
    jsonError('Please upload a resume file or paste your resume text.');
}

// Final check
if (strlen($resumeText) < 40) {
    jsonError('Resume text is too short. Please provide more content.');
}

// ── Build prompt ──────────────────────────────────────────────

$jd = $jobDesc ? "\n\nJOB DESCRIPTION:\n" . mb_substr($jobDesc, 0, 1000) : '';

$prompt = 'You are an ATS expert. Analyze the resume below. Output ONLY a JSON object — no markdown, no backticks, nothing before or after the JSON.

RESUME:
' . $resumeText . $jd . '

Output this JSON with real analysis values:
{"score":72,"sections_found":4,"keywords_found":["Python","Git","OOP"],"keywords_missing":["Docker","AWS","CI/CD"],"section_scores":{"Contact Info":80,"Work Experience":70,"Education":90,"Skills":65,"Summary/Objective":40,"Formatting":75},"improvements":["Add a professional summary at the top","Quantify achievements with numbers","Add missing keywords from JD","Include LinkedIn and GitHub links","Use consistent bullet points","Add measurable impact to project descriptions"]}

Rules:
- score: 0-100 integer (ATS compatibility)  
- keywords_found: up to 10 actual skills/tools found in the resume
- keywords_missing: up to 10 skills from job description NOT in resume ([] if no JD)
- improvements: exactly 6 short specific actionable strings based on this resume
- Output ONLY the JSON object, nothing else';

// ── Call OpenRouter with fallback ─────────────────────────────

$models = [
    ['model' => 'openrouter/free',                         'tokens' => 2000],
    ['model' => 'meta-llama/llama-3.3-70b-instruct:free', 'tokens' => 2000],
    ['model' => 'deepseek/deepseek-r1:free',              'tokens' => 2000],
];

$analysis  = null;
$errors    = [];

foreach ($models as $entry) {
    $result = callModel($entry['model'], $prompt, $entry['tokens']);

    if (isset($result['error'])) {
        $errors[] = '[' . $entry['model'] . '] ' . $result['error'];
        continue;
    }

    $analysis = repairAndParseJSON($result['ok']);
    if ($analysis) break;

    $errors[] = '[' . $entry['model'] . '] bad JSON: ' . substr($result['ok'], 0, 80);
}

if (!$analysis) {
    jsonError('Analysis failed: ' . implode(' | ', $errors), 500);
}

// ── Sanitize ──────────────────────────────────────────────────

$analysis['score']            = max(0, min(100, intval($analysis['score'])));
$analysis['sections_found']   = intval($analysis['sections_found'] ?? 0);
$analysis['keywords_found']   = array_values(array_filter((array)($analysis['keywords_found']   ?? [])));
$analysis['keywords_missing'] = array_values(array_filter((array)($analysis['keywords_missing'] ?? [])));
$analysis['improvements']     = array_values(array_filter((array)($analysis['improvements']     ?? [])));

$defaults = ['Contact Info'=>0,'Work Experience'=>0,'Education'=>0,'Skills'=>0,'Summary/Objective'=>0,'Formatting'=>0];
$analysis['section_scores'] = array_merge($defaults, (array)($analysis['section_scores'] ?? []));

// ── Save to DB ────────────────────────────────────────────────

try {
    getDB()->prepare("
        INSERT INTO resume_checks
            (filename,score,keywords_found,keywords_missing,sections_found,section_scores,improvements,job_desc_provided)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $fileName,
        $analysis['score'],
        count($analysis['keywords_found']),
        count($analysis['keywords_missing']),
        $analysis['sections_found'],
        json_encode($analysis['section_scores']),
        json_encode($analysis['improvements']),
        $jobDesc ? 1 : 0,
    ]);
} catch (Exception $e) { error_log('DB: ' . $e->getMessage()); }

// ── Return ────────────────────────────────────────────────────

echo json_encode(array_merge(['success' => true], $analysis));