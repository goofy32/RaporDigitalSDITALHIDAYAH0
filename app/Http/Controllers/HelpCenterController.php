<?php

namespace App\Http\Controllers;

use App\Services\HelpFaqService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpCenterController extends Controller
{
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
        ));
    }
}
