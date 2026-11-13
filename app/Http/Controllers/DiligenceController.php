<?php

namespace App\Http\Controllers;

use App\Diligence\Actions\CreateDiligence;
use App\Diligence\Actions\RetryDiligenceItem;
use App\Diligence\Models\Diligence;
use App\Diligence\Models\DiligenceItem;
use App\Http\Requests\StoreDiligenceRequest;
use App\Http\Resources\DiligenceResource;
use App\Rules\EvaluateFindings;

class DiligenceController extends Controller
{
    public function store(StoreDiligenceRequest $request, CreateDiligence $create): DiligenceResource
    {
        $diligence = $create->handle($request->validated('document'));

        return new DiligenceResource($diligence->load('items.findings'));
    }

    public function show(Diligence $diligence): DiligenceResource
    {
        return new DiligenceResource($diligence->load(['items.findings', 'latestAssessment']));
    }

    public function retryItem(Diligence $diligence, DiligenceItem $item, RetryDiligenceItem $retry): DiligenceResource
    {
        $retry->handle($item);

        return new DiligenceResource($diligence->refresh()->load(['items.findings', 'latestAssessment']));
    }

    public function evaluate(Diligence $diligence, EvaluateFindings $evaluate): DiligenceResource
    {
        $evaluate->handle($diligence);

        return new DiligenceResource($diligence->load(['items.findings', 'latestAssessment']));
    }
}
