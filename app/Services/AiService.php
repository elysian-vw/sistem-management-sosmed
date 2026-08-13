<?php

namespace App\Services;

use App\Models\AiGenerationLogModel;
use App\Models\ContentStatusLogModel;

class AiService
{
    protected $client;
    protected $apiKey;
    protected $logModel;

    public function __construct()
    {
        $this->client = \Config\Services::curlrequest([
            'baseURI' => 'https://generativelanguage.googleapis.com',
            'timeout' => 30,
        ]);

        // Kita ambil dari getenv, jika kosong berarti fitur AI dimatikan/belum dikonfigurasi
        $this->apiKey = getenv('GEMINI_API_KEY');
        $this->logModel = new AiGenerationLogModel();
    }

    /**
     * Panggil Gemini API (Gemini 1.5 Flash)
     */
    protected function callGemini(string $prompt): string
    {
        if (empty($this->apiKey)) {
            return "Fitur AI belum dikonfigurasi. Mohon isi GEMINI_API_KEY di file .env.";
        }

        $url = '/v1beta/models/gemini-flash-latest:generateContent?key=' . $this->apiKey;

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048,
            ]
        ];

        try {
            $response = $this->client->post($url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
                'http_errors' => false // agar bisa nangkep pesan error API
            ]);

            $body = json_decode($response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                log_message('error', 'Gemini API Error: ' . $response->getBody());
                return "Gagal memanggil API AI. Periksa konfigurasi API Key.";
            }

            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                return $body['candidates'][0]['content']['parts'][0]['text'];
            }

            return "AI tidak mengembalikan respon yang valid.";
        } catch (\Exception $e) {
            log_message('error', 'CURL Exception: ' . $e->getMessage());
            return "Terjadi kesalahan koneksi ke server AI.";
        }
    }

    /**
     * Log penggunaan AI
     */
    protected function logUsage(?int $contentId, ?int $userId, string $fitur, string $prompt, string $output)
    {
        $this->logModel->insert([
            'content_id' => ($contentId && $contentId > 0) ? $contentId : null,
            'user_id' => ($userId && $userId > 0) ? $userId : null,
            'fitur' => $fitur,
            'prompt_input' => $prompt,
            'output' => $output,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // FITUR 9.2: CAPTION ASSISTANT
    // =========================================================================
    public function generateCaption(int $contentId, string $judul, string $platform, string $brief, int $userId): string
    {
        $prompt = "Kamu adalah asisten copywriter media sosial profesional. Tolong buatkan 1 draft caption yang menarik (termasuk hashtag yang relevan) berdasarkan informasi berikut:\n\n";
        $prompt .= "- Judul/Topik: " . $judul . "\n";
        $prompt .= "- Platform Target: " . $platform . "\n";
        $prompt .= "- Catatan/Brief: " . $brief . "\n\n";
        $prompt .= "Aturan:\n";
        $prompt .= "1. Sesuaikan gaya bahasa (tone) dengan karakteristik platform ($platform).\n";
        $prompt .= "2. Jangan buat kalimat pembuka seperti 'Tentu, ini dia'. Langsung berikan captionnya.\n";

        $output = $this->callGemini($prompt);

        $this->logUsage($contentId, $userId, 'caption_gen', $prompt, $output);

        return trim($output);
    }

    // =========================================================================
    // FITUR 9.3: PRE-REVIEW CHECKLIST
    // =========================================================================
    /**
     * Dijalankan secara asinkron (via cron) atau sinkron (sebelum transisi selesai).
     * Mengevaluasi konten saat masuk ke status 'review_design'.
     */
    public function preReviewCheck(array $konten): void
    {
        // Fitur AI belum jalan kalau API Key kosong
        if (empty($this->apiKey)) {
            return;
        }

        $judul = $konten['judul_konten'];
        $brief = $konten['deskripsi'] ?? 'Tidak ada brief';
        $caption = $konten['caption'] ?? 'Tidak ada caption';

        $prompt = "Kamu adalah Manager Media Sosial yang sedang mereview draft konten sebelum disetujui untuk dipublish. Tolong berikan evaluasi singkat (maksimal 3 poin) terhadap draft konten ini:\n\n";
        $prompt .= "Judul Konten: " . $judul . "\n";
        $prompt .= "Brief Ide Awal: " . $brief . "\n";
        $prompt .= "Draft Caption: " . $caption . "\n\n";
        $prompt .= "Fokuskan evaluasi pada:\n1. Kesesuaian caption dengan brief\n2. Ejaan/Tata Bahasa (Typo)\n3. Ajakan bertindak (Call to Action) yang jelas\n\nTulis evaluasimu dalam poin-poin yang padat (jangan terlalu panjang) langsung pada intinya.";

        $output = $this->callGemini($prompt);

        // Jika output bukan pesan error
        if (strpos($output, 'Fitur AI belum') === false && strpos($output, 'Gagal memanggil API') === false && strpos($output, 'kesalahan koneksi') === false) {

            // Catat di ai_generation_log
            $this->logUsage($konten['id'], 0, 'prereview', $prompt, $output); // 0 = Sistem

            // Catat di content_status_log sebagai catatan sistem
            $statusLog = new ContentStatusLogModel();
            $statusLog->insert([
                'content_id' => $konten['id'],
                'status_lama' => 'in_design', // karena AI ini nge-trigger pas masuk review_design
                'status_baru' => 'review_design',
                'user_id' => null, // Sistem
                'catatan' => "[AI Pre-Review Checklist]\n" . $output,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // =========================================================================
<<<<<<< HEAD
    // FITUR: AI HOOK GENERATOR
    // =========================================================================
    public function generateHooks(string $topik, string $platform, int $userId = 0): string
    {
        $prompt = "Kamu adalah Strategis Konten Media Sosial berpengalaman. Tolong buatkan 5 contoh kalimat pembuka (Viral Hook 3 detik pertama) yang sangat menarik dan terbukti efektif untuk konten {$platform} berdasarkan topik/produk: \"{$topik}\".\n\n";
        $prompt .= "Untuk setiap hook, berikan:\n";
        $prompt .= "1. Kalimat Hook (Viral Opening)\n";
        $prompt .= "2. Tipe Hook (misal: Curiosity, Fear of Missing Out, Problem-Solving, Bold Statement)\n";
        $prompt .= "3. Alasan singkat kenapa hook ini efektif\n\n";
        $prompt .= "Format output dengan rapi, singkat, dan siap pakai.";
=======
    // FITUR: AI VIRAL HOOK GENERATOR
    // =========================================================================
    public function generateHook(string $topik, string $platform, ?int $userId = null): string
    {
        $prompt = "Buatkan 3 contoh kalimat pembuka (Viral Hook) yang sangat menarik untuk konten {$platform} dengan topik: \"{$topik}\". Berikan format 1, 2, 3 yang singkat dan siap pakai.";
>>>>>>> 3c3b1c5586d126f57b65bde708a716f5601e4143

        $output = $this->callGemini($prompt);

        $this->logUsage(null, $userId, 'hook_gen', $prompt, $output);

        return trim($output);
    }

    // =========================================================================
    // FITUR 9.1: AI IDEA GENERATOR
    // =========================================================================
<<<<<<< HEAD
    public function generateIdeas(string $topik, string $platform, int $userId = 0): string
=======
    public function generateIdeas(string $topik, string $platform, ?int $userId = null): string
>>>>>>> 3c3b1c5586d126f57b65bde708a716f5601e4143
    {
        $prompt = "Kamu adalah Strategis Konten Media Sosial berpengalaman. Tolong berikan 3 ide konten kreatif untuk platform {$platform} berdasarkan topik/produk: \"{$topik}\".\n\n";
        $prompt .= "Untuk setiap ide, berikan:\n";
        $prompt .= "1. Judul Konten yang Menarik\n";
        $prompt .= "2. Konsep/Visual Ringkas\n";
        $prompt .= "3. Call to Action (CTA)\n\n";
        $prompt .= "Format output dengan rapi, singkat, dan mudah dipahami.";

        $output = $this->callGemini($prompt);

        $this->logUsage(null, $userId, 'idea_gen', $prompt, $output);

        return trim($output);
    }

    // =========================================================================
    // FITUR: AI BRIEF GENERATOR
    // =========================================================================
    public function generateBrief(string $judul, string $jenis = '', string $pillar = '', int $userId = 0): string
    {
        $prompt = "Kamu adalah Strategis Konten & Creative Director Media Sosial. Tolong buatkan deskripsi / brief ide singkat dan jelas untuk sebuah ide konten dengan detail berikut:\n\n";
        $prompt .= "- Judul / Topik Konten: \"{$judul}\"\n";
        if (! empty($jenis))  { $prompt .= "- Format / Jenis Konten: {$jenis}\n"; }
        if (! empty($pillar)) { $prompt .= "- Content Pillar: {$pillar}\n"; }
        $prompt .= "\nTuliskan brief ide yang praktis untuk Designer & Copywriter yang mencakup:\n";
        $prompt .= "1. Konsep Visual & Angle Konten\n";
        $prompt .= "2. Poin Utama Pesan / Poin Diskusi\n";
        $prompt .= "3. Output / Call to Action (CTA) singkat\n\n";
        $prompt .= "PENTING ATURAN FORMATTING:\n";
        $prompt .= "- JANGAN gunakan simbol markdown sama sekali seperti bintang-bintang (** atau *), pagar (###), atau garis pembatas (---).\n";
        $prompt .= "- Gunakan format teks polos (plain text) yang rapi, berpenomoran (1, 2, 3), serta simbol bullet sederhana (- atau •) agar bersih saat dibaca di dalam kolom input teks (textarea).\n";
        $prompt .= "- Tulis langsung ke inti brief tanpa kata pembuka informal.";

        $output = $this->callGemini($prompt);

        // Pembersihan otomatis agar tidak ada karakter markdown tersisa di textarea
        $cleanOutput = preg_replace('/[\*#_]{1,3}/', '', $output);
        $cleanOutput = preg_replace('/^\s*[-─_]{3,}\s*$/m', '', $cleanOutput);
        $cleanOutput = trim(preg_replace("/\n{3,}/", "\n\n", $cleanOutput));

        $this->logUsage(null, $userId, 'brief_gen', $prompt, $cleanOutput);

        return $cleanOutput;
    }
}

