<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait RespondsWithLiveList
{
    /**
     * Return a normal Blade page for fallback requests and a small HTML
     * fragment for debounced live-list updates.
     *
     * @param  array<string, mixed>  $data
     */
    protected function liveListResponse(Request $request, string $view, string $partial, array $data)
    {
        if ($request->ajax() || $request->wantsJson() || $request->boolean('live')) {
            return response()->json([
                'html' => view($partial, $data)->render(),
            ]);
        }

        return view($view, $data);
    }
}
