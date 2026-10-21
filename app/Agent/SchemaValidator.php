<?php

declare(strict_types=1);
namespace App\Agent;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Errors\ErrorFormatter;

final class SchemaValidator
{
    public function validate(array $schema, mixed $value, bool $output = false): void
    {
        $json = json_decode(json_encode($value, JSON_THROW_ON_ERROR));
        if (($schema['type'] ?? '') === 'object' && $value === []) { $json = new \stdClass(); }
        $result = (new Validator())->validate($json, json_decode(json_encode($schema, JSON_THROW_ON_ERROR)));
        if (!$result->isValid()) {
            $errors = (new ErrorFormatter())->format($result->error());
            throw new OperationException($output ? 'response_contract_failed' : 'validation_failed', $output ? 500 : 422, $output ? [] : $errors);
        }
    }
}
