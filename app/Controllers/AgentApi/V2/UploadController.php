<?php

declare(strict_types=1);

namespace App\Controllers\AgentApi\V2;

use App\Agent\{OperationException, TransportAuth};
use App\Application\Media\MediaService;
use App\Engine\{Request, Response};

final class UploadController
{
    public function receive(Request $request): Response
    {
        try {
            try { $budget=(new \App\Agent\RateBudget())->consume(['upload:ip:'.$request->ip()=>30]); }
            catch(\Throwable) { throw new OperationException('rate_limiter_unavailable',503,retryable:true); }
            $request->setAttribute('agent_budget',$budget);
            if(!$budget['allowed']) { throw new OperationException('rate_limited',429,retryable:true); }
            $file = $request->file('image');
            if ($file === null) { throw new OperationException('upload_invalid', 422); }
            $result = MediaService::receiveGrant($request->getAttribute('id'), $request->header('X-Upload-Token') ?? '', $file);
            return TransportAuth::headers(Response::json($result), $request);
        } catch (OperationException $error) {
            return TransportAuth::problem($error, $request);
        }
    }
}
