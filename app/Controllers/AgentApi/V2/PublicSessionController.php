<?php

declare(strict_types=1);
namespace App\Controllers\AgentApi\V2;
use App\Agent\{OperationException,PublicTransport,TransportAuth};
use App\Engine\{Request,Response};

final class PublicSessionController
{
    public function start(Request $request): Response
    {
        try {
            return TransportAuth::headers(Response::json(PublicTransport::start($request)), $request);
        } catch(OperationException $error) {
            return TransportAuth::problem($error,$request);
        }
    }
}
