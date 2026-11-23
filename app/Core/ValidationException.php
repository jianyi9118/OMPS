<?php
namespace Omps\Core;

/**
 * Field validation errors: ['field' => 'translated message'].
 */
class ValidationException extends \RuntimeException
{
    /** @var array */
    public $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct('validation failed');
    }

    public static function field($field, $messageKey, array $params = array())
    {
        return new self(array($field => __($messageKey, $params)));
    }
}
