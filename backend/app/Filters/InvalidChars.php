<?php

namespace App\Filters;

use CodeIgniter\Filters\InvalidChars as BaseInvalidChars;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;

/**
 * The framework's invalid-character check (valid UTF-8, no control characters), reading a JSON
 * body as JSON.
 *
 * The framework version reads every body with parse_str(), as if it were a form. A JSON body
 * that holds report HTML (an edited stock card or summary report) has hundreds of "&nbsp;",
 * each of which parse_str() counts as a separate form field: past PHP's max_input_vars (1000)
 * that is an error and the export failed with a 500. Decoding the JSON checks the same text
 * without that limit.
 */
class InvalidChars extends BaseInvalidChars
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $isJson = str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json');
        $body   = (string) ($request->getBody() ?? '');

        if ($isJson) {
            $decoded  = $body === '' ? [] : json_decode($body, true);
            // Not valid JSON: the raw text is still checked (the controller rejects it later)
            $rawInput = is_array($decoded) ? $this->asStrings($decoded) : $body;
        } else {
            $rawInput = $request->getRawInput();
        }

        $data = [
            'get'      => $request->getGet(),
            'post'     => $request->getPost(),
            'cookie'   => $request->getCookie(),
            'rawInput' => $rawInput,
        ];

        foreach ($data as $source => $values) {
            $this->source = $source;
            $this->checkEncoding($values);
            $this->checkControl($values);
        }

        return null;
    }

    /** JSON numbers, booleans and nulls as text, so the string checks can read every value */
    private function asStrings(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_array($value) ? $this->asStrings($value) : (is_scalar($value) ? (string) $value : '');
        }

        return $values;
    }
}
