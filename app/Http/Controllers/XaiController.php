<?php

namespace App\Http\Controllers;

use App\Support\XaiFeatureLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class XaiController extends Controller
{
    public function modelInsights(): JsonResponse
    {
        $data = Cache::remember('xai_model_insights', 60, function () {
            $resp = Http::timeout(10)->get('http://127.0.0.1:5002/model_insights');
            if ($resp->failed()) {
                return null;
            }
            $raw = $resp->json();
            foreach (['ic', 'env', 'func'] as $domain) {
                foreach ($raw[$domain] ?? [] as &$item) {
                    $item['label'] = XaiFeatureLabels::label($item['feature']);
                }
                unset($item);
            }

            return $raw;
        });

        if ($data === null) {
            return response()->json(['error' => 'Model insights unavailable'], 503);
        }

        return response()->json($data);
    }
}
