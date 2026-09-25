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
 * WP_Error intentionally does NOT implement Throwable, matching the real WordPress class.
 * Code under test that does `throw new \WP_Error(...)` will therefore raise a genuine
 * TypeError here too, exactly as it would in production WordPress.
 */
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private array $errors = [];
        private array $errorData = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if (empty($code)) {
                return;
            }

            $this->errors[$code][] = $message;

            if ('' !== $data) {
                $this->errorData[$code] = $data;
            }
        }

        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_code()
        {
            $codes = $this->get_error_codes();

            return $codes[0] ?? '';
        }

        public function get_error_message($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }

            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '')
        {
            if (empty($code)) {
                $code = $this->get_error_code();
            }

            return $this->errorData[$code] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;
        private int $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
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
     * get_json_params() and get_header(), so this stand-in exposes those four directly
     * against the values it was constructed with, instead of reimplementing that merge.
     */
    class WP_REST_Request
    {
        private array $params;
        private ?array $jsonParams;
        private array $headers = [];

        public function __construct(array $params = [], array $headers = [], ?array $jsonParams = null)
        {
            $this->params = $params;
            $this->jsonParams = $jsonParams;

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
