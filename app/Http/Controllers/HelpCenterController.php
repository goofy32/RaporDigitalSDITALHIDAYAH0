<?php

namespace App\Http\Controllers;

use App\Services\HelpFaqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpCenterController extends Controller
{
    public function adminIndex(HelpFaqService $faq): View
    {
        return $this->indexResponse($faq, 'admin', 'Admin', 'layouts.app');
    }

    public function pengajarIndex(HelpFaqService $faq): View
    {
        return $this->indexResponse($faq, 'pengajar', 'Pengajar', 'layouts.pengajar.app');
    }

    public function waliKelasIndex(HelpFaqService $faq): View
    {
        return $this->indexResponse($faq, 'wali_kelas', 'Wali Kelas', 'layouts.wali_kelas.app');
    }

    public function adminFaq(Request $request, HelpFaqService $faq): JsonResponse
    {
        return $this->faqResponse($request, $faq, 'admin');
    }

    public function pengajarFaq(Request $request, HelpFaqService $faq): JsonResponse
    {
        return $this->faqResponse($request, $faq, 'pengajar');
    }

    public function waliKelasFaq(Request $request, HelpFaqService $faq): JsonResponse
    {
        return $this->faqResponse($request, $faq, 'wali_kelas');
    }

    private function faqResponse(Request $request, HelpFaqService $faq, string $role): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $question = trim((string) $request->query('question', ''));

        return response()->json($faq->responseFor(
            $role,
            $query !== '' ? $query : null,
            $question !== '' ? $question : null,
            $request->boolean('all'),
        ));
    }

    private function indexResponse(HelpFaqService $faq, string $role, string $roleLabel, string $layout): View
    {
        $payload = $faq->responseFor($role, all: true);

        return view('help.center', [
            'layout' => $layout,
            'role' => $role,
            'roleLabel' => $roleLabel,
            'categories' => $payload['categories'] ?? [],
            'topics' => $payload['results'] ?? [],
        ]);
    }
}
