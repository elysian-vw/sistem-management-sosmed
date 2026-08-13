<?php

namespace App\Controllers;

use App\Services\AiService;

/**
 * TrendAi Controller
 *
 * Dashboard khusus Creative Team untuk eksplorasi Bank Tren Medsos,
 * Kalender Event & Promo Musiman, serta Instant AI Viral Hook Generator.
 */
class TrendAi extends BaseController
{
    protected AiService $aiService;

    public function __construct()
    {
        $this->aiService = new AiService();
    }

    public function index()
    {
        $db = \Config\Database::connect();
        $role = session('kode_role');

        if (!in_array($role, ['creative_team', 'superadmin', 'owner'], true)) {
            return redirect()->to('/dashboard/content-plan');
        }

        $bisnisId = (int) session('bisnis_aktif_id');

        // Master trend audio & visual Reels/TikTok (Dinamis dari database trend_bank, filter by bisnis)
        $audioTrends = $db->table('trend_bank')
            ->where('bisnis_id', $bisnisId)
            ->where('status', 'aktif')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        // Kalender event & momen promo musiman
        $eventCalendar = [
            ['tanggal' => '17 Agustus 2026', 'momen' => 'Hari Kemerdekaan RI (Promo 17-an)', 'tag' => 'Nasional'],
            ['tanggal' => '04 September 2026', 'momen' => 'Hari Pelanggan Nasional', 'tag' => 'Branding'],
            ['tanggal' => '09 September 2026', 'momen' => 'Harbolnas 9.9 Mega Sale', 'tag' => 'Promo Big Sale'],
            ['tanggal' => '25 September 2026', 'momen' => 'Payday Promo Gajian Akhir Bulan', 'tag' => 'Rutinan'],
            ['tanggal' => '10 Oktober 2026', 'momen' => 'Promo 10.10 Festival Belanja', 'tag' => 'Promo Big Sale']
        ];

        // Master data untuk modal ajukan ide (filter by bisnis + global fallback)
        $platforms = $db->table('platforms')
            ->where('status', 'aktif')
            ->groupStart()
            ->where('bisnis_id', $bisnisId)
            ->orWhere('bisnis_id IS NULL')
            ->groupEnd()
            ->get()->getResultArray();

        $jenisKonten = $db->table('jenis_konten')
            ->groupStart()
            ->where('bisnis_id', $bisnisId)
            ->orWhere('bisnis_id IS NULL')
            ->groupEnd()
            ->get()->getResultArray();

        $contentTypes = $db->table('content_types')
            ->groupStart()
            ->where('bisnis_id', $bisnisId)
            ->orWhere('bisnis_id IS NULL')
            ->groupEnd()
            ->get()->getResultArray();

        return view('trend_ai/index', [
            'judul' => 'Bank Trend & Inspirasi AI',
            'audioTrends' => $audioTrends,
            'eventCalendar' => $eventCalendar,
            'platforms' => $platforms,
            'jenisKonten' => $jenisKonten,
            'contentTypes' => $contentTypes,
            'kode_role' => $role,
        ]);
    }

    /**
     * POST /dashboard/trend-ai/generate-hook
     */
    public function generateHook(): \CodeIgniter\HTTP\ResponseInterface
    {
        $topik = $this->request->getPost('topik');
        $platform = $this->request->getPost('platform') ?: 'Instagram';

        if (!$topik) {
            return $this->response->setJSON([
                'sukses' => false,
                'pesan' => 'Topik wajib diisi.',
            ])->setStatusCode(400);
        }

        $userId = (int) session('user_id');

        try {
            $hasilAi = $this->aiService->generateHooks($topik, $platform, $userId);
            return $this->response->setJSON([
                'sukses' => true,
                'data'   => $hasilAi,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'sukses' => false,
                'pesan' => 'Gagal generate hook AI: ' . $e->getMessage(),
            ])->setStatusCode(500);
        }
    }
}
