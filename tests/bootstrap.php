<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// WordPress constants required by production code under test.
// Values match what WordPress itself defines.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

// Minimal WP_Error stub — enough for createMock() and instanceof checks.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private array $errors = [];

        public function __construct(string $code = '', string $message = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
            }
        }

        public function get_error_message(): string
        {
            foreach ($this->errors as $messages) {
                return implode(' ', $messages);
            }
            return '';
        }
    }
}
