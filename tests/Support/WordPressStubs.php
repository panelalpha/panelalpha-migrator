<?php

declare(strict_types=1);

/**
 * Minimal, faithful stand-ins for the WordPress classes/functions the API layer calls
 * (WP_REST_Request, WP_Error, WP_REST_Response, rest_ensure_response()). Declared in the
 * global namespace, exactly like the real WordPress symbols, so they must never be
 * autoloaded or loaded in-process: require this file only from inside a test method of a
 * #[RunTestsInSeparateProcesses] class, never from a normal in-process test, otherwise
 * these names would leak into every later test in the same PHP process.
 *
 * WP_Error lives in WordPressError.php, which the test bootstrap loads for every test.
 */
require_once __DIR__ . '/WordPressError.php';

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;
        private int $status;
        private array $headers = [];

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response)
    {
        if ($response instanceof WP_Error || $response instanceof WP_REST_Response) {
            return $response;
        }

        return new WP_REST_Response($response);
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Simplified stand-in: a real WP_REST_Request merges URL/query/body/JSON params with
     * precedence rules. The controllers under test only ever call get_param(), get_params(),
     * get_json_params(), get_header() and get_body(), so this stand-in exposes those directly
     * against the values it was constructed with (and get_body() for the raw body), instead of reimplementing that merge.
     */
    class WP_REST_Request
    {
        private array $params;
        private ?array $jsonParams;
        private array $headers = [];
        private string $body;

        public function __construct(array $params = [], array $headers = [], ?array $jsonParams = null, string $body = '')
        {
            $this->params = $params;
            $this->jsonParams = $jsonParams;
            $this->body = $body;

            foreach ($headers as $key => $value) {
                $this->headers[$this->canonicalizeHeaderName($key)] = $value;
            }
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params(): array
        {
            return $this->params;
        }

        public function get_json_params(): ?array
        {
            return $this->jsonParams;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_header($key)
        {
            $key = $this->canonicalizeHeaderName($key);

            return $this->headers[$key] ?? null;
        }

        private function canonicalizeHeaderName(string $key): string
        {
            return str_replace('-', '_', strtolower($key));
        }
    }
}
