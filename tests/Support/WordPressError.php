<?php

declare(strict_types=1);

/**
 * Stand-in for the WordPress WP_Error class, loaded by the test bootstrap for every test.
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
